<?php

namespace App\Actions\CheckIns;

use App\Actions\Ai\ApplyAiVerdict;
use App\Enums\ApprovalMode;
use App\Enums\CheckInSessionStatus;
use App\Enums\CheckInStatus;
use App\Enums\StepInputType;
use App\Exceptions\SessionRejectedException;
use App\Models\ChallengeStep;
use App\Models\CheckIn;
use App\Models\CheckInSession;
use App\Models\CheckInStepSubmission;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Satisfy one step of an open session, in order, after its wait.
 *
 * Three gates, each its own refusal — a surface owes the participant a
 * specific sentence, not a generic "invalid":
 *
 * 1. **The step must be the session's current step.** Callback data can be
 *    replayed and messages can be old; a step from another position in the
 *    sequence is a replay or a guess, not progress.
 * 2. **The wait must have elapsed.** The wait is the point of a timed session
 *    — accepting an early tap would make it decorative. The exact remaining
 *    seconds travel on the exception so the surface can say them.
 * 3. **The submission must be the kind the step demands**, and a recording
 *    must respect its cap — a voice message the step's own
 *    `voice_max_seconds`, a video the challenge's media caps — measured by
 *    the duration the messenger reports, never a client-supplied number.
 *
 * The final step has two endings. The ordinary one hands the session to
 * `CompleteCheckInSession`, which approves the check-in — a completed
 * session *is* a settled check-in. On a challenge whose `approval_mode` is
 * `ai`, the final step's evidence is instead *submitted for review*: the
 * check-in is opened, the media attached, and the one shared AI-verdict
 * router decides — approve settles and the session completes through the
 * ordinary path, while a rejection or a fallback to the manual queue leaves
 * the check-in undecided and the session ends *without* the bundled
 * approval, because `CompleteCheckInSession` would approve a row the verdict
 * deliberately left open. The settlement engines themselves are untouched:
 * every decision still lands through `ApplyAiVerdict` and
 * `SettleCheckIn`, the same paths a photo takes.
 */
class AdvanceCheckInStep
{
    public function __construct(
        private readonly CompleteCheckInSession $complete,
        private readonly OpenCheckIn $openCheckIn,
        private readonly ApplyAiVerdict $aiVerdict,
    ) {}

    /**
     * @param  array{proof_path?: string|null, voice_seconds?: int|null, video_seconds?: int|null, media_size_kb?: int|null}  $submission
     * @param  int|float|string|null  $reportedValue  the quantity report a quantity
     *                                                challenge asks for once, at the final step
     *
     * @throws SessionRejectedException when any gate refuses
     */
    public function handle(CheckInSession $session, ChallengeStep $step, array $submission = [], ?CarbonInterface $now = null, int|float|string|null $reportedValue = null): CheckInSession
    {
        $at = CarbonImmutable::instance($now ?? now());

        $this->assertOpen($session);
        $this->assertCurrent($session, $step);
        $this->assertWaitElapsed($session, $step, $at);
        $this->assertSubmissionShaped($step, $submission);

        // Set inside the transaction below when the final step belongs to an
        // AI-reviewed challenge: the check-in waiting on the verdict router.
        $pendingReview = null;

        $result = DB::transaction(function () use ($session, $step, $submission, $at, $reportedValue, &$pendingReview): CheckInSession {
            // Re-read under the write lock: two taps on the same button arrive
            // as two updates, and the second must see the first's advance.
            /** @var CheckInSession $fresh */
            $fresh = CheckInSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            if (! $fresh->isOpen()) {
                throw SessionRejectedException::sessionNotOpen($fresh);
            }

            if ($fresh->current_step_order !== $step->step_order) {
                throw SessionRejectedException::wrongStep($fresh, $step);
            }

            $next = $this->nextOrder($fresh, $step);

            $fresh->submissions()->create([
                'challenge_step_id' => $step->getKey(),
                'submitted_at' => $at,
                'proof_path' => $submission['proof_path'] ?? null,
            ]);

            if ($next === null) {
                $evidence = $this->reviewEvidence($fresh);

                if ($evidence !== null && $this->completesUnderReview($fresh)) {
                    $pendingReview = $this->submitForReview($fresh, $evidence, $reportedValue);

                    return $fresh;
                }

                return $this->complete->handle($fresh, $reportedValue);
            }

            $fresh->update(['current_step_order' => $next]);

            return $fresh;
        });

        if ($pendingReview === null) {
            return $result;
        }

        // Deliberately outside the transaction: the provider call is a
        // multi-second HTTP request, and holding the session's row lock
        // across it is how every worker deadlocks.
        $this->aiVerdict->handle($pendingReview);

        if ($pendingReview->refresh()->status === CheckInStatus::Approved) {
            // The verdict already settled the check-in through the ordinary
            // approval path, so the completion below pairs the session's
            // bookkeeping with an approve that is an idempotent no-op on the
            // settled row — exactly the pairing `CompleteCheckInSession`
            // exists to make.
            return $this->completeIfStillOpen($result);
        }

        // A rejection (resubmittable until the period closes) or a fallback
        // to the manual queue: the steps were genuinely run, so the session
        // is over — but ending it through `CompleteCheckInSession` would
        // approve a row the verdict deliberately left undecided. Only the
        // bookkeeping runs; the decision stays with the review queue.
        $result->update([
            'status' => CheckInSessionStatus::Completed,
            'completed_at' => now(),
            'current_step_order' => null,
        ]);

        return $result;
    }

