<?php

namespace App\Services\Ai;

use App\Enums\AiLimitPeriod;
use App\Enums\AiReservationStatus;
use App\Enums\AiUsageRecordStatus;
use App\Exceptions\AiUsageLimitExceededException;
use App\Models\AiGlobalUsageLimit;
use App\Models\AiProviderAccount;
use App\Models\AiUsageRecord;
use App\Models\AiUsageReservation;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The token lease: reserve (estimate, budget-checked) → claim (exactly once)
 * → invoke (caller, NO transaction) → reconcile (real tokens + release of the
 * difference). You cannot enforce a budget with numbers you only learn after
 * spending them, which is why the estimate gates the call.
 */
class AiQuotaService
{
    private const DEADLOCK_RETRIES = 3;

    /**
     * The operation's token estimate. Total is clamped to at least 1: a zero
     * reservation passes every budget check and makes the ledger lie about
     * live work.
     *
     * @return array{input_tokens: int, output_tokens: int, total_tokens: int}
     */
    public function estimate(string $operation): array
    {
        $estimate = (array) config('ai_usage.estimates.'.$operation, []);

        return [
            'input_tokens' => max(0, (int) ($estimate['input_tokens'] ?? 0)),
            'output_tokens' => max(0, (int) ($estimate['output_tokens'] ?? 0)),
            'total_tokens' => max(1, (int) ($estimate['total_tokens'] ?? 0)),
        ];
    }

    /**
     * @param  array{input_tokens: int, output_tokens: int, total_tokens: int}|null  $estimate
     */
    public function reserve(
        AiOperationIdentity $identity,
        int $attempt,
        AiProviderAccount $account,
        ?Model $subject = null,
        ?array $estimate = null,
    ): AiUsageReservation {
        $estimate ??= $this->estimate($identity->operation);
        $key = implode(':', [$identity->idempotencyKey(), 'attempt', $attempt, 'account', $account->getKey()]);

        return $this->transactionWithRetry(function () use ($identity, $account, $subject, $estimate, $key): AiUsageReservation {
            // 1. Already settled for this run+account? Hand it back; do not re-charge.
            $completed = AiUsageReservation::query()
                ->where('run_id', $identity->runId)
                ->where('operation', $identity->operation)
                ->where('ai_provider_account_id', $account->getKey())
                ->where('status', AiReservationStatus::Reconciled)
                ->lockForUpdate()
                ->first();

            if ($completed !== null) {
                return $completed;
            }

            // 2. Same key already reserved? Reuse it (this is the retry path).
            $existing = AiUsageReservation::query()->where('idempotency_key', $key)->lockForUpdate()->first();

            if ($existing !== null) {
                return $existing;
            }

            // 3. Lock BOTH scopes before reading consumption, or two workers
            //    both see room and both reserve — the lost-update race.
            //    Only app-wide (owner-less) windows gate the chain today.
            $global = AiGlobalUsageLimit::query()
                ->whereNull('owner_type')
                ->where('is_active', true)
                ->where('starts_at', '<=', now())
                ->where('ends_at', '>', now())
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $lockedAccount = AiProviderAccount::query()
                ->whereKey($account->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $accountLimit = $this->limitForAccount($lockedAccount);
            $accountWindow = $accountLimit->windowContaining(CarbonImmutable::now());

            $decision = $this->decision(
                globalLimits: $this->limitForGlobal($global),
                accountLimits: $accountLimit,
                globalConsumed: $global === null ? ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0] : $this->consumedBetween($global->starts_at, $global->ends_at, null),
                accountConsumed: $this->consumedBetween($accountWindow->startsAt, $accountWindow->endsAt, (int) $lockedAccount->getKey()),
                estimate: $estimate,
            );

            if (! $decision->allowed) {
                throw new AiUsageLimitExceededException($decision, (int) $lockedAccount->getKey());
            }

            return AiUsageReservation::query()->create([
                'ai_capability_id' => $lockedAccount->ai_capability_id,
                'ai_provider_account_id' => $lockedAccount->getKey(),
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'operation' => $identity->operation,
                'idempotency_key' => $key,
                'job_identity' => $identity->jobClass,
                'run_id' => $identity->runId,
                'reserved_input_tokens' => $estimate['input_tokens'],
                'reserved_output_tokens' => $estimate['output_tokens'],
                'reserved_total_tokens' => $estimate['total_tokens'],
                'status' => AiReservationStatus::Queued,
            ]);
        });
    }

