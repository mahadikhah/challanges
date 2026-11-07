<?php

namespace App\Actions\CheckIns;

use App\Enums\CheckInSessionStatus;
use App\Models\CheckInSession;
use Illuminate\Support\Collection;

/**
 * Mark open sessions expired once their period has ended.
 *
 * Bookkeeping, not correctness: the settlement engine counts only `completed`
 * sessions, and `RollOverPeriod`'s miss detection looks at the `CheckIn` row,
 * which an open session never touched. A participant who never finished their
 * steps is marked missed (or frozen) by the rollover whether or not this sweep
 * has run — confirmed by test. What the sweep buys is an honest record: the
 * session row says "expired" instead of claiming to be in progress forever.
 *
 * Chunked and re-runnable: an already-expired row cannot match the query, so a
 * second pass finds nothing to do.
 */
class ExpireStaleCheckInSessions
{
    /**
     * Expire every open session whose period has closed, and return them.
     *
     * @return Collection<int, CheckInSession>
     */
    public function handle(): Collection
    {
        $expired = CheckInSession::query()
            ->where('status', CheckInSessionStatus::InProgress)
            ->whereHas('period', fn ($query) => $query->where('ends_at', '<=', now()))
            ->with('period')
            ->get();

        $expired->each(function (CheckInSession $session): void {
            // An update, not a mass update, so model events (when any arrive)
            // see each transition — and `current_step_order` is cleared with
            // the status, not left pointing at a step nobody will answer.
            $session->update([
                'status' => CheckInSessionStatus::Expired,
                'current_step_order' => null,
            ]);
        });

        return $expired;
    }
}
