<?php

namespace App\Actions\CheckIns;

use App\Enums\CheckInStatus;
use App\Models\ChallengeParticipant;
use App\Models\ChallengePeriod;
use App\Models\CheckIn;

/**
 * Find — or create — the one row that records what a participant owes for a
 * period.
 *
 * There is exactly one `CheckIn` per (participant, period), enforced by a
 * unique index, and this is the only place that pair turns into a row. A
 * participant tapping *check in*, the rollover sweep closing a period they
 * ignored, and a reminder job issuing their phrase all converge on the same
 * row rather than racing to create three.
 *
 * `firstOrCreate` is race-safe here rather than merely convenient: it delegates
 * to `createOrFirst`, which catches the unique-constraint violation and re-reads
 * the winner's row. Two concurrent taps therefore both return the same row
 * instead of one of them blowing up.
 */
class OpenCheckIn
{
    /**
     * The participant's obligation for this period, opened `Pending` if it did
     * not exist yet.
     *
     * Deliberately does **not** check `$participant->owesPeriod($period)`. The
     * question of *who owes a period* belongs to the caller that decided to
     * open the row — `RollOverPeriod` scopes its sweep, a check-in flow checks
     * the actor — and answering it twice in two places is how the two answers
     * start to disagree.
     */
    public function handle(ChallengeParticipant $participant, ChallengePeriod $period): CheckIn
    {
        return CheckIn::query()->firstOrCreate(
            [
                'challenge_participant_id' => $participant->getKey(),
                'challenge_period_id' => $period->getKey(),
            ],
            ['status' => CheckInStatus::Pending],
        );
    }
}
