<?php

namespace App\Exceptions;

use App\Enums\SessionRejection;
use App\Models\Challenge;
use App\Models\ChallengeStep;
use App\Models\CheckInSession;
use RuntimeException;

/**
 * A timed check-in session could not start, advance, or complete.
 *
 * One class rather than ten, mirroring `CheckInRejectedException`: every
 * caller handles these together — the bot catches it and picks a reply, an
 * HTTP surface catches it and picks a status code — and the `reason` selects
 * the reply. None of these are errors to log; all of them are sentences to
 * send.
 *
 * The counts (`$remainingSeconds`, `$voiceSeconds`, `$voiceCeiling`) travel as
 * numbers rather than being baked into the message, because a surface renders
 * them — "try again in 4 minutes" — in the recipient's own language and
 * units.
 */
class SessionRejectedException extends RuntimeException
{
    private function __construct(
        public readonly SessionRejection $reason,
        string $message,
        public readonly int $remainingSeconds = 0,
        public readonly int $voiceSeconds = 0,
        public readonly int $voiceCeiling = 0,
    ) {
        parent::__construct($message);
    }

    public static function notATimedChallenge(Challenge $challenge): self
    {
        return new self(
            SessionRejection::NotATimedChallenge,
            "Challenge {$challenge->id} collects one submission per period, not a step session.",
        );
    }

    public static function notAParticipant(Challenge $challenge): self
    {
        return new self(
            SessionRejection::NotAParticipant,
            "No active participant on challenge {$challenge->id} for this user.",
        );
    }

    public static function noOpenPeriod(Challenge $challenge): self
    {
        return new self(
            SessionRejection::NoOpenPeriod,
            "Challenge {$challenge->id} has no period open at this moment.",
        );
    }

    public static function alreadySettled(string $settledAs): self
    {
        return new self(
            SessionRejection::AlreadySettled,
            "This period's check-in is already settled as {$settledAs}.",
        );
    }

    public static function sessionNotOpen(CheckInSession $session): self
    {
        return new self(
            SessionRejection::SessionNotOpen,
            "Session {$session->id} is {$session->status->value} and cannot take a step.",
        );
    }

    public static function wrongStep(CheckInSession $session, ChallengeStep $step): self
    {
        return new self(
            SessionRejection::WrongStep,
            "Session {$session->id} is waiting on step "
            .var_export($session->current_step_order, true).", not step {$step->step_order}.",
        );
    }

    public static function tooEarly(CheckInSession $session, int $remainingSeconds): self
    {
        return new self(
            SessionRejection::TooEarly,
            "Session {$session->id}'s current step opens in {$remainingSeconds} seconds.",
            remainingSeconds: $remainingSeconds,
        );
    }

    public static function voiceTooLong(ChallengeStep $step, int $voiceSeconds): self
    {
        return new self(
            SessionRejection::VoiceTooLong,
            "Step {$step->step_order} accepts a voice message of at most "
            ."{$step->voice_max_seconds} seconds; {$voiceSeconds} arrived.",
            voiceSeconds: $voiceSeconds,
            voiceCeiling: (int) $step->voice_max_seconds,
        );
    }

    public static function submissionMissing(ChallengeStep $step): self
    {
        return new self(
            SessionRejection::SubmissionMissing,
            "Step {$step->step_order} is a {$step->input_type->value} step and its input did not arrive.",
        );
    }

    public static function unexpectedSubmission(ChallengeStep $step): self
    {
        return new self(
            SessionRejection::UnexpectedSubmission,
            "Step {$step->step_order} is a {$step->input_type->value} step and accepts nothing but the tap.",
        );
    }
}
