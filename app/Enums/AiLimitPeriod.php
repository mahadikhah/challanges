<?php

namespace App\Enums;

/**
 * The window an account's own token limits are measured over.
 *
 * Boundaries are local to the account's `limit_timezone`, not UTC — a "daily"
 * limit that resets at UTC midnight is wrong for everyone not on UTC.
 */
enum AiLimitPeriod: string
{
    case Daily = 'daily';
    case Monthly = 'monthly';
}
