<?php

namespace App\Jobs\Telegram;

use App\Actions\Challenges\ComposeLeaderboard;
use App\Enums\ChatPostKind;
use App\Enums\SettingKey;
use App\Models\ChallengeChat;
use App\Services\Settings;
use App\Services\Telegram\ChatBroadcaster;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Post one day's leaderboard into one linked chat — once.
 *
 * Dispatched by the daily sweep (one staggered job per chat) and by the
 * on-demand `/leaderboard` command. Both carry a chat id and a date; the
 * unique `(chat, kind, date)` row is what keeps a re-run of either from
 * double-posting.
 *
 * The date is the day the board describes, carried explicitly rather than
 * read from the clock at send time: the sweep's stagger can push execution
 * past midnight, and a board labelled "today" that describes yesterday is a
 * lie the row would have prevented.
 */
class PostDailyLeaderboard implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public function __construct(
        public readonly int $chatId,
        public readonly string $date,
    ) {}

    /**
     * @throws Throwable when Telegram refuses the send, so the job retries
     */
    public function handle(ChatBroadcaster $broadcaster, ComposeLeaderboard $boards, Settings $settings): void
    {
        /** @var ChallengeChat|null $chat */
        $chat = ChallengeChat::query()->with('challenge')->find($this->chatId);

        if ($chat === null || ! $chat->is_active || ! $chat->post_daily_leaderboard) {
            return;
        }

        $lines = $boards->handle($chat->challenge, $settings->integer(SettingKey::LeaderboardTopSize));

        if ($lines === null) {
            // Nothing to celebrate yet. Nothing is claimed, so tomorrow's run
            // — or an on-demand ask once the cooldown clears — gets a real
            // answer rather than being told "already posted".
            return;
        }

        DB::transaction(function () use ($broadcaster, $chat, $lines): void {
            if (! $broadcaster->claim($chat, ChatPostKind::DailyLeaderboard->value, [
                'post_date' => $this->date,
            ])) {
                return;
            }

            try {
                $broadcaster->sendLines($chat, $lines);
            } catch (Throwable $failure) {
                $chat->posts()
                    ->where('post_kind', ChatPostKind::DailyLeaderboard)
                    ->where('post_date', $this->date)
                    ->delete();

                throw $failure;
            }
        });
    }
}
