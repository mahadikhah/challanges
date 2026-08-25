<?php

namespace App\Actions\CheckIns;

use App\Enums\CheckInStatus;
use App\Exceptions\CheckInRejectedException;
use App\Models\CheckIn;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The creator's verdict on a photo.
 *
 * Only `image_approval` produces rows that reach here — a tap and a matching
 * phrase are settled the moment they arrive. The same two methods serve the bot's
 * inline approve/reject buttons and the admin panel's review queue.
 *
 * **Authorization is re-derived from the row on every call.** Ownership is read
 * off `$checkIn->participant->challenge->creator_id`, never from anything the
 * caller passed alongside it, so a client cannot review somebody else's challenge
 * by supplying a check-in id it has no business with — the id resolves to a row,
 * the row names its own owner, and the owner has to be the actor.
 *
 * **Rejection is not an ending.** It returns the row to a state that accepts
 * another photo, and only the period rollover decides whether the period was
 * actually lost. That keeps one rule in one place: the streak engine only ever
 * looks for `Missed`.
 *
 * **A returned `CheckIn` always means the verdict took**, matching
 * `SubmitCheckIn` — anything else throws with a reason. Approving is *not*
 * idempotent for that purpose: a second approval of an already-approved row is
 * refused rather than quietly re-stamping `reviewed_by`, because the audit trail
 * should name whoever actually made the decision.
 */
class ReviewCheckIn
{
    public function __construct(private readonly SettleCheckIn $settle) {}

    /**
     * Accept the photo. Settles the period and moves the streak.
     *
     * **A late approval is refused, not silently swallowed.** If the rollover
     * already closed the period, the row is `Missed` or `Frozen` and the streak
     * consequence has happened; the creator has to be told that rather than shown
     * a success message they will later have to explain.
     *
     * @throws CheckInRejectedException
     */
    public function approve(User $reviewer, CheckIn $checkIn): CheckIn
    {
        $this->authorise($reviewer, $checkIn);

        return DB::transaction(function () use ($reviewer, $checkIn): CheckIn {
            $this->guardAwaitingVerdict($checkIn);

            $settled = $this->settle->approve($checkIn);

            // The guard above answers the ordinary case with a precise reason;
            // this catches the rollover landing in the gap between the two.
            if ($settled->status !== CheckInStatus::Approved) {
                throw CheckInRejectedException::alreadySettled($settled);
            }

            // Stamped after the settlement, and only because it took: the
            // reviewer is recorded exactly when their decision had an effect.
            $settled->update([
                'reviewed_by' => $reviewer->getKey(),
                'reviewed_at' => now(),
            ]);

            return $settled;
        });
    }

    /**
     * Turn the photo down. The participant may upload another until the period
     * closes; no streak moves either way.
     *
     * @throws CheckInRejectedException
     */
    public function reject(User $reviewer, CheckIn $checkIn): CheckIn
    {
        $this->authorise($reviewer, $checkIn);

        return DB::transaction(function () use ($reviewer, $checkIn): CheckIn {
            $this->guardAwaitingVerdict($checkIn);

            $checkIn->update([
                'status' => CheckInStatus::Rejected,
                'reviewed_by' => $reviewer->getKey(),
                'reviewed_at' => now(),
            ]);

            return $checkIn;
        });
    }

    /**
     * Insist there is actually a verdict to give, and say precisely why not.
     *
     * Re-read inside the transaction: the caller's instance may predate a
     * resubmission or a rollover, and deciding on that stale status would either
     * overwrite a photo the creator never looked at or rewrite a settlement that
     * has already had its streak consequence.
     *
     * The two refusals are told apart because they are different sentences. A
     * settled row means the period is over and the outcome is fixed; a `Pending`
     * or `Rejected` row means no photo is currently on the table. A creator who
     * clicks an old inline button deserves to know which.
     *
     * @throws CheckInRejectedException
     */
    private function guardAwaitingVerdict(CheckIn $checkIn): void
    {
        $checkIn->refresh();

        if ($checkIn->status->isSettled()) {
            throw CheckInRejectedException::alreadySettled($checkIn);
        }

        if (! $checkIn->status->awaitsReview()) {
            throw CheckInRejectedException::notAwaitingReview($checkIn);
        }
    }

    /**
     * Only the challenge's creator, or a platform admin.
     *
     * Admins are included because they answer the support ticket when a creator
     * goes quiet mid-challenge and a queue of photos strands everybody's streak.
     *
     * A creator who is also a participant can approve their own photo. That falls
     * out of the design rather than slipping past it — they chose the proof type
     * and could have picked `button` — so it is allowed, and visible in
     * `reviewed_by`.
     *
     * @throws CheckInRejectedException
     */
    private function authorise(User $reviewer, CheckIn $checkIn): void
    {
        if ($reviewer->is_admin) {
            return;
        }

        if ($checkIn->participant->challenge->creator_id === $reviewer->getKey()) {
            return;
        }

        throw CheckInRejectedException::notTheReviewer($checkIn);
    }
}
