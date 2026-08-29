<?php

namespace App\Actions\CheckIns;

use App\Enums\CheckInStatus;
use App\Events\CheckInSettled;
use App\Models\ChallengeParticipant;
use App\Models\CheckIn;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * The only place a check-in becomes final and a streak moves.
 *
 * Every surface reaches this: a tapped button in the bot, a matching phrase, a
 * creator approving a photo in the admin panel, and the period rollover closing
 * what nobody submitted. There is one copy of the arithmetic, so the bot and the
 * Mini App cannot disagree about what a streak is.
 *
 * **The status transition *is* the idempotency token.** A settled row is never
 * settled again, and the status change and the counter updates happen in the same
 * transaction, so a streak can only move on the transition into a settled status —
 * exactly once, however many times the caller is retried. Nothing needs a separate
 * "already counted" flag. The same holds for the score: `total_score` moves only
 * on that one transition, so a replayed settlement cannot double-credit it.
 *
 * **The participant row is locked first.** `current_streak`, `freezes_used` and
 * `streak_resets_count` are all read-then-write, and two settlements for the same
 * participant can genuinely arrive together: a rollover sweep closing yesterday
 * while the participant taps today's button. Without the lock both would read the
 * same streak and one increment would vanish. It also makes the freeze decision
 * safe — "is a freeze available?" and "spend it" are one atomic step, so a
 * participant with one freeze left cannot have two periods frozen by it.
 *
 * The rules themselves live on `CheckInStatus`, not here: `incrementsStreak()`,
 * `breaksStreak()`, `consumesFreeze()`. This class decides *which* status a
 * settlement lands on and applies the consequences the status declares. For a
 * quantity challenge it also decides whether a report clears the bar — see
 * `scored()`.
 */
class SettleCheckIn
{
    /**
     * Settle as done. The participant checked in.
     *
     * For a quantity challenge, `$reportedValue` is what they reported. Reaching
     * `target_value` (or falling short under the creator's partial opt-in) settles
     * as done with the strategy's score; falling short without the opt-in is a
     * miss through the ordinary freeze engine, because reaching the target *is*
     * the bar — see `scored()`.
     *
     * **The caller must read the returned status rather than assume it.** A row
     * that was already settled is returned untouched, so approving twice is a
     * harmless no-op — but approving one the rollover has already closed returns a
     * `Missed` row, and a creator reviewing late needs to be told that rather than
     * shown a success message.
     *
     * A caller that passes no value is not guessing zero: the row's own stored
     * `reported_value` is used when there is one, because the review paths — a
     * creator approving a photo hours later, an AI verdict at upload — settle
     * long after the number was recorded on the row. A row with neither a
     * passed nor a stored value falls through `scored()` as below-target, which
     * is why every submission surface is required to collect one.
     */
    public function approve(CheckIn $checkIn, int|float|string|null $reportedValue = null): CheckIn
    {
        $reported = $reportedValue ?? $checkIn->reported_value;

        return $this->settle(
            $checkIn,
            fn (ChallengeParticipant $participant): CheckInStatus => $this->scored($checkIn, $participant, $reported),
            $reported,
        );
    }

    /**
     * Settle an unapproved check-in because its period has ended.
     *
     * A freeze absorbs the miss if one is available, otherwise the streak is lost.
     * The choice is made inside the lock, against the freeze count as it actually
     * is at that moment.
     */
    public function close(CheckIn $checkIn): CheckIn
    {
        return $this->settle($checkIn, fn (ChallengeParticipant $participant): CheckInStatus => $participant->hasFreezeAvailable()
            ? CheckInStatus::Frozen
            : CheckInStatus::Missed);
    }

