<?php

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use App\Enums\Contracts\HasTranslatedLabel as HasTranslatedLabelContract;

/**
 * Where a `CheckInSession` stands.
 *
 * Only `in_progress` owes anything: the other three are terminal bookkeeping.
 * The unique index that keeps one open session per (participant, period) is
 * derived from this status, so it relaxes itself the moment the status moves.
 */
enum CheckInSessionStatus: string implements HasTranslatedLabelContract
{
    use HasTranslatedLabel;

    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Expired = 'expired';
    case Abandoned = 'abandoned';
}
