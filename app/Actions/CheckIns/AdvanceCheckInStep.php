<?php

namespace App\Actions\CheckIns;

use App\Enums\StepInputType;
use App\Exceptions\SessionRejectedException;
use App\Models\ChallengeStep;
use App\Models\CheckInSession;
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
 */
class AdvanceCheckInStep
{
    public function __construct(private readonly CompleteCheckInSession $complete) {}

    /**
     * @param  array{proof_path?: string|null, voice_seconds?: int|null, video_seconds?: int|null, media_size_kb?: int|null}  $submission
     *
     * @throws SessionRejectedException when any gate refuses
     */
    public function handle(CheckInSession $session, ChallengeStep $step, array $submission = [], ?CarbonInterface $now = null): CheckInSession
    {
        $at = CarbonImmutable::instance($now ?? now());

        $this->assertOpen($session);
        $this->assertCurrent($session, $step);
        $this->assertWaitElapsed($session, $step, $at);
        $this->assertSubmissionShaped($step, $submission);

        return DB::transaction(function () use ($session, $step, $submission, $at): CheckInSession {
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
                return $this->complete->handle($fresh);
            }

            $fresh->update(['current_step_order' => $next]);

            return $fresh;
        });
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
}