    /**
     * Exactly one worker may move a reservation from queued to started. Null
     * (not a throw) when someone else already did: a concurrent claim is
     * normal in an at-least-once queue.
     */
    public function claim(AiUsageReservation $reservation): ?AiUsageReservation
    {
        return DB::transaction(function () use ($reservation): ?AiUsageReservation {
            $fresh = AiUsageReservation::query()
                ->whereKey($reservation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($fresh->status !== AiReservationStatus::Queued) {
                return null;
            }

            $fresh->update(['status' => AiReservationStatus::Started, 'started_at' => now()]);

            return $fresh->refresh();
        });
    }

    /**
     * Swap the estimate for the truth. When the provider said nothing usable,
     * consumed = reserved — "usage missing" must not become the cheapest
     * outcome, or it quietly blows the budget.
     */
    public function reconcile(AiUsageReservation $reservation, ?AiUsageRecord $record): AiUsageReservation
    {
        if ($reservation->status->isTerminal()) {
            return $reservation;
        }

        $known = $record !== null
            && in_array($record->usage_quality?->value, ['reported', 'estimated', 'contradictory'], true);

        $input = $known ? (int) $record->input_tokens : (int) $reservation->reserved_input_tokens;
        $output = $known ? (int) $record->output_tokens : (int) $reservation->reserved_output_tokens;
        $total = $known ? (int) $record->total_tokens : (int) $reservation->reserved_total_tokens;

        return $this->transition($reservation, AiReservationStatus::Reconciled, [
            'consumed_input_tokens' => $input,
            'consumed_output_tokens' => $output,
            'consumed_total_tokens' => $total,
            'released_input_tokens' => max(0, (int) $reservation->reserved_input_tokens - $input),
            'released_output_tokens' => max(0, (int) $reservation->reserved_output_tokens - $output),
            'released_total_tokens' => max(0, (int) $reservation->reserved_total_tokens - $total),
            'usage_known' => $known,
            'ai_usage_record_id' => $record?->getKey(),
            'reconciled_at' => now(),
            'terminal_at' => now(),
        ], required: AiReservationStatus::Completed);
    }

    public function complete(AiUsageReservation $reservation): AiUsageReservation
    {
        if ($reservation->status->isTerminal()) {
            return $reservation;
        }

        return $this->transition($reservation, AiReservationStatus::Completed, [
            'completed_at' => now(),
        ]);
    }

    public function fail(AiUsageReservation $reservation): AiUsageReservation
    {
        if ($reservation->status->isTerminal()) {
            return $reservation;
        }

        return $this->transition($reservation, AiReservationStatus::Failed, [
            'failed_at' => now(),
        ]);
    }

    public function release(AiUsageReservation $reservation): AiUsageReservation
    {
        if ($reservation->status->isTerminal()) {
            return $reservation;
        }

        return $this->transition($reservation, AiReservationStatus::Released, [
            'released_input_tokens' => max(0, (int) $reservation->reserved_input_tokens - (int) ($reservation->consumed_input_tokens ?? 0)),
            'released_output_tokens' => max(0, (int) $reservation->reserved_output_tokens - (int) ($reservation->consumed_output_tokens ?? 0)),
            'released_total_tokens' => max(0, (int) $reservation->reserved_total_tokens - (int) ($reservation->consumed_total_tokens ?? 0)),
            'released_at' => now(),
            'terminal_at' => now(),
        ], required: AiReservationStatus::Failed);
    }

    /**
     * The guarded state machine: re-read under lock every time, short-circuit
     * if already terminal. `required` passes through the intermediate state
     * (completed before reconciled, failed before released) so the audit
     * trail shows the real order instead of jumping straight to terminal.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function transition(
        AiUsageReservation $reservation,
        AiReservationStatus $status,
        array $attributes,
        ?AiReservationStatus $required = null,
    ): AiUsageReservation {
        return DB::transaction(function () use ($reservation, $status, $attributes, $required): AiUsageReservation {
            $fresh = AiUsageReservation::query()
                ->whereKey($reservation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($fresh->status->isTerminal()) {
                return $fresh;
            }

            if ($required !== null && $fresh->status !== $required) {
                $fresh->update(['status' => $required]);
            }

            $fresh->update(['status' => $status, ...$attributes]);

            return $fresh->refresh();
        });
    }

    /**
     * How many tokens count against a window, extracted once so every
     * "how much have we used" answer agrees with the gate that refuses work.
     *
     * - In-flight reservations count at their estimate (else ten concurrent
     *   jobs each see an empty budget and all pass).
     * - Released reservations count for nothing — the tokens went back.
     * - Reconciled reservations count at consumed, and their linked record is
     *   excluded: the same spend must not count from both sides.
     *
     * Half-open [start, end): a row landing exactly on a boundary must not be
     * counted in two adjacent periods.
     *
     * @return array{input_tokens: int, output_tokens: int, total_tokens: int}
     */
    public function consumedBetween(CarbonImmutable $startsAt, CarbonImmutable $endsAt, ?int $accountId = null): array
    {
        $records = AiUsageRecord::query()
            ->where('created_at', '>=', $startsAt->utc())
            ->where('created_at', '<', $endsAt->utc())
            ->whereIn('status', [AiUsageRecordStatus::Succeeded, AiUsageRecordStatus::NoUsage])
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('ai_usage_reservations as linked')
                ->whereColumn('linked.ai_usage_record_id', 'ai_usage_records.id')
                ->where('linked.status', AiReservationStatus::Reconciled->value));

        $reservations = AiUsageReservation::query()
            ->where('created_at', '>=', $startsAt->utc())
            ->where('created_at', '<', $endsAt->utc());

        if ($accountId !== null) {
            $records->where('ai_provider_account_id', $accountId);
            $reservations->where('ai_provider_account_id', $accountId);
        }

        $totals = [
            'input_tokens' => (int) $records->clone()->sum('input_tokens'),
            'output_tokens' => (int) $records->clone()->sum('output_tokens'),
            'total_tokens' => (int) $records->clone()->sum('total_tokens'),
        ];

        $reservations->get([
            'status',
            'reserved_input_tokens',
            'reserved_output_tokens',
            'reserved_total_tokens',
            'consumed_input_tokens',
            'consumed_output_tokens',
            'consumed_total_tokens',
        ])->each(function (AiUsageReservation $reservation) use (&$totals): void {
            if ($reservation->status === AiReservationStatus::Released) {
                return;
            }

            $prefix = $reservation->status === AiReservationStatus::Reconciled ? 'consumed_' : 'reserved_';

            $totals['input_tokens'] += (int) $reservation->{$prefix.'input_tokens'};
            $totals['output_tokens'] += (int) $reservation->{$prefix.'output_tokens'};
            $totals['total_tokens'] += (int) $reservation->{$prefix.'total_tokens'};
        });

        return $totals;
    }

