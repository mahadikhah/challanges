<?php

namespace App\Exceptions;

use App\Enums\JoinRejection;
use App\Models\Challenge;
use App\Models\ChallengeParticipant;
use RuntimeException;

/**
 * A challenge was offered and could not be joined.
 *
 * One class rather than three, because every caller handles these together — the
 * bot catches this and picks a reply — and the `reason` is what selects the reply.
 *
 * Unlike `InviteNotClaimableException`, this one **is** fatal to the thing the user
 * was trying to do: a refused invite still lets them into the bot, but a refused
 * join means they are not in the challenge. Callers report it; they do not carry on
 * as though it worked.
 */
class ChallengeNotJoinableException extends RuntimeException
{
    /**
     * `$challenge`, never `$code` or `$message`: `Exception` already declares
     * non-readonly properties by those names and redeclaring one readonly on a
     * subclass is a compile-time fatal.
     *
     * @param  ChallengeParticipant|null  $participant  the participation that ended,
     *                                                  when that is why we refused
     */
    private function __construct(
        public readonly JoinRejection $reason,
        public readonly Challenge $challenge,
        public readonly ?ChallengeParticipant $participant,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function closed(Challenge $challenge): self
    {
        return new self(
            JoinRejection::ChallengeClosed,
            $challenge,
            null,
            "Challenge {$challenge->getKey()} is {$challenge->status->value} and does not accept joins.",
        );
    }

    public static function timelineExhausted(Challenge $challenge): self
    {
        return new self(
            JoinRejection::TimelineExhausted,
            $challenge,
            null,
            "Challenge {$challenge->getKey()} has no period left to join at.",
        );
    }

    /**
     * They were in it and are not any more.
     *
     * Refused rather than reactivated, and the reason is that each terminal status
     * would need a different answer we have not designed: `Removed` is a
     * moderation decision that must not be undoable by the person it was applied
     * to, `Left` was their own decision to walk away, and `Completed` is a
     * finished record. Reactivating would also mean inventing streak-restoration
     * semantics — does an old streak come back? — so rejoining is a
     * creator/admin act, not a tap on a link.
     */
    public static function participationEnded(ChallengeParticipant $participant): self
    {
        return new self(
            JoinRejection::ParticipationEnded,
            $participant->challenge,
            $participant,
            "User {$participant->user_id} already left challenge {$participant->challenge_id}"
            ." with status {$participant->status->value}.",
        );
    }
}
