<?php

namespace App\Services\Telegram;

use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramSDKException;

/**
 * Who the bot is, on Telegram's own say-so.
 *
 * Verifying a linked chat asks `getChatMember(chat_id, bot_id)`, and the bot's
 * own id is not in any config file — the token names the bot, but only
 * `getMe` says the number. Asking once per process is enough: the answer
 * cannot change while the worker lives, and at ~30 Bot API calls a second
 * globally, a `getMe` per verification would double the cost of every one.
 *
 * A transport failure is not cached — `null` stays `null`, so the next caller
 * asks again rather than trusting an answer nobody ever got.
 */
class BotIdentity
{
    /**
     * The memoised answer, keyed by nothing because there is exactly one bot.
     */
    private ?int $botId = null;

    public function __construct(
        private readonly Api $telegram,
    ) {}

    /**
     * The bot's own Telegram user id.
     *
     * @throws TelegramSDKException when Telegram cannot be asked or refuses
     */
    public function id(): int
    {
        if ($this->botId !== null) {
            return $this->botId;
        }

        $me = $this->telegram->getMe();

        // Read through the collection rather than the magic `__get`, which
        // returns mixed; `id` is the one field `getMe` always carries.
        $id = $me->get('id');

        if (! is_int($id) || $id <= 0) {
            throw new TelegramSDKException('getMe returned no usable bot id.');
        }

        return $this->botId = $id;
    }
}
