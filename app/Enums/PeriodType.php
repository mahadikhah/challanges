<?php

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use App\Enums\Contracts\HasTranslatedLabel as HasTranslatedLabelContract;

/**
 * How often a challenge demands a check-in.
 *
 * The date arithmetic deliberately does not live here: boundaries have to be
 * computed in the *challenge's* timezone and only then stored as UTC, so it
 * belongs with the period materialiser that owns that conversion. This enum
 * carries what is intrinsic to the type — its name, and whether it needs a
 * companion day count.
 */
enum PeriodType: string implements HasTranslatedLabelContract
{
    use HasTranslatedLabel;

    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Seasonal = 'seasonal';
    case Yearly = 'yearly';
    case Custom = 'custom';

    /**
     * Whether `Challenge::$custom_period_days` must be present.
     */
    public function requiresCustomDays(): bool
    {
        return $this === self::Custom;
    }
}
