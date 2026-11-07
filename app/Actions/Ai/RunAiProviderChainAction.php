<?php

namespace App\Actions\Ai;

use App\Enums\AiUsageOutcome;
use App\Exceptions\AiProviderNotConfiguredException;
use App\Exceptions\AiUsageLimitExceededException;
use App\Services\Ai\AiClientFactory;
use App\Services\Ai\AiOperationIdentity;
use App\Services\Ai\AiProviderChain;
use App\Services\Ai\AiTextResult;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\Ai;
use Laravel\Ai\Events\ProviderFailedOver;
use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\Providers\Provider;
use RuntimeException;

/**
 * The rotation loop every AI operation runs through.
 *
 * Retry guard first; `retryAttempt * 100 + index` keeps attempt numbers
 * unique across (queue retry × account) so idempotency keys never collide;
 * the failover event is dispatched HERE — the SDK only fires it during its
 * own walk, and without it nothing gets benched; an account cap moves to the
 * next sibling while a global cap aborts; and the account that actually
 * answered (resolved from the connection name, never assumed) is the one
 * credited. On total failure the last REAL exception re-throws — "no
 * provider configured" when the truth is "all three are out of credit" sends
 * the operator to the wrong screen.
 */
class RunAiProviderChainAction
{
    public function __construct(
        private readonly ExecuteAiProviderCallAction $providerCall,
        private readonly AiClientFactory $clients,
    ) {}

    /**
     * Drive the capability's chain until one account answers.
     *
     * @param  Closure(string $connection, ?string $model): AiTextResult  $prompt
     */
    public function __invoke(
        string $capabilityKey,
        AiOperationIdentity $identity,
        Closure $prompt,
        ?Model $subject = null,
        int $retryAttempt = 0,
    ): AiTextResult {
        if ($this->providerCall->hasReconciledReservation($identity)) {
            throw new RuntimeException('This AI run already settled; callers must check before re-running.');
        }

        $chain = AiProviderChain::forKey($capabilityKey);

        if ($chain === null) {
            throw AiProviderNotConfiguredException::forCapability($capabilityKey);
        }

        $lastProviderException = null;
        $lastLimitException = null;

        foreach ($chain->configs as $index => $config) {
            try {
                $call = $this->providerCall->execute(
                    $identity,
                    $retryAttempt * 100 + $index,
                    $config,
                    $subject,
                    fn (): AiTextResult => $prompt(
                        $this->clients->connectAccount($config),
                        $config->model,
                    ),
                );
            } catch (FailoverableException $exception) {
                $lastProviderException = $exception;

                // A single named provider was passed, so the SDK's own walk
                // had one entry and fired no event — benching is ours.
                $provider = Ai::textProvider($config->connectionName());

                if ($provider instanceof Provider) {
                    event(new ProviderFailedOver($provider, (string) $config->model, $exception));
                }

                continue; // next account
            } catch (AiUsageLimitExceededException $exception) {
                $lastLimitException = $exception;

                if (! $exception->blocksGlobalBudget()) {
                    continue; // account cap → a sibling may still have room
                }

                throw $exception; // global cap → no sibling can help
            }

            if ($call->response === null) {
                // Reserved-but-claimed-elsewhere: another worker is running
                // this exact attempt right now. Treat it as a settled replay.
                continue;
            }

            $answered = $chain->findByConnectionName($call->response->responseConnection());
            $answered?->account()?->markSucceeded(); // clears the cooldown

            $this->providerCall->reconcile($identity, $call, AiUsageOutcome::Completed, $subject);

            return $call->response;
        }

        throw $lastLimitException
            ?? $lastProviderException
            ?? new RuntimeException('No AI provider could answer the request.');
    }
}
