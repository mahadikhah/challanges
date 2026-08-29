<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A debit was refused because it would have taken the balance below zero.
 *
 * Carries the numbers rather than only a message, so a bot reply can say
 * "you need 50 and have 20" in the user's own language without re-reading the
 * ledger.
 */
class InsufficientCoinsException extends RuntimeException
{
    public function __construct(
        public readonly int $balance,
        public readonly int $required,
    ) {
        parent::__construct("Insufficient coins: balance {$balance}, required {$required}.");
    }

    public function shortfall(): int
    {
        return max(0, $this->required - $this->balance);
    }
}
