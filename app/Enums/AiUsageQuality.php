<?php

namespace App\Enums;

/**
 * How much to believe an attempt's token numbers.
 *
 * `contradictory` keeps both numbers (input+output sum and the provider's own
 * total) rather than silently picking a winner.
 */
enum AiUsageQuality: string
{
    case Reported = 'reported';
    case Estimated = 'estimated';
    case Missing = 'missing';
    case Contradictory = 'contradictory';
}
