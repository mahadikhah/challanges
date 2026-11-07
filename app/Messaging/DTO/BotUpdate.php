<?php

namespace App\Messaging\DTO;

use App\Enums\MessagingPlatform;
use Illuminate\Support\Arr;

/**
 * One incoming update, normalized across platforms.
 *
 * Everything a handler needs to act lives in the named fields; `raw` carries
 * the payload exactly as the platform sent it for anything platform-specific a
 * handler still wants to read (Telegram's full update shape, including kinds
 * this DTO has no field for, like `successful_payment`).
 *
 * Field-by-field meaning, so the Bale normalizer (Phase 11 Task 2) maps to the
 * same intent rather than to Telegram's key names:
 *
 * - `platform_user_id` — the sender's platform identity (Telegram's `from.id`).
 * - `chat_id` — the chat the update happened in; for a private chat this is
 *   the sender's own id, which is also the reply target.
 * - `text`, `callback_data` — whichever the update carries; null otherwise.
 * - `photo_file_id` — the largest photo size's file id, pre-picked the way
 *   `TelegramFileDownloader` picks it, so the downloader does not have to
 *   understand the photo ladder again.
 * - `voice_file_id`, `voice_duration` — a voice message's file id and its
 *   duration in seconds as the platform reported it.
 * - `forwarded_chat_id` — the origin chat of a forwarded message, how creator
 *   chat registration discovers a group's id (§2.6); null when the message
 *   was not forwarded.
 *
 * `raw` is untrusted display/lookup data, exactly as it arrived — same
 * discipline as `TelegramUpdate::value()`: read defensively, never execute.
 */
final readonly class BotUpdate
{
    /**
     * @param  array<string, mixed>  $raw  the platform's own update payload, verbatim
     */
    public function __construct(
        public MessagingPlatform $platform,
        public int $platformUserId,
        public int $chatId,
        public ?string $text = null,
        public ?string $callbackData = null,
        public ?string $photoFileId = null,
        public ?string $voiceFileId = null,
        public ?int $voiceDuration = null,
        public ?int $forwardedChatId = null,
        public array $raw = [],
    ) {}

    /**
     * Read a value out of the raw payload by dot path.
     *
     * The same contract as `TelegramUpdate::value()`: the payload is stored
     * verbatim, so this is how a handler reaches a platform-specific field the
     * named ones do not cover.
     */
    public function value(string $path, mixed $default = null): mixed
    {
        return Arr::get($this->raw, $path, $default);
    }

    /**
     * Whether the update happened in a private chat with the bot.
     */
    public function isPrivateChat(): bool
    {
        $type = $this->value('message.chat.type', $this->value('callback_query.message.chat.type'));

        return $type === 'private';
    }
}
