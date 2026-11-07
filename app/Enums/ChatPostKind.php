<?php

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use App\Enums\Contracts\HasTranslatedLabel as HasTranslatedLabelContract;

/**
 * What a `ChallengeChatPost` row says was posted.
 *
 * The kind is part of both idempotency keys — `(chat, period, participant)`
 * and `(chat, date)` — so adding a case here is how a new broadcast becomes
 * safe to post exactly once.
 */
enum ChatPostKind: string implements HasTranslatedLabelContract
{
    use HasTranslatedLabel;

    /** One participant's check-in landed. */
    case CheckInAnnouncement = 'checkin_announcement';

    /** The day's leaderboard. */
    case DailyLeaderboard = 'daily_leaderboard';
}
