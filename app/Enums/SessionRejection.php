<?php

namespace App\Enums;

/**
 * Why a timed check-in session could not start, advance, or complete.
 *
 * Carried on `SessionRejectedException` so a caller can `match` on the cause
 * rather than parse a message, exactly as `CheckInRejection` does for
 * submissions. Every case here is a routine, expected outcome a surface must
 * answer with a specific sentence — "N seconds left" and "you already finished
 * today" are different conversations, and a surface that cannot tell them
 * apart answers all of them with something vague.
 *
 * Deliberately **no** `label()`, matching `CheckInRejection`: these select
 * which sentence a surface sends; they are not values in a list.
 */
enum SessionRejection: string
{
    /** The challenge collects one submission per period, not a step session. */
    case NotATimedChallenge = 'not_a_timed_challenge';

    /** The user is not in this challenge, or is no longer active in it. */
    case NotAParticipant = 'not_a_participant';

    /** The moment falls outside every period of the challenge's timeline. */
    case NoOpenPeriod = 'no_open_period';

    /** The period's check-in is already settled — there is nothing left to earn. */
    case AlreadySettled = 'already_settled';

    /** The session has finished, expired, or been abandoned; it cannot take a step. */
    case SessionNotOpen = 'session_not_open';

    /** The step submitted is not the session's current step — a replay or a guess. */
    case WrongStep = 'wrong_step';

    /** The step's minimum wait has not elapsed yet. */
    case TooEarly = 'too_early';

    /** The voice message ran past the step's cap; a shorter one must be sent. */
    case VoiceTooLong = 'voice_too_long';

    /** The step demands media or a duration and none arrived. */
    case SubmissionMissing = 'submission_missing';

    /** The step demands a bare tap and something else arrived with it. */
    case UnexpectedSubmission = 'unexpected_submission';
}
