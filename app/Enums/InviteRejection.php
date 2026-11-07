<?php

namespace App\Enums;

/**
 * Why an invite code could not be claimed.
 *
 * Carried on `InviteNotClaimableException` so a caller can `match` on the cause
 * instead of parsing a message. The four cases are genuinely different
 * conversations: one is a typo, one is a misunderstanding, and two are "you are
 * too late" from opposite ends.
 *
 * Deliberately **no** `label()`, for the same reason `ConversationState` has
 * none: these are not nouns to render next to a value, they select which
 * sentence the bot sends. Those sentences are their own lang lines.
 */
enum InviteRejection: string
{
    /**
     * No invite exists with that code. Almost always a mistyped or truncated
     * deep link.
     */
    case NotFound = 'not_found';

    /**
     * The inviter tried to claim their own code. Refused rather than ignored,
     * because the obvious next question is "then how do I get coins?".
     */
    case SelfInvite = 'self_invite';

    /**
     * Somebody else already arrived through this code. Codes are single-use.
     */
    case AlreadyClaimed = 'already_claimed';

    /**
     * The arriving user already came in through an invite. Attribution is
     * for life, so a second one is refused even if the code itself is open.
     */
    case InviteeAlreadyAttributed = 'invitee_already_attributed';
}
