<?php

namespace App\Console\Commands\Challenges;

use App\Enums\SettingKey;
use App\Jobs\Telegram\PostDailyLeaderboard;
use App\Models\ChallengeChat;
use App\Services\Settings;
use Illuminate\Console\Command;

/**
 * The daily leaderboard sweep: one staggered job per opted-in chat.
 *
 * Runs every minute and does nothing for 23:59 of the day, because *when* it
 * fires is not this command's to decide: the `LeaderboardHour` setting says
 * which hour, and the hour is checked **in each challenge's own timezone** —
 * a platform-wide "at nine" would post the Farsi challenges' board in the
 * middle of their night. A chat whose challenge has just entered the
 * leaderboard hour gets its job queued; the unique `(chat, kind, date)` row
 * keeps the next minute's run — or a cron that fires twice — from queuing a
 * duplicate the job would only have to skip.
 *
 * The stagger is the same convention as reminders: one second between
 * dispatches, never a synchronous loop of `sendMessage` calls.
 */
class PostDailyLeaderboardsCommand extends Command
{
    protected $signature = 'challenges:leaderboards';

    protected $description = 'Queue the daily leaderboard for chats whose challenge has entered the leaderboard hour';

    /**
     * Seconds between consecutive dispatches — the trickle rate, as reminders.
     */
    public const STAGGER_SECONDS = 1;

    public function handle(Settings $settings): int
    {
        $hour = $settings->integer(SettingKey::LeaderboardHour);
        $dispatched = 0;

        ChallengeChat::query()
            ->active()
            ->where('post_daily_leaderboard', true)
            ->with('challenge')
            ->eachById(function (ChallengeChat $chat) use ($hour, &$dispatched): void {
                // The day the board describes is the one the challenge's
                // participants are living in, not the worker's.
                $now = now()->timezone($chat->challenge->timezone);

                if ((int) $now->format('G') !== $hour) {
                    return;
                }

                dispatch(
                    (new PostDailyLeaderboard($chat->getKey(), $now->toDateString()))
                        ->delay($dispatched * self::STAGGER_SECONDS)
                );

                $dispatched++;
            });

        $this->components->twoColumnDetail('Leaderboards queued', (string) $dispatched);

        return self::SUCCESS;
    }
}
