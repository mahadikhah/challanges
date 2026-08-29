<?php

namespace App\Services\Telegram\Handlers;

use App\Actions\Challenges\ComposeLeaderboard;
use App\Enums\SettingKey;
use App\Jobs\Telegram\PostDailyLeaderboard;
use App\Messaging\Contracts\MessengerException;
use App\Messaging\PlatformRegistry;
use App\Models\ChallengeChat;
use App\Models\TelegramUpdate;
use App\Services\Localization;
use App\Services\Settings;
use App\Services\Telegram\BotCommand;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Commands typed inside a linked challenge chat.
 *
 * The group half of the bot's message surface, and deliberately the smallest
 * surface there is: one command, `/leaderboard`, and everything else is
 * ignored. A chat is an audience, not a user — nothing here resolves a
 * `users` row, opens a conversation, or answers free text, because a group
 * message has no one author the platform could hold a conversation with.
 *
 * **Authorization is the chat's, not the platform's.** Any administrator of
 * the chat may ask for the board — `getChatMember` against the sender's own
 * Telegram id, judged by Telegram — not just the creator who linked it. The
 * id is read from the payload and used for exactly this one question to
 * Telegram about that same person: Telegram's answer is the authority, and
 * the id never becomes a platform identity or anything else.
 *
 * **The reply is rate-limited per chat, not per caller.** One board every
 * `ChatCommandCooldownSeconds` however many admins ask, because the command
 * posts into a shared audience — and a cooldown answered with "try again in
 * N minutes" beats silence, and beats a 429.
 */
class LinkedChatHandler
{
    public function __construct(
        private readonly PlatformRegistry $platforms,
        private readonly Settings $settings,
        private readonly Localization $localization,
        private readonly ComposeLeaderboard $boards,
    ) {}

    public function handle(TelegramUpdate $update): void
    {
        $chatId = $update->value('message.chat.id');

        if (! is_int($chatId)) {
            return;
        }

        /** @var ChallengeChat|null $chat */
        $chat = ChallengeChat::query()
            ->active()
            ->where('platform', $update->platform)
            ->where('telegram_chat_id', $chatId)
            ->first();

        if ($chat === null) {
            // A group the bot is in but nobody linked — most of them. Not an
            // error, and emphatically not something to reply to.
            Log::info('Ignoring a message from a chat no challenge has linked.', [
                'update_id' => $update->update_id,
                'platform' => $update->platform->value,
                'chat_id' => $chatId,
            ]);

            return;
        }

        $command = BotCommand::parse($this->text($update));

        if ($command === null || $command->name !== 'leaderboard') {
            return;
        }

        $this->leaderboard($update, $chat);
    }

    /**
     * The on-demand board: admin-only, cooled-down, posted into the chat.
     *
     * This send is synchronous inside the update's queue job, exactly like
     * every private-chat command's reply — the rate limiter is what keeps it
     * from becoming a fan-out, so the staggering convention has nothing to
     * police here.
     */
    private function leaderboard(TelegramUpdate $update, ChallengeChat $chat): void
    {
        $senderId = $update->value('message.from.id');

        if (! is_int($senderId)) {
            return;
        }

        // The one question this surface ever asks the platform about a person:
        // are they an administrator of the chat they just typed in. The
        // answer authorizes the reply; nothing else is done with the id.
        // The chat's own platform answers, because the sender id is only
        // meaningful to the messenger that issued it.
        if (! $this->platforms->for($chat->platform)->getChatMember($chat->telegram_chat_id, $senderId)->isAdmin()) {
            $this->reply($chat, 'bot.chatpost.leaderboard.not_admin');

            return;
        }

        RateLimiter::attempt(
            key: "leaderboard:{$chat->getKey()}",
            maxAttempts: 1,
            callback: fn (): bool => $this->dispatchBoard($chat),
            decaySeconds: max(1, $this->settings->integer(SettingKey::ChatCommandCooldownSeconds)),
        ) || $this->reply($chat, 'bot.chatpost.leaderboard.cooldown', [
            'minutes' => max(1, (int) ceil(
                RateLimiter::availableIn("leaderboard:{$chat->getKey()}") / 60,
            )),
        ]);
    }

    /**
     * Queue the board through the same job the daily sweep uses.
     *
     * The job's own `(chat, kind, date)` claim means an on-demand ask the
     * daily post already satisfied is a quiet no-op — one board per chat per
     * day, whichever route asked first. Returns false when there is nothing
     * to show yet, so the caller can say that instead of nothing.
     */
    private function dispatchBoard(ChallengeChat $chat): bool
    {
        $lines = $this->boards->handle($chat->challenge, $this->settings->integer(SettingKey::LeaderboardTopSize));

        if ($lines === null) {
            $this->reply($chat, 'bot.chatpost.leaderboard.empty');

            return true;
        }

        $date = now()->timezone($chat->challenge->timezone)->toDateString();

        PostDailyLeaderboard::dispatch($chat->getKey(), $date);

        return true;
    }

    /**
     * Say one line into the chat, in the platform's fallback locale — the
     * same choice every linked-chat post makes, for the same reason: a chat
     * has a mixed-language audience.
     *
     * @param  array<string, string|int|float>  $replace
     */
    private function reply(ChallengeChat $chat, string $key, array $replace = []): void
    {
        $line = Lang::get($key, $replace, $this->localization->fallback());

        try {
            $this->platforms->for($chat->platform)->sendMessage($chat->telegram_chat_id, is_string($line) ? $line : $key);
        } catch (MessengerException $unsendable) {
            Log::info('A linked-chat reply could not be sent.', [
                'challenge_chat_id' => $chat->getKey(),
                'key' => $key,
                'reason' => $unsendable->getMessage(),
            ]);
        }
    }

    /**
     * The message text, if this message has any.
     */
    private function text(TelegramUpdate $update): ?string
    {
        $text = $update->value('message.text');

        return is_string($text) ? $text : null;
    }
}