    /**
     * Settle the row and apply what the resulting status implies.
     *
     * @param  Closure(ChallengeParticipant): CheckInStatus  $outcome  decided inside the lock, so it sees current counters
     * @param  int|float|string|null  $reportedValue  stored on the row as evidence of what was reported, whatever the outcome
     */
    private function settle(CheckIn $checkIn, Closure $outcome, int|float|string|null $reportedValue = null): CheckIn
    {
        return DB::transaction(function () use ($checkIn, $outcome, $reportedValue): CheckIn {
            $participant = $this->lockParticipant($checkIn);

            // Re-read inside the lock. The caller's instance may have been loaded
            // before a concurrent settlement committed, and acting on that stale
            // status is how a period gets counted twice.
            $checkIn->refresh();

            if ($checkIn->status->isSettled()) {
                return $checkIn;
            }

            $status = $outcome($participant);
            $score = $this->scoreFor($participant, $status, $reportedValue);

            $update = ['status' => $status];

            // What was reported rides on the row whatever the outcome — a miss
            // by a whisker is still a fact worth being able to point at.
            if ($reportedValue !== null) {
                $update['reported_value'] = number_format((float) $reportedValue, 2, '.', '');
            }

            if ($score !== null) {
                $update['score'] = $score;
            }

            $checkIn->update($update);
            $this->advance($participant, $status, $score);

            // Announce the transition itself, not the call: the event fires
            // only on the one update that moved the row into a settled status,
            // which is the same transaction that moved the streak — so a
            // listener can never see the one without the other.
            CheckInSettled::dispatch($checkIn);

            return $checkIn;
        });
    }

    /**
     * Whether a quantity report clears the bar, and the status that follows.
     *
     * Reaching `target_value` settles as done. Falling short is a miss by
     * default — reaching the target *is* the bar, exactly as a photo the
     * creator rejects is — and only the creator's explicit
     * `quantity_partial_counts_as_done` opt-in lets a partial report keep the
     * streak alive at a proportionally lower score. A binary challenge is
     * never asked: the plain `Approved` it always got.
     */
    private function scored(CheckIn $checkIn, ChallengeParticipant $participant, int|float|string|null $reportedValue): CheckInStatus
    {
        $challenge = $participant->challenge;

        if (! $challenge->scoring_type->isQuantity()) {
            return CheckInStatus::Approved;
        }

        $reached = $reportedValue !== null
            && (float) $reportedValue >= (float) $challenge->target_value;

        if ($reached || $challenge->quantity_partial_counts_as_done) {
            return CheckInStatus::Approved;
        }

        // Below the bar with no opt-in: the ordinary miss engine, unchanged.
        return $participant->hasFreezeAvailable()
            ? CheckInStatus::Frozen
            : CheckInStatus::Missed;
    }

    /**
     * The score a settlement earns, or null when it earns none.
     *
     * Only a *done* quantity settlement scores — a miss or a freeze recorded
     * nothing worth scoring, and the partial opt-in buys a lower score, not a
     * free one. The strategy itself is the enum's arithmetic.
     */
    private function scoreFor(ChallengeParticipant $participant, CheckInStatus $status, int|float|string|null $reportedValue): ?int
    {
        $challenge = $participant->challenge;

        if (! $challenge->scoring_type->isQuantity()
            || $status !== CheckInStatus::Approved
            || $reportedValue === null) {
            return null;
        }

        return $challenge->scoring_strategy->score($challenge->target_value, $challenge->base_points, $reportedValue);
    }

    /**
     * Take the per-participant mutex and return the row as it currently stands.
     *
     * Deliberately not `$checkIn->participant` — that may be an already-hydrated
     * relation holding counters from before another settlement committed, and the
     * arithmetic below would then write a stale streak back.
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
     * Move the participant's counters to match a settled period.
     *
     * A freeze protects the streak without extending it — the participant did not
     * do the thing, they spent a freeze to avoid the penalty — so `Frozen` touches
     * `freezes_used` and nothing else.
     *
     * A miss with no freeze left resets the streak and counts the reset. It
     * deliberately does **not** change `status`: the participant stays `Active`.
     * They may have spent a friend's invite on that slot, and burning it over one
     * bad day is the wrong trade. `streak_resets_count` is recorded so a stricter
     * "N resets and you are out" rule can be layered on later as a counter check,
     * with no schema change and no rewriting of this method.
     *
     * The score joins the same write the streak does: `total_score` moves only
     * inside this lock, on the one transition that also moved the streak, so two
     * settlements arriving together cannot both read the same total and lose one
     * increment between them.
     */
    private function advance(ChallengeParticipant $participant, CheckInStatus $status, ?int $score): void
    {
        if ($status->incrementsStreak()) {
            $participant->current_streak++;
            $participant->longest_streak = max($participant->longest_streak, $participant->current_streak);
        }

        if ($status->consumesFreeze()) {
            $participant->freezes_used++;
        }

        if ($status->breaksStreak()) {
            $participant->current_streak = 0;
            $participant->streak_resets_count++;
        }

        if ($score !== null) {
            $participant->total_score = number_format((float) $participant->total_score + $score, 2, '.', '');
        }

        $participant->save();
    }
}
