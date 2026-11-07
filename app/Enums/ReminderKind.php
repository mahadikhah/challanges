<?php

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use App\Enums\Contracts\HasTranslatedLabel as HasTranslatedLabelContract;

/**
 * Which nudge a `ReminderDispatch` row represents.
 *
 * The kind is part of the idempotency key `(participant, period, kind)`, so
 * adding a case here is how a new nudge becomes safe to send exactly once.
 */
enum ReminderKind: string implements HasTranslatedLabelContract
{
    use HasTranslatedLabel;

    /** The challenge is about to begin. Sent once, against period 0. */
    case ChallengeStarting = 'challenge_starting';

    /** A new period is open and a check-in is due. */
    case PeriodOpened = 'period_opened';

    /** The period closes soon and nothing has been submitted. */
    case PeriodEnding = 'period_ending';

    /**
     * Whether this nudge should be suppressed once the participant has an
     * approved check-in for the period — there is no point telling someone their
     * period is ending when they are already done.
     */
    public function skipWhenSettled(): bool
    {
        return $this === self::PeriodEnding;
    }
}
