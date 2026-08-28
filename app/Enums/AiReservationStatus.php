<?php

namespace App\Enums;

/**
 * Where a token reservation sits in the reserve → claim → reconcile lease.
 *
 * `released` and `reconciled` are terminal: the first means the estimate went
 * back (the call never happened or failed), the second means real usage was
 * written. Everything else is live work a budget check must still count.
 */
enum AiReservationStatus: string
{
    case Queued = 'queued';
    case Started = 'started';
    case Completed = 'completed';
    case Failed = 'failed';
    case Released = 'released';
    case Reconciled = 'reconciled';

    public function isTerminal(): bool
    {
        return $this === self::Released || $this === self::Reconciled;
    }
}
