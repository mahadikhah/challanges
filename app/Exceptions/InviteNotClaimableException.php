<?php

namespace App\Exceptions;

use App\Enums\InviteRejection;
use App\Models\Invite;
use RuntimeException;

/**
 * An invite code was offered and refused.
 *
 * One class rather than four, because every caller handles these together —
 * `/start` catches this and picks a reply — and the `reason` is what selects the
 * reply. Splitting it into four classes would mean four catch blocks that all do
 * the same shape of thing.
 *
 * The rejection is never fatal to the arrival itself: a bad code means the user
 * still gets in, just without attribution. Callers are expected to catch this and
 * carry on, not to let it reach the webhook.
 */
class InviteNotClaimableException extends RuntimeException
{
    /**
     * `$inviteCode` rather than `$code`, which is not a style choice: `Exception`
     * already owns a non-readonly `$code`, and redeclaring it readonly is a
     * **compile-time fatal** the moment this file is autoloaded.
     *
     * @param  string  $inviteCode  the code as it was offered, already normalised
     * @param  Invite|null  $invite  the row, when one was found at all
     */
    private function __construct(
        public readonly InviteRejection $reason,
        public readonly string $inviteCode,
        public readonly ?Invite $invite,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $code): self
    {
        return new self(
            InviteRejection::NotFound,
            $code,
            null,
            "No invite exists with code {$code}.",
        );
    }

    public static function selfInvite(Invite $invite): self
    {
        return new self(
            InviteRejection::SelfInvite,
            $invite->code,
            $invite,
            "Invite {$invite->code} belongs to the user trying to claim it.",
        );
    }

    public static function alreadyClaimed(Invite $invite): self
    {
        return new self(
            InviteRejection::AlreadyClaimed,
            $invite->code,
            $invite,
            "Invite {$invite->code} was already claimed by user {$invite->invited_user_id}.",
        );
    }

    public static function inviteeAlreadyAttributed(Invite $invite): self
    {
        return new self(
            InviteRejection::InviteeAlreadyAttributed,
            $invite->code,
            $invite,
            "The user claiming invite {$invite->code} already arrived through another invite.",
        );
    }
}
