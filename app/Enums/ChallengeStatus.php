<?php

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use App\Enums\Contracts\HasTranslatedLabel as HasTranslatedLabelContract;

/**
 * Where a challenge is in its own lifecycle.
 *
 * There is deliberately no `draft`: a half-built challenge lives in
 * `BotConversation` (or the Mini App's local form state) until the wizard
 * finishes, so a `challenges` row is only ever written complete. That keeps
 * "rows in this table are real challenges" true, which every listing,
 * announcement and reminder query depends on.
 */
enum ChallengeStatus: string implements HasTranslatedLabelContract
{
    use HasTranslatedLabel;

    /**
     * Created, but `starts_at` is still in the future.
     */
    case Scheduled = 'scheduled';

    /**
     * Running: periods are open and check-ins are expected.
     */
    case Active = 'active';

    /**
     * Every period has elapsed.
     */
    case Completed = 'completed';

    /**
     * Stopped early by its creator or an admin.
     */
    case Cancelled = 'cancelled';

    /**
     * Whether new participants may still join.
     *
     * Joining a running challenge is allowed on purpose — late joiners catch up
     * on the shared timeline rather than getting a personal clock.
     */
    public function acceptsJoins(): bool
    {
        return in_array($this, [self::Scheduled, self::Active], true);
    }

    /**
     * Whether check-ins are expected right now.
     */
    public function acceptsCheckIns(): bool
    {
        return $this === self::Active;
    }

    /**
     * Whether the challenge is over, one way or another. Terminal statuses are
     * never transitioned out of.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], true);
    }
}
