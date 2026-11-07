<?php

namespace App\Actions\CheckIns;

use App\Enums\CheckInSessionStatus;
use App\Enums\FlowType;
use App\Exceptions\SessionRejectedException;
use App\Models\ChallengeParticipant;
use App\Models\ChallengePeriod;
use App\Models\CheckInSession;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Open a participant's step session for a period — once.
 *
 * The timed-flow sibling of `OpenCheckIn`: where that finds-or-creates the row
 * a submission lands on, this finds-or-creates the run the steps land in, and
 * both converge rather than race. "Start" is a button, and buttons get
 * double-tapped: the guard is **lock first, then look** — the participant row
 * is taken `lockForUpdate`, so two concurrent starts serialise on it and the
 * second finds the first's session instead of its own insert losing to the
 * unique index with a bang.
 *
 * The unique index on `(participant, period, open)` is the backstop, not the
 * defence: with the lock held it cannot even be reached by a race, and it is
 * still there for the writer that forgot the lock.
 */
class StartCheckInSession
{
    public function __construct(private readonly OpenCheckIn $openCheckIn) {}

    /**
     * The session waiting on its first step, or the one already waiting.
     *
     * @throws SessionRejectedException when the challenge is not a timed one, the
     *                                  participant is not active on it, no period is open, or the period's
     *                                  check-in is already settled
     */
    public function handle(ChallengeParticipant $participant, ChallengePeriod $period, ?CarbonInterface $now = null): CheckInSession
    {
        $at = CarbonImmutable::instance($now ?? now());
        $challenge = $participant->challenge;

        if ($challenge->flow_type !== FlowType::TimedSession) {
            throw SessionRejectedException::notATimedChallenge($challenge);
        }

        if ($challenge->getKey() !== $period->challenge_id) {
            // A period from another challenge is a caller bug, not a user
            // mistake; the participant check below would misfire, so refuse early.
            throw SessionRejectedException::notAParticipant($challenge);
        }

        if ($period->starts_at > $at || $period->ends_at <= $at) {
            throw SessionRejectedException::noOpenPeriod($challenge);
        }

        if (! $participant->owesPeriod($period)) {
            // Not active on the challenge, or a period from before they joined:
            // either way there is nothing here for them to start.
            throw SessionRejectedException::notAParticipant($challenge);
        }

        // A settled check-in leaves nothing to earn: the session is over
        // before it starts, and saying so beats opening one that can only end
        // in "already settled" at its final step.
        $checkIn = $this->openCheckIn->handle($participant, $period);

        if ($checkIn->status->isSettled()) {
            throw SessionRejectedException::alreadySettled($checkIn->status->value);
        }

        return DB::transaction(function () use ($participant, $period): CheckInSession {
            $participant = ChallengeParticipant::query()
                ->whereKey($participant->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $existing = CheckInSession::query()
                ->where('challenge_participant_id', $participant->getKey())
                ->where('challenge_period_id', $period->getKey())
                ->where('status', CheckInSessionStatus::InProgress)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $steps = $participant->challenge->steps()->orderBy('step_order')->get();

            try {
                return CheckInSession::query()->create([
                    'challenge_participant_id' => $participant->getKey(),
                    'challenge_period_id' => $period->getKey(),
                    'status' => CheckInSessionStatus::InProgress,
                    'started_at' => now(),
                    'current_step_order' => $steps->first()?->step_order,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Unreachable with the lock held; here for the caller that
                // skipped it. The winner's row is the answer either way.
                /** @var CheckInSession $session */
                $session = CheckInSession::query()
                    ->where('challenge_participant_id', $participant->getKey())
                    ->where('challenge_period_id', $period->getKey())
                    ->where('status', CheckInSessionStatus::InProgress)
                    ->firstOrFail();

                return $session;
            }
        });
    }
}
