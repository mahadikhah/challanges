<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A Telegram chat offered for linking turned out to be nothing we can post to.
 *
 * Thrown by `TelegramChatType::fromForwardedChat` when a forwarded message's
 * `forward_from_chat.type` names neither a channel nor a group — a private
 * chat forwarded from a user, or a Bot API value this deploy does not know.
 *
 * Callers are expected to catch this and tell the creator plainly; it is a
 * refusal of one forwarded message, not a failure of the flow.
 */
class WrongChatTypeException extends RuntimeException
{
    /**
     * The Bot API value that could not be classified, for the log.
     */
    public readonly string $chatType;

    private function __construct(string $chatType, string $message)
    {
        parent::__construct($message);

        $this->chatType = $chatType;
    }

    public static function unrecognised(string $chatType): self
    {
        return new self(
            $chatType,
            "A forwarded message named a chat of type '{$chatType}', which is neither a channel nor a group.",
        );
    }
}
