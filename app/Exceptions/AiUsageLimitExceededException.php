<?php

namespace App\Exceptions;

use App\Enums\AiLimitScope;
use App\Services\Ai\AiLimitDecision;
use RuntimeException;

/**
 * A reservation was refused by a budget check, before any provider call.
 *
 * Carries the decision so callers can tell "this account is tapped, a sibling
 * may still have room" (`blocksGlobalBudget() === false`) from "the app-wide
 * budget is spent, no sibling can help" — continue vs abort.
 */
class AiUsageLimitExceededException extends RuntimeException
{
    public function __construct(
        public readonly AiLimitDecision $decision,
        public readonly int $accountId,
    ) {
        parent::__construct('The AI usage limit for this operation was exceeded.');
    }

    public function blocksGlobalBudget(): bool
    {
        return in_array(AiLimitScope::Global, $this->decision->bindingScopes(), true);
    }
}