    /**
     * @throws SessionRejectedException
     */
    private function assertOpen(CheckInSession $session): void
    {
        if (! $session->isOpen()) {
            throw SessionRejectedException::sessionNotOpen($session);
        }
    }

    /**
     * @throws SessionRejectedException
     */
    private function assertCurrent(CheckInSession $session, ChallengeStep $step): void
    {
        if ($session->current_step_order !== $step->step_order) {
            throw SessionRejectedException::wrongStep($session, $step);
        }

        // A step belonging to another challenge can never be current on this
        // session, but the message for that owes the caller the true current
        // order — and the comparison above would lie if the orders collided.
        if ($step->challenge_id !== $session->participant->challenge_id) {
            throw SessionRejectedException::wrongStep($session, $step);
        }
    }

    /**
     * @throws SessionRejectedException
     */
    private function assertWaitElapsed(CheckInSession $session, ChallengeStep $step, CarbonImmutable $at): void
    {
        $previous = $session->submissions()
            ->orderByDesc('submitted_at')
            ->first();

        $opensAt = $step->opensAt(CarbonImmutable::instance($session->started_at), $previous?->submitted_at);

        if ($at < $opensAt) {
            throw SessionRejectedException::tooEarly($session, (int) $at->diffInSeconds($opensAt, absolute: true));
        }
    }

    /**
     * @param  array{proof_path?: string|null, voice_seconds?: int|null, video_seconds?: int|null, media_size_kb?: int|null}  $submission
     *
     * @throws SessionRejectedException
     */
    private function assertSubmissionShaped(ChallengeStep $step, array $submission): void
    {
        $path = $submission['proof_path'] ?? null;
        $voiceSeconds = $submission['voice_seconds'] ?? null;
        $videoSeconds = $submission['video_seconds'] ?? null;
        $sizeKb = $submission['media_size_kb'] ?? null;

        $recording = $step->input_type === StepInputType::Voice || $step->input_type === StepInputType::Video;

        if ($step->input_type === StepInputType::Button
            && ($path !== null || $voiceSeconds !== null || $videoSeconds !== null || $sizeKb !== null)) {
            throw SessionRejectedException::unexpectedSubmission($step);
        }

        if ($step->input_type !== StepInputType::Button && ($path === null || trim($path) === '')) {
            throw SessionRejectedException::submissionMissing($step);
        }

        // The duration is the surface's to report honestly — the bot reads
        // it from Telegram's own `voice.duration`; the Mini App reads it
        // from the recorded file. Neither may be *trusted*, but a lie can
        // only shorten, and a too-long message is refused below regardless
        // of who counted it.
        if ($step->input_type === StepInputType::Voice) {
            if ($voiceSeconds === null) {
                throw SessionRejectedException::submissionMissing($step);
            }

            if ($voiceSeconds > (int) $step->voice_max_seconds) {
                throw SessionRejectedException::voiceTooLong($step, $voiceSeconds);
            }
        }

        // A video step is capped by the challenge, not by the step: video
        // duration and size are the platform's storage problem, so both live
        // on the challenge row and are required the moment a video step is
        // designed (see `CreateChallenge::assertMediaCaps()`).
        if ($step->input_type === StepInputType::Video) {
            if ($videoSeconds === null) {
                throw SessionRejectedException::submissionMissing($step);
            }

            $challenge = $step->challenge;

            if ($videoSeconds > (int) $challenge->proof_media_max_seconds) {
                throw SessionRejectedException::videoTooLong($challenge, $videoSeconds);
            }
        }

        // The size cap spans both recording kinds whenever the surface
        // measured the bytes. Null means the surface could not — its own
        // pre-download check is then the only size gate, which is why a
        // surface should always send what it can.
        if ($recording && $sizeKb !== null && $sizeKb > (int) $step->challenge->proof_media_max_size_kb) {
            throw SessionRejectedException::mediaTooLarge($step->challenge, $sizeKb);
        }
    }

