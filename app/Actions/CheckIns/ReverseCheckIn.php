<?php

namespace App\Actions\CheckIns;

use App\Enums\CheckInStatus;
use App\Exceptions\CheckInRejectedException;
use App\Models\ChallengeParticipant;
use App\Models\CheckIn;
use Illuminate\Support\Facades\DB;

/**
 * The counterpart to `SettleCheckIn` for the override case: undo a settled
 * check-in so a different verdict can take its place.
 *
 * An AI-approved photo that an admin flips to rejected is the one case where
 * a settlement has already moved a streak and the decision is nevertheless
 * overturned afterwards. The participant must not keep the streak a wrong
 * approval gave them — and must not lose it twice, either.
 *
 * **Reversal is not a verdict.** It returns the row to `Submitted` — back in
 * the manual review queue — and un-does the counter effects the settlement
 * applied. It is the caller (the override action) that then records the new
 * verdict, so the flip reads in the audit trail as two deliberate steps
 * rather than one opaque transition.
 *
 * **The same idempotency discipline as `SettleCheckIn`, inverted.** The row
 * is only reversed while it is settled: an unsettled row is refused (there is
 * nothing to undo), and a reversed row is already back in an unsettled state,
 * so a second reversal lands on that first refusal rather than subtracting a
 * streak twice. The participant row is locked first for the same reason as
 * the original settlement — the counters are read-then-write.
 *
 * The arithmetic is the exact inverse of `SettleCheckIn::advance()`, kept in
 * step with it deliberately: whichever status rule moved a counter on the way
 * in, the same rule moves it back on the way out. `longest_streak` is *not*
 * recomputed — a record set while the reversal is pending stands; rewriting
 * history upward later would be someone else's streak.
 */
class ReverseCheckIn
{
    public function reverse(CheckIn $checkIn): CheckIn
    {
        return DB::transaction(function () use ($checkIn): CheckIn {
            $participant = $this->lockParticipant($checkIn);

            // Re-read inside the lock, exactly as the settlement does: the
            // caller's instance may predate another settlement or an
            // override, and deciding on a stale status is how a streak gets
            // subtracted twice.
            $checkIn->refresh();

            if (! $checkIn->status->isSettled()) {
                throw CheckInRejectedException::notReversible($checkIn);
            }

            $this->retreat($participant, $checkIn->status);

            $checkIn->update(['status' => CheckInStatus::Submitted]);

            return $checkIn;
        });
    }

    /**
     * Take the per-participant mutex, per `SettleCheckIn`.
     */
    private function lockParticipant(CheckIn $checkIn): ChallengeParticipant
    {
        /** @var ChallengeParticipant $participant */
        $participant = ChallengeParticipant::query()
            ->whereKey($checkIn->challenge_participant_id)
            ->lockForUpdate()
            ->firstOrFail();

        return $participant;
    }

    /**
     * Un-move the participant's counters to match the settlement being undone.
     *
     * Each branch is guarded by the same `CheckInStatus` rule that applied
     * the effect, so this can never drift from what `advance()` did — only a
     * status that actually moved a counter on the way in moves it back.
     */
    private function retreat(ChallengeParticipant $participant, CheckInStatus $was): void
    {
        if ($was->incrementsStreak()) {
            $participant->current_streak = max(0, $participant->current_streak - 1);
        }

        if ($was->consumesFreeze()) {
            $participant->freezes_used = max(0, $participant->freezes_used - 1);
        }

        if ($was->breaksStreak()) {
            // The streak that was reset to zero cannot be rebuilt exactly —
            // what existed before the miss is unrecoverable from the row —
            // but the reset itself is undone as a counted event, and the
            // participant is returned to the queue rather than left marked
            // as having lost the period.
            $participant->streak_resets_count = max(0, $participant->streak_resets_count - 1);
        }

        $participant->save();
    }
}