    /**
     * min() across finite scopes; null = unlimited and must never be treated
     * as zero.
     *
     * @param  array{input_tokens: int, output_tokens: int, total_tokens: int}  $globalConsumed
     * @param  array{input_tokens: int, output_tokens: int, total_tokens: int}  $accountConsumed
     * @param  array{input_tokens: int, output_tokens: int, total_tokens: int}  $estimate
     */
    private function decision(AiLimit $globalLimits, AiLimit $accountLimits, array $globalConsumed, array $accountConsumed, array $estimate): AiLimitDecision
    {
        $limitsByScope = [
            'global' => $globalLimits,
            'account' => $accountLimits,
        ];
        $consumedByScope = [
            'global' => $globalConsumed,
            'account' => $accountConsumed,
        ];

        $remainingByScope = [];
        $allowed = true;
        $remaining = null;

        foreach (['input_tokens', 'output_tokens', 'total_tokens'] as $dimension) {
            $dimensionRemaining = null;

            foreach ($limitsByScope as $scope => $limit) {
                $scopeLimit = match ($dimension) {
                    'input_tokens' => $limit->inputTokens,
                    'output_tokens' => $limit->outputTokens,
                    default => $limit->totalTokens,
                };

                $scopeRemaining = $scopeLimit === null
                    ? null
                    : max(0, (int) $scopeLimit - $consumedByScope[$scope][$dimension]);

                $remainingByScope[$dimension.'.'.$scope] = $scopeRemaining;

                if ($scopeRemaining !== null
                    && ($dimensionRemaining === null || $scopeRemaining < $dimensionRemaining)) {
                    $dimensionRemaining = $scopeRemaining;
                }
            }

            $remaining = $remaining === null || ($dimensionRemaining !== null && $dimensionRemaining < $remaining)
                ? $dimensionRemaining
                : $remaining;

            if ($dimensionRemaining !== null && $estimate[$dimension] > $dimensionRemaining) {
                $allowed = false;
            }
        }

        return new AiLimitDecision($allowed, $remaining, $remainingByScope, $allowed ? null : 'ai_usage_limit_exceeded');
    }

