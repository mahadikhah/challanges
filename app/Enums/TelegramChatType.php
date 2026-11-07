<?php

namespace App\Enums;

use App\Exceptions\WrongChatTypeException;

/**
 * The kind of Telegram chat a `ChallengeChat` points at.
 *
 * The distinction is load-bearing in exactly one place: a channel admin needs
 * `can_post_messages` before the bot can post there, while a group admin needs
 * nothing beyond the admin status itself. Everything else about a linked chat
 * treats the two alike.
 *
 * Deliberately **no** `label()`: the title the creator gave the chat is echoed
 * back verbatim, and the type only ever decides which Bot API rule applies.
 */
enum TelegramChatType: string
{
    case Channel = 'channel';

    case Group = 'group';

    /**
     * Read the `forward_from_chat.type` of a forwarded message, tolerating
     * anything.
     *
     * Telegram reports `supergroup` as its own type, and a supergroup is a
     * group for posting purposes — the admin rules are the same — so it folds
     * in here rather than growing a case nothing would branch on. Anything
     * unknown is an exception rather than a guess: linking a bot to a chat it
     * cannot classify is a row we could never post to with confidence.
     *
     * @throws WrongChatTypeException when the value names no channel or group
     */
    public static function fromForwardedChat(mixed $type): self
    {
        $type = is_string($type) ? $type : '';

        return match ($type) {
            'channel' => self::Channel,
            'group', 'supergroup' => self::Group,
            default => throw WrongChatTypeException::unrecognised($type),
        };
    }

    /**
     * Whether an administrator of this kind of chat can post into it.
     *
     * `getChatMember` reports privileges per admin; `can_post_messages` only
     * ever appears for channels, so the group branch asks nothing extra.
     */
    public function requiresPostPrivilege(): bool
    {
        return $this === self::Channel;
    }
}
