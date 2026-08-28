<?php

namespace App\Listeners\Ai;

use App\Models\AiProviderAccount;
use App\Services\Ai\AiProviderConfig;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Events\ProviderFailedOver;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;

/**
 * The memory half of cooldown: an account that failed over is benched for a
 * window keyed to *why* it failed.
 */
class RecordAiProviderFailover
{
    private const CREDITS_COOLDOWN_MINUTES = 60; // a balance does not refill on its own

    private const RATE_LIMIT_COOLDOWN_MINUTES = 2; // a window that clears itself

    private const OVERLOADED_COOLDOWN_MINUTES = 2;

    private const DEFAULT_COOLDOWN_MINUTES = 2; // unclassifiable: short enough to cost nothing

    public function handle(ProviderFailedOver $event): void
    {
        $account = $this->accountFor($event->provider->name());

        if ($account === null) {
            // Not one of our connection names — the SDK's own .env-backed
            // providers are not our business.
            return;
        }

        $reason = class_basename($event->exception);

        $account->markUnavailable($reason, $this->cooldownMinutesFor($event->exception));

        Log::warning('AI provider account failed over', [
            'account_id' => $account->getKey(),
            'driver' => $account->driver,
            'model' => $event->model,
            'reason' => $reason,
        ]);
    }

    private function cooldownMinutesFor(object $exception): int
    {
        return match (true) {
            $exception instanceof InsufficientCreditsException => self::CREDITS_COOLDOWN_MINUTES,
            $exception instanceof RateLimitedException => self::RATE_LIMIT_COOLDOWN_MINUTES,
            $exception instanceof ProviderOverloadedException => self::OVERLOADED_COOLDOWN_MINUTES,
            default => self::DEFAULT_COOLDOWN_MINUTES,
        };
    }

    private function accountFor(?string $connectionName): ?AiProviderAccount
    {
        $accountId = AiProviderConfig::accountIdFromConnectionName($connectionName);

        return $accountId === null ? null : AiProviderAccount::query()->find($accountId);
    }
}