    private function limitForAccount(AiProviderAccount $account): AiLimit
    {
        return new AiLimit(
            inputTokens: $account->input_token_limit !== null ? (int) $account->input_token_limit : null,
            outputTokens: $account->output_token_limit !== null ? (int) $account->output_token_limit : null,
            totalTokens: $account->total_token_limit !== null ? (int) $account->total_token_limit : null,
            // Null only on a row written before the cast existed.
            period: $account->limit_period ?? AiLimitPeriod::Monthly,
            timezone: blank($account->limit_timezone) ? 'UTC' : (string) $account->limit_timezone,
        );
    }

    private function limitForGlobal(?AiGlobalUsageLimit $limit): AiLimit
    {
        if ($limit === null) {
            return new AiLimit(null, null, null, AiLimitPeriod::Monthly, 'UTC');
        }

        return new AiLimit(
            inputTokens: $limit->input_token_limit !== null ? (int) $limit->input_token_limit : null,
            outputTokens: $limit->output_token_limit !== null ? (int) $limit->output_token_limit : null,
            totalTokens: $limit->total_token_limit !== null ? (int) $limit->total_token_limit : null,
            period: AiLimitPeriod::Monthly,
            timezone: (string) $limit->timezone,
        );
    }

    /**
     * Two row locks in one transaction (global + account) means deadlocks are
     * a when, not an if. Retry only on deadlock; rethrow everything else.
     *
     * @param  Closure(): AiUsageReservation  $callback
     */
    private function transactionWithRetry(Closure $callback): AiUsageReservation
    {
        for ($attempt = 1; $attempt <= self::DEADLOCK_RETRIES; $attempt++) {
            try {
                return DB::transaction($callback);
            } catch (QueryException $exception) {
                if ($attempt === self::DEADLOCK_RETRIES || ! str_contains(strtolower($exception->getMessage()), 'deadlock')) {
                    throw $exception;
                }
            }
        }

        throw new RuntimeException('Unable to reserve AI usage.');
    }
}
