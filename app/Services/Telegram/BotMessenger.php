<?php

namespace App\Services\Telegram;

use App\Messaging\Contracts\MessengerException;
use App\Messaging\Contracts\MessengerPlatform;
use App\Messaging\DTO\SentMessage;
use App\Messaging\PlatformRegistry;
use App\Models\User;
use App\Services\Localization;
use Illuminate\Support\Facades\Lang;
use LogicException;

/**
 * Talks to one user, in that user's own language, on that user's own messenger.
 *
 * The reason this exists rather than call sites reaching for a platform client
 * and `__()` directly is the locale. A handler runs in a queue worker that
 * serves everybody, so `app()->getLocale()` is whoever was processed last — a
 * Farsi user would get whatever language the previous update happened to leave
 * behind. Every line sent from here resolves the locale **per recipient**,
 * exactly as `IssueCheckInPhrase` does for phrases.
 *
 * The chat id is resolved from our own `users` row and never from the update
 * payload. That is not paranoia about a typo: a payload-supplied chat id would
 * mean a crafted update could have the bot deliver one user's private reply into
 * another user's chat.
 *
 * **Plain text, no `parse_mode`.** Messages interpolate names, challenge titles
 * and invite codes — all user-supplied — and under HTML or Markdown an unescaped
 * `<` or `_` turns a reply into a Bot API error or, worse, into markup the sender
 * chose. Formatting can be added later behind a helper that escapes; it is not
 * worth a silent class of broken messages now.
 *
 * Sends go through the `MessengerPlatform` seam, resolved from the recipient's
 * stored `platform` — a Bale user is replied to by the Bale bot, a Telegram
 * user by the Telegram bot, with no caller having to care which.
 */
class BotMessenger
{
    public function __construct(
        private readonly PlatformRegistry $platforms,
        private readonly Localization $localization,
    ) {}

    /**
     * The locale this user reads.
     *
     * `locale` is what they chose; `language_code` is what their Telegram client
     * reports. Preference first, client second, configured fallback last.
     */
    public function localeFor(User $user): string
    {
        return $this->localization->best($user->locale, $user->language_code);
    }

    /**
     * One translated line, in the recipient's language rather than the ambient one.
     *
     * @param  array<string, string|int|float>  $replace
     */
    public function line(User $user, string $key, array $replace = []): string
    {
        $line = Lang::get($key, $replace, $this->localeFor($user));

        // A missing key comes back as the key itself, which is ugly but visible.
        // An array comes back when a group is asked for instead of a line, and
        // that would otherwise become "Array" inside a message.
        return is_string($line) ? $line : $key;
    }

    /**
     * Several translated lines as one message, blank-line separated.
     *
     * One send rather than one per line, because messengers allow roughly a
     * message a second per chat and a burst of three is how a reply gets
     * silently dropped with a 429.
     *
     * @param  list<string|null>  $lines  nulls are dropped, so a caller can build
     *                                    conditionally without filtering first
     * @param  list<list<array<string, string>>>|null  $inlineKeyboard  rows of buttons
     */
    public function paragraphs(User $user, array $lines, ?array $inlineKeyboard = null): SentMessage
    {
        $text = implode("\n\n", array_filter(
            $lines,
            static fn (?string $line): bool => $line !== null && trim($line) !== '',
        ));

        return $this->send($user, $text, $inlineKeyboard);
    }

    /**
     * Send text to the user's private chat with the bot.
     *
     * @param  list<list<array<string, string>>>|null  $inlineKeyboard  rows of buttons
     *
     * @throws LogicException when the user has no messenger identity to message
     * @throws MessengerException when the platform refuses or cannot be reached
     */
    public function send(User $user, string $text, ?array $inlineKeyboard = null): SentMessage
    {
        return $this->platformFor($user)->sendMessage($this->chatId($user), $text, $inlineKeyboard);
    }

    /**
     * Send one media message to the user's private chat, keyboard optional.
     *
     * The bytes travel rather than a platform file id: the platform's id is
     * short-lived and per-bot, and `proof_path` is this codebase's one storage
     * convention — a second one pointing at somebody else's storage would be a
     * second source of truth for where a proof lives.
     *
     * @param  string  $kind  `CheckIn::proofKind()`'s vocabulary —
     *                        `image`, `voice` or `video`. Anything else is a
     *                        caller bug, not a user-facing state, so it refuses
     *                        rather than guessing at a field.
     * @param  string  $filename  names the upload; the platform reads its
     *                            extension to pick a content type
     * @param  list<string|null>  $captionLines  blank-line separated, nulls dropped
     * @param  list<list<array<string, string>>>|null  $inlineKeyboard  rows of buttons
     *
     * @throws LogicException when the user has no messenger identity, or `$kind` is not one of the three
     * @throws MessengerException when the platform refuses or cannot be reached
     */
    public function sendMedia(
        User $user,
        string $kind,
        string $bytes,
        string $filename,
        array $captionLines,
        ?array $inlineKeyboard = null,
    ): SentMessage {
        $platform = $this->platformFor($user);
        $chatId = $this->chatId($user);

        return match ($kind) {
            'image' => $platform->sendPhoto($chatId, $bytes, $filename, $captionLines, $inlineKeyboard),
            'voice' => $platform->sendVoice($chatId, $bytes, $filename, $captionLines, $inlineKeyboard),
            'video' => $platform->sendVideo($chatId, $bytes, $filename, $captionLines, $inlineKeyboard),
            default => throw new LogicException("{$kind} is not a media kind the bot can send."),
        };
    }

    /**
     * The platform this user lives on.
     */
    public function platformFor(User $user): MessengerPlatform
    {
        return $this->platforms->for($user->platform);
    }

    /**
     * A private chat's id is the user's own platform id.
     *
     * @throws LogicException
     */
    private function chatId(User $user): int
    {
        $platformUserId = $user->platform_user_id;

        if ($platformUserId === null) {
            // An admin who signs in by email has no messenger identity. Reaching
            // here means a bot path was handed a web user, which is a wiring bug.
            throw new LogicException(
                "User {$user->getKey()} has no platform_user_id, so the bot cannot message them."
            );
        }

        return $platformUserId;
    }
}
