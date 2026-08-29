<?php

namespace App\Actions\CheckIns;

use App\Enums\CheckInSessionStatus;
use App\Exceptions\CheckInRejectedException;
use App\Exceptions\SessionRejectedException;
use App\Models\CheckInSession;
use Illuminate\Support\Facades\DB;

/**
 * Finish a session and hand its result to the ordinary settlement engine.
 *
 * The whole of §3.6's rule for timed sessions: **a session is a new way to
 * *arrive* at a settled check-in, not a new settlement engine.** Marking the
 * session `completed` and approving its check-in happen in one transaction,
 * through `SettleCheckIn` exactly as a button tap reaches it — the same
 * participant lock, the same streak arithmetic, the same `CheckInSettled`
 * event, so a linked-chat announcement fires for a session completion just as
 * it does for a tap.
 *
 * `SettleCheckIn` is not modified and not forked; the only adaptation is that
 * the check-in row is opened the way every other path opens it.
 */
class CompleteCheckInSession
{
    public function __construct(
        private readonly OpenCheckIn $openCheckIn,
        private readonly SettleCheckIn $settle,
    ) {}

    /**
     * Mark the session finished and approve the check-in it was running for.
     *
     * `$reportedValue` is the quantity a `quantity` challenge asks for once, at
     * session end — passed straight through to the settlement, which decides
     * whether it clears the bar.
     *
     *
     * @throws SessionRejectedException when the session is not open
     * @throws CheckInRejectedException when the period's check-in is already settled
     */
    public function handle(CheckInSession $session, int|float|string|null $reportedValue = null): CheckInSession
    {
        if (! $session->isOpen()) {
            throw SessionRejectedException::sessionNotOpen($session);
        }

        return DB::transaction(function () use ($session, $reportedValue): CheckInSession {
            // Marked complete *inside* the settlement's transaction: the
            // `open` generated column goes NULL the moment this lands, which
            // is what frees the (participant, period) pair for a future
            // period — and pairs the two writes so neither can exist alone.
            $session->update([
                'status' => CheckInSessionStatus::Completed,
                'completed_at' => now(),
                'current_step_order' => null,
            ]);

            $checkIn = $this->openCheckIn->handle($session->participant, $session->period);

            $settled = $this->settle->approve($checkIn, $reportedValue);

            // The rollover can have closed the period between the session's
            // last gate and here. The session stays completed — the steps were
            // genuinely run — but the participant must hear "too late", not
            // have a Missed row reported to them as success.
            if ($settled->status->isSettled() && ! $settled->status->incrementsStreak()) {
                throw CheckInRejectedException::alreadySettled($settled);
            }

            return $session;
        });
    }
}
