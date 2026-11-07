<?php

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use App\Enums\Contracts\HasTranslatedLabel as HasTranslatedLabelContract;

/**
 * A participant's standing within one challenge.
 *
 * Note what is *not* here: there is no status for "failed". Missing a period
 * with no freeze left resets the streak to 0 and the participant stays
 * `Active` — they may have spent a friend's invite on that slot, and burning it
 * for one bad day is the wrong trade. `Removed` exists for moderation, not for
 * repeated misses.
 */
enum ParticipantStatus: string implements HasTranslatedLabelContract
{
    use HasTranslatedLabel;

    /**
     * In the challenge and owing check-ins.
     */
    case Active = 'active';

    /**
     * Saw the challenge through to its last period.
     */
    case Completed = 'completed';

    /**
     * Quit voluntarily.
     */
    case Left = 'left';

    /**
     * Taken out by the creator or an admin.
     */
    case Removed = 'removed';

    /**
     * Whether this participant should be given periods to check into, counted in
     * progress, and sent reminders.
     */
    public function owesCheckIns(): bool
    {
        return $this === self::Active;
    }

    /**
     * Whether the participation is over.
     */
    public function isTerminal(): bool
    {
        return $this !== self::Active;
    }
}
