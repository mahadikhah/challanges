<?php

namespace App\Actions\Ai;

use App\Actions\CheckIns\SettleCheckIn;
use App\Enums\AiDecisionOutcome;
use App\Enums\ApprovalMode;
use App\Enums\CheckInStatus;
use App\Exceptions\CheckInRejectedException;
use App\Models\CheckIn;
use App\Services\Ai\AiApprovalGate;
use App\Services\Telegram\NotifyCheckInVerdict;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The router between AI review and the manual queue.
 *
 * On every photo submitted to an `approval_mode = ai` challenge: ask
 * `ReviewProofWithAi`, and either let the verdict act — through the same
 * settlement machinery a human verdict takes — or leave the row in the manual
 * queue.
 *
 * Approval goes through `SettleCheckIn::approve()`, the one settlement path
 * every surface already shares, unmodified: the streak moves there or
 * nowhere. `reviewed_by` is deliberately left null — no user made this
 * decision, and the `AiApprovalDecision` row is the audit trail naming what
 * did. Rejection lands the row in `Rejected`, the same resubmittable state a
 * manual rejection uses, for the same reason: an AI "no" is not a period
 * lost, and the participant may send a better photo until the period closes.
 *
 * Below the confidence threshold, on an unreadable response, or when no
 * provider answered at all, the row stays `Submitted` — which *is* its
 * presence in the existing manual queue. There is no second queue.
 */
class ApplyAiVerdict
{
    public function __construct(
        private readonly ReviewProofWithAi $review,
        private readonly SettleCheckIn $settle,
        private readonly NotifyCheckInVerdict $notify,
        private readonly AiApprovalGate $gate,
    ) {}

    /**
     * Route one submitted photo. Never throws: every failure path is the
     * fallback path.
     */
    public function handle(CheckIn $submission): void
    {
        $challenge = $submission->participant->challenge;

        if ($challenge->approval_mode !== ApprovalMode::Ai) {
            return;
        }

        // The admin can withdraw a media type between a challenge's creation
        // and its next submission. The row's mode stays `ai` — history is not
        // rewritten — but no provider is called: the submission waits in the
        // manual queue like any other the AI declined to answer.
        if (! $this->gate->allows($challenge->proof_type)) {
            return;
        }

        $decision = $this->review->review($submission, $challenge);

        if ($decision->outcome !== AiDecisionOutcome::Applied || $decision->approved === null) {
            return;
        }

        try {
            $settled = $decision->approved
                ? $this->approve($submission)
                : $this->reject($submission);
        } catch (Throwable $refused) {
            // The verdict could not take — most likely the rollover closed
            // the period in the gap after submission. The decision row
            // already records what the AI said; the check-in stays where the
            // settlement machinery left it, which is the truthful state.
            Log::warning('An AI verdict could not be applied.', [
                'check_in_id' => $submission->getKey(),
                'approved' => $decision->approved,
                'reason' => $refused::class,
            ]);

            return;
        }

        // The participant hears the verdict in the same words a manual
        // verdict uses; delivery failure must not unwind a settled streak.
        try {
            $decision->approved ? $this->notify->approved($settled) : $this->notify->rejected($settled);
        } catch (Throwable) {
            // The Bot API being down is routine and logged by the sender.
        }
    }

    /**
     * Approve through the one settlement path, insisting it actually took.
     *
     * `SettleCheckIn::approve()` returns an already-settled row untouched by
     * design; for an AI verdict that means the rollover won the race, and
     * reporting the row as "AI approved" would be a lie about a period the
     * participant actually missed.
     *
     * @throws CheckInRejectedException when the row could not be approved
     */
    private function approve(CheckIn $submission): CheckIn
    {
        return DB::transaction(function () use ($submission): CheckIn {
            $submission->refresh();

            if ($submission->status->isSettled()) {
                throw CheckInRejectedException::alreadySettled($submission);
            }

            $settled = $this->settle->approve($submission);

            if ($settled->status !== CheckInStatus::Approved) {
                throw CheckInRejectedException::alreadySettled($settled);
            }

            return $settled;
        });
    }

    /**
     * Reject: the same resubmittable state a manual rejection lands in,
     * with no reviewer stamped because no user decided.
     *
     * @throws CheckInRejectedException when there is no verdict left to give
     */
    private function reject(CheckIn $submission): CheckIn
    {
        return DB::transaction(function () use ($submission): CheckIn {
            $submission->refresh();

            if ($submission->status->isSettled()) {
                throw CheckInRejectedException::alreadySettled($submission);
            }

            if (! $submission->status->awaitsReview()) {
                throw CheckInRejectedException::notAwaitingReview($submission);
            }

            $submission->update(['status' => CheckInStatus::Rejected]);

            return $submission;
        });
    }
}
