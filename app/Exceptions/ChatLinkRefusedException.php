<?php

namespace App\Exceptions;

use App\Enums\ChatLinkRefusal;
use RuntimeException;

/**
 * A chat link or chat setting was offered and refused.
 *
 * One class rather than several, because every caller handles these together —
 * the link flow catches this and picks a reply by `reason` — and the reasons
 * share a shape: the creator is told plainly, nothing was written.
 *
 * Never allowed out of the flow: these are answers to a person's request, not
 * failures of the webhook.
 */
class ChatLinkRefusedException extends RuntimeException
{
    private function __construct(
        public readonly ChatLinkRefusal $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function notTheCreator(): self
    {
        return new self(
            ChatLinkRefusal::NotTheCreator,
            'Only the creator of a challenge may link a chat to it.',
        );
    }

    public static function privateProofs(): self
    {
        return new self(
            ChatLinkRefusal::ProofsNotPublic,
            'A challenge whose proofs are private cannot share proof media into a chat.',
        );
    }
}
