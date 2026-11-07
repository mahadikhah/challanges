<?php

namespace App\Listeners\Challenges;

use App\Events\CheckInSettled;
use App\Jobs\Telegram\PostCheckInAnnouncement;
use App\Models\ChallengeChat;

/**
 * Queue one announcement job per eligible chat when a check-in is approved.
 *
 * Runs synchronously inside `SettleCheckIn`'s transaction — a deliberately
 * cheap body: one indexed query, one job per chat, no Telegram. Everything
 * that could fail slowly lives in the job, so a settlement is never held (or,
 * worse, rolled back) by the broadcast half of what it did.
 *
 * Only `Approved` triggers an announcement. A miss or a freeze is the
 * participant's own news, and the leaderboard already covers it in aggregate.
 */
class AnnounceApprovedCheckIn
{
    public function handle(CheckInSettled $event): void
    {
        if (! $event->checkIn->status->incrementsStreak()) {
            return;
        }

        ChallengeChat::query()
            ->active()
            ->where('challenge_id', $event->checkIn->period->challenge_id)
            ->where('post_checkin_announcements', true)
            ->each(function (ChallengeChat $chat) use ($event): void {
                dispatch(new PostCheckInAnnouncement($chat->getKey(), $event->checkIn->getKey()));
            });
    }
}
