<?php

namespace App\Enums;

/**
 * A user's standing in a Telegram chat, as `getChatMember` reports it.
 *
 * The access gate hangs on this: every user must be in the announcement channel
 * before they can use the bot, and "are they in?" is answered by mapping one of
 * these statuses to a yes or a no. Modelled as an enum rather than compared as
 * strings so the mapping lives in one readable `match` instead of being spelled
 * out — differently — at each call site.
 *
 * Deliberately **no** `label()`: these are never shown to a user. Nobody is told
 * "your status is kicked"; they are told to join the channel.
 */
enum ChatMemberStatus: string
{
    /**
     * The channel's owner.
     */
    case Creator = 'creator';

    case Administrator = 'administrator';

    case Member = 'member';

    /**
     * Under a restriction. **The only status that cannot be decided on its own** —
     * see `grantsAccess()`.
     */
    case Restricted = 'restricted';

    /**
     * Left of their own accord, or was never in.
     */
    case Left = 'left';

    /**
     * Banned.
     */
    case Kicked = 'kicked';

    /**
     * A status this deploy does not recognise.
     *
     * Not a Bot API value. Telegram has added statuses before and may again, and
     * an unrecognised one has to become *something* — so it becomes this, which
     * `grantsAccess()` refuses. A gate that let strangers through on a value it
     * failed to parse would be worse than one that occasionally over-blocks.
     */
    case Unknown = 'unknown';

    /**
     * Read Telegram's `status` string, tolerating anything.
     */
    public static function fromTelegram(mixed $status): self
    {
        return is_string($status)
            ? self::tryFrom($status) ?? self::Unknown
            : self::Unknown;
    }

    /**
     * Whether this standing means the user is in the chat right now.
     *
     * `restricted` is genuinely ambiguous: Telegram uses it both for a member
     * who is muted and for someone who left while a restriction was still in
     * force, and `is_member` is the only thing that tells the two apart. Every
     * other status answers on its own, which is why `$isMember` is consulted for
     * exactly one case and ignored for the rest.
     *
     * @param  bool|null  $isMember  the `is_member` field, when Telegram sent one
     */
    public function grantsAccess(?bool $isMember = null): bool
    {
        return match ($this) {
            self::Creator, self::Administrator, self::Member => true,
            self::Restricted => $isMember === true,
            self::Left, self::Kicked, self::Unknown => false,
        };
    }
}
