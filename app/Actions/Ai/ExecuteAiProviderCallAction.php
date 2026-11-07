<?php

namespace App\Actions\Ai;

use App\Enums\AiReservationStatus;
use App\Enums\AiUsageOutcome;
use App\Exceptions\AiUsageLimitExceededException;
use App\Models\AiUsageRecord;
use App\Models\AiUsageReservation;
use App\Services\Ai\AiOperationIdentity;
use App\Services\Ai\AiProviderCallResult;
use App\Services\Ai\AiProviderConfig;
use App\Services\Ai\AiQuotaService;
use App\Services\Ai\AiTextResult;
use App\Services\Ai\AiUsageRecorder;
use Closure;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Throwable;

/**
 * One provider attempt, start to finish — the wrapper that makes a caller
 * unable to accidentally skip the accounting.
 *
 * The lifecycle: reserve (budget gate) → claim (exactly-once) → the caller's
 * invoke closure, which MUST NOT run inside a transaction (a multi-second
 * HTTP call under a row lock is how every worker deadlocks) → reconcile.
 */
class ExecuteAiProviderCallAction
{
    public function __construct(
        private readonly AiQuotaService $quota,
        private readonly AiUsageRecorder $recorder,
    ) {}

    /**
     * @param  Closure(): AiTextResult  $invoke
     */
    public function execute(
        AiOperationIdentity $identity,
        int $attempt,
        AiProviderConfig $config,
        ?Model $subject,
        Closure $invoke,
    ): AiProviderCallResult {
        $account = $config->account();

        if ($account === null) {
            throw new RuntimeException('The AI provider account is no longer available.');
        }

        try {
            $reservation = $this->quota->reserve($identity, $attempt, $account, $subject);
        } catch (AiUsageLimitExceededException $exception) {
            // Record the rejection so a refused call is visible, not silent.
            $this->recorder->record($identity, $attempt, AiUsageOutcome::ProviderFailed,
                account: $account, subject: $subject, exception: $exception);

            throw $exception;
        }

        if ($reservation->status->isTerminal()) {
            return new AiProviderCallResult(null, $config, $reservation, $attempt, alreadyCompleted: true);
        }

        $claimed = $this->quota->claim($reservation);

        if ($claimed === null) {
            // Another worker already claimed it. Not an error — just not ours to run.
            return new AiProviderCallResult(null, $config, $reservation->refresh(), $attempt, alreadyCompleted: true);
        }

        try {
            $response = $invoke();
        } catch (Throwable $exception) {
            // The failure row is written BEFORE the release — releasing first
            // would let a crash between the two steps erase the evidence.
            $this->recorder->record($identity, $attempt, AiUsageOutcome::ProviderFailed,
                account: $account, subject: $subject, exception: $exception);
            $this->quota->fail($claimed);
            $this->quota->release($claimed);

            throw $exception;
        }

        $this->quota->complete($claimed);

        return new AiProviderCallResult($response, $config, $claimed, $attempt);
    }

    /**
     * Write the ledger row and swap the estimate for real usage.
     */
    public function reconcile(
        AiOperationIdentity $identity,
        AiProviderCallResult $call,
        AiUsageOutcome $outcome,
        ?Model $subject = null,
        ?Throwable $exception = null,
    ): ?AiUsageRecord {
        if ($call->alreadyCompleted) {
            return $call->reservation->usageRecord; // replay: return what was already written
        }

        $record = $this->recorder->record(
            $identity,
            $call->attempt,
            $outcome,
            $call->response?->response,
            $call->config->account(),
            $subject,
            $exception,
        );

        $this->quota->reconcile($call->reservation, $record);

        return $record;
    }

    /**
     * Retry guard: has this run already settled? Call BEFORE doing any work.
     */
    public function hasReconciledReservation(AiOperationIdentity $identity): bool
    {
        return AiUsageReservation::query()
            ->where('run_id', $identity->runId)
            ->where('operation', $identity->operation)
            ->where('status', AiReservationStatus::Reconciled->value)
            ->exists();
    }
}
