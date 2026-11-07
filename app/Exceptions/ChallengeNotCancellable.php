<?php

namespace App\Exceptions;

use App\Models\Challenge;
use RuntimeException;

/**
 * A challenge could not be cancelled.
 *
 * Cancellation is a one-way door — every `ChallengeStatus::isTerminal()` state
 * is out of reach — and it belongs to the creator or a platform admin. Both
 * refusals are this one class because every caller answers them the same way:
 * tell the human it did not happen, and nothing else moved.
 */
final class ChallengeNotCancellable extends RuntimeException
{
    public static function terminal(Challenge $challenge): self
    {
        return new self(
            "Challenge {$challenge->getKey()} is {$challenge->status->value} and can no longer be cancelled.",
        );
    }

    public static function notPermitted(Challenge $challenge): self
    {
        return new self(
            "Challenge {$challenge->getKey()} may only be cancelled by its creator or a platform admin.",
        );
    }
}
