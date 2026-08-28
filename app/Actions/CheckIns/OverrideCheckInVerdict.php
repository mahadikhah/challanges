<?php

namespace App\Actions\CheckIns;

use App\Exceptions\CheckInRejectedException;
use App\Models\CheckIn;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * An admin flipping a decision that already took.
 *
 * The case this exists for: AI review approved a photo with enough
 * confidence to settle it, and a human later disagrees. The settlement has
 * already moved the streak, so disagreeing means *undoing* — not just
 * overwriting the status and hoping nobody notices the arithmetic.
 *
 * Reverse-then-decide, in one transaction, so the row can never be observed
 * half-flipped: `ReverseCheckIn` restores the counters and returns the row
 * to the manual queue, then the ordinary `ReviewCheckIn` verdict the admin
 * actually wanted lands through the same path it always takes. The
 * `reviewed_by` stamp on the outcome names the admin, which is the honest
 * record — the AI's decision stays in `ai_approval_decisions`, and this
 * human's override is stamped on the row.
 *
 * Works in both directions for symmetry: an AI rejection an admin disagrees
 * with is appealed the same way, and a `Missed`/`Frozen` row the rollover
 * settled can be overturned too. What is refused is anything not currently
 * settled — there is nothing to reverse — which is also the idempotency
 * guard: a second override on the same decision finds the row unsettled and
 * is told precisely that.
 */
class OverrideCheckInVerdict
{
    public function __construct(
        private readonly ReverseCheckIn $reverse,
        private readonly ReviewCheckIn $verdicts,
    ) {}

    /**
     * Undo the settlement in force and land the admin's verdict instead.
     *
     * @param  bool  $approved  the verdict that should replace the one being reversed
     *
     * @throws CheckInRejectedException when the row is not settled (nothing to
     *                                  reverse), or the reversal and verdict raced something
     */
    public function handle(User $admin, CheckIn $checkIn, bool $approved): CheckIn
    {
        return DB::transaction(function () use ($admin, $checkIn, $approved): CheckIn {
            $checkIn->refresh();

            if (! $checkIn->status->isSettled()) {
                throw CheckInRejectedException::notReversible($checkIn);
            }

            $reversed = $this->reverse->reverse($checkIn);

            return $approved
                ? $this->verdicts->approve($admin, $reversed)
                : $this->verdicts->reject($admin, $reversed);
        });
    }
}
