<?php

namespace App\Enums;

/**
 * The accounting status of a usage-record row: does it count toward a budget?
 *
 * Terminal for every value except `pending` — terminal rows are append-only,
 * because they are a ledger.
 */
enum AiUsageRecordStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case NoUsage = 'no_usage';

    public function isTerminal(): bool
    {
        return $this !== self::Pending;
    }
}
