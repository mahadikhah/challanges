<?php

namespace App\Enums;

/**
 * Why a challenge could not be joined.
 *
 * Carried on `ChallengeNotJoinableException` so a caller can `match` on the cause
 * instead of parsing a message, exactly as `InviteRejection` does for invite codes.
 *
 * Note what is **not** here: "already joined". Joining twice is not a refusal, it
 * is the same join arriving twice — a double-tapped button, a Telegram retry — and
 * `JoinChallenge` answers it by handing back the participation that already exists
 * without spending a second slot. Turning that into an exception would make every
 * caller catch a case that is not an error.
 *
 * No `label()`, for the same reason `InviteRejection` has none: these do not get
 * rendered next to a value, they select which sentence the bot sends.
 */
enum JoinRejection: string
{
    /**
     * The challenge is over or was cancelled. Nothing to join.
     */
    case ChallengeClosed = 'challenge_closed';

    /**
     * Every period has already ended, even though the challenge has not been
     * marked finished yet — rollover runs on a schedule, so there is a window
     * where a challenge looks active but has no period left to check into.
     * Joining would create a participant who can never check in once.
     */
    case TimelineExhausted = 'timeline_exhausted';

    /**
     * They were in this challenge and are not any more: they left, or a moderator
     * removed them, or they finished it. Rejoining is not a user-side act — see
     * `ChallengeNotJoinableException::participationEnded()`.
     */
    case ParticipationEnded = 'participation_ended';
}
