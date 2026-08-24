<?php

namespace App\Exceptions;

use App\Enums\EntitlementType;
use RuntimeException;

/**
 * A user tried to spend a slot they do not have.
 *
 * Carries the `EntitlementType` because the recovery path depends on it: the bot
 * turns this into "you have used your free challenge — buy another create slot
 * for N coins?", and it needs to know which slot and therefore which price to
 * quote. A bare message would force the caller to guess.
 */
class NoEntitlementAvailableException extends RuntimeException
{
    public function __construct(public readonly EntitlementType $type)
    {
        parent::__construct("No unconsumed {$type->value} entitlement is available.");
    }
}