    /**
     * The order that follows `$step`, or null when `$step` was the last.
     */
    private function nextOrder(CheckInSession $session, ChallengeStep $step): ?int
    {
        /** @var ChallengeStep|null $next */
        $next = ChallengeStep::query()
            ->where('challenge_id', $step->challenge_id)
            ->where('step_order', '>', $step->step_order)
            ->orderBy('step_order')
            ->first();

        return $next?->step_order;
    }

    /**
     * The media a final-step review would judge: this session's latest
     * proof-bearing submission, whatever step it came from.
     *
     * A review needs something to look at. A step design with no media at
     * all (buttons only) has nothing to submit for review, and completes the
     * ordinary way — there is no evidence an honest reviewer could pass on.
     */
    private function reviewEvidence(CheckInSession $session): ?CheckInStepSubmission
    {
        /** @var CheckInStepSubmission|null $evidence */
        $evidence = $session->submissions()
            ->whereNotNull('proof_path')
            ->orderByDesc('submitted_at')
            ->first();

        return $evidence;
    }

    /**
     * Whether this session's completion must pass through review rather than
     * auto-approve: the challenge asked for AI review of its media proof.
     *
     * The proof *type* decides what the reviewer looks at; the step *inputs*
     * are how the participant produced evidence of it. Only the media-proof
     * types are reviewable at all — a session on a `button` or
     * `text_autogen` challenge has no review to wait for, whatever its mode.
     */
    private function completesUnderReview(CheckInSession $session): bool
    {
        $challenge = $session->participant->challenge;

        return $challenge->approval_mode === ApprovalMode::Ai
            && $challenge->proof_type->isMediaApproval();
    }

    /**
     * Open the period's check-in and attach the evidence to it, the same
     * submission state a photo upload leaves behind.
     *
     * The session's steps are done: `current_step_order` is cleared so a
     * replayed final-step message or callback finds no current step and is
     * refused as stale, exactly as it would be on a completed session.
     *
     * @param  int|float|string|null  $reportedValue  stored with the evidence, so
     *                                                the verdict — model or human — scores a number
     */
    private function submitForReview(CheckInSession $session, CheckInStepSubmission $evidence, int|float|string|null $reportedValue = null): CheckIn
    {
        $checkIn = $this->openCheckIn->handle($session->participant, $session->period);

        $update = [
            'status' => CheckInStatus::Submitted,
            'proof_path' => $evidence->proof_path,
            'submitted_at' => now(),
            // No reviewer stamped: nothing has been decided, and the manual
            // queue (the AI verdict's fallback) presents the row by this
            // same `Submitted` state.
            'reviewed_by' => null,
            'reviewed_at' => null,
        ];

        if ($reportedValue !== null) {
            $update['reported_value'] = number_format((float) $reportedValue, 2, '.', '');
        }

        $checkIn->update($update);

        $session->update(['current_step_order' => null]);

        return $checkIn;
    }

    /**
     * Complete the session when the rollover has not already closed it.
     *
     * `CompleteCheckInSession` refuses a session that is no longer open —
     * the expiry sweep may have won the race while the verdict was being
     * asked. The session's terminal state then already says what happened,
     * and returning it beats masking an honest expiry as an error.
     */
    private function completeIfStillOpen(CheckInSession $session): CheckInSession
    {
        $session->refresh();

        if (! $session->isOpen()) {
            return $session;
        }

        return $this->complete->handle($session);
    }
}
