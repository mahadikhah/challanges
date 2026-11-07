<?php

namespace App\Messaging\DTO;

/**
 * One message the platform accepted, reduced to what callers use.
 *
 * Nothing in the codebase reads anything off a sent message today, so this
 * carries only the platform's id for it — the one field any future use
 * (editing, pinning) would start from. Platform-specific payloads stay behind
 * the implementation.
 */
final readonly class SentMessage
{
    public function __construct(
        public int $messageId,
    ) {}
}
