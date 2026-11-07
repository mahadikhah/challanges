<?php

namespace App\Actions\Observability;

use App\Enums\ExternalCallOutcome;
use App\Enums\ExternalCallProvider;
use App\Models\ExternalCallStat;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Increment one provider's outcome counter for today.
 *
 * Called from the real call sites (the messenger platforms, the payment legs,
 * the AI review chain), so it must be cheap enough to sit inside a webhook:
 * one UPDATE against an indexed row, falling back to one INSERT for the day's
 * first call. A counter write must never be able to fail the call it counts —
 * every caller wraps it in the silent `attempt()` below, because losing a
 * statistic is nothing next to losing a reminder.
 */
class RecordExternalCall
{
    public function handle(ExternalCallProvider $provider, ExternalCallOutcome $outcome): void
    {
        $day = today()->toDateString();

        $matched = ExternalCallStat::query()
            ->where('provider', $provider->value)
            ->where('day', $day)
            ->where('outcome', $outcome->value)
            ->update(['count' => DB::raw('count + 1'), 'updated_at' => now()]);

        if ($matched > 0) {
            return;
        }

        try {
            ExternalCallStat::query()->create([
                'provider' => $provider,
                'day' => $day,
                'outcome' => $outcome,
                'count' => 1,
            ]);
        } catch (QueryException) {
            // Two processes raced the day's first call and the other insert
            // won the unique key — run the update again, which now matches.
            ExternalCallStat::query()
                ->where('provider', $provider->value)
                ->where('day', $day)
                ->where('outcome', $outcome->value)
                ->update(['count' => DB::raw('count + 1'), 'updated_at' => now()]);
        }
    }

    /**
     * Count a call without ever letting the counting matter to the caller.
     */
    public function attempt(ExternalCallProvider $provider, callable $call): mixed
    {
        try {
            $result = $call();
        } catch (Throwable $failure) {
            try {
                $this->handle($provider, ExternalCallOutcome::Failure);
            } catch (Throwable) {
                // The counter is best-effort by design.
            }

            throw $failure;
        }

        try {
            $this->handle($provider, ExternalCallOutcome::Success);
        } catch (Throwable) {
            // Ditto.
        }

        return $result;
    }
}
