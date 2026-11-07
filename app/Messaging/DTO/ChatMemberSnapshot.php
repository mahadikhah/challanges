<?php

namespace App\Messaging\DTO;

use App\Enums\ChatMemberStatus;

/**
 * One user's standing in one chat, as the platform reported it.
 *
 * `status` stays the platform's own token (`ChatMemberStatus::fromTelegram()`
 * judges it), because the token is the entire verdict; the two booleans are
 * the optional refinements Telegram omits rather than reports as false —
 * absent stays `null` so "not said" never masquerades as "no".
 */
final readonly class ChatMemberSnapshot
{
    public function __construct(
        public string $status,
        public ?bool $isMember = null,
        public ?bool $canPostMessages = null,
    ) {}

    /**
     * Whether this standing is one of the two that run the chat.
     */
    public function isAdmin(): bool
    {
        return in_array(
            ChatMemberStatus::fromTelegram($this->status),
            [ChatMemberStatus::Creator, ChatMemberStatus::Administrator],
            true,
        );
    }
}
