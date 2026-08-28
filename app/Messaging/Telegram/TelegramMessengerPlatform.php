<?php

namespace App\Messaging\Telegram;

use App\Enums\MessagingPlatform;
use App\Messaging\Contracts\MessengerException;
use App\Messaging\Contracts\MessengerPlatform;
use App\Messaging\DTO\BotUpdate;
use App\Messaging\DTO\ChatMemberSnapshot;
use App\Messaging\DTO\SentMessage;
use App\Services\Telegram\BotIdentity;
use Closure;
use Illuminate\Support\Facades\Http;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramSDKException;
use Telegram\Bot\FileUpload\InputFile;
use Throwable;

/**
 * `MessengerPlatform` for Telegram — the reference implementation.
 *
 * Everything here existed before the interface, scattered across services and
 * actions as direct `Api` calls; this class gathers those calls behind intent
 * names so shared code can talk to a platform without naming one. The wire
 * details it absorbs are exactly the ones the old call sites carried inline:
 *
 * - `reply_markup` is JSON-serialized where Telegram wants a serialized object
 *   (the SDK passes params through as form fields and does not serialize it).
 * - Photos upload from bytes via `InputFile::createFromContents`, never from a
 *   path — the bytes are on our disk, not at a URL Telegram can fetch.
 * - Optional booleans (`is_member`, `can_post_messages`) arrive absent, not
 *   false, and stay `null` when absent.
 * - `createInvoiceLink` passes `provider_token: ''` — for Stars, an empty
 *   string, not null and not omitted (CLAUDE.md, verified against
 *   core.telegram.org).
 * - `refundStarPayment` has no SDK wrapper in 3.16, so it travels as a raw
 *   `post` over the same fakeable transport as every other call.
 * - `botId` delegates to `BotIdentity`'s memoised `getMe` rather than paying
 *   for one per call, and file downloads to `TelegramFileDownloader`, which
 *   owns the `getFile` + token-keyed-URL pair.
 *
 * Failures from the SDK are wrapped in `MessengerException` — which *extends*
 * `TelegramSDKException`, so existing catches keep working — with the original
 * preserved as the previous exception.
 */
class TelegramMessengerPlatform implements MessengerPlatform
{
    public function __construct(
        private readonly Api $telegram,
        private readonly BotIdentity $identity,
    ) {}

    public function platform(): MessagingPlatform
    {
        return MessagingPlatform::Telegram;
    }

    public function normalizeUpdate(array $payload): ?BotUpdate
    {
        if (isset($payload['callback_query']) && is_array($payload['callback_query'])) {
            return $this->fromCallbackQuery($payload, $payload['callback_query']);
        }

        foreach (['message', 'edited_message', 'channel_post'] as $kind) {
            if (isset($payload[$kind]) && is_array($payload[$kind])) {
                return $this->fromMessage($payload, $payload[$kind]);
            }
        }

        // Kinds the shared fields do not describe — pre_checkout_query,
        // my_chat_member, chat_member — reach their handlers through `raw`
        // exactly as before; this method's job is the common shape, not
        // re-homing every payload.
        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $query
     */
    private function fromCallbackQuery(array $payload, array $query): BotUpdate
    {
        $from = is_array($query['from'] ?? null) ? $query['from'] : [];
        $chatId = $query['message']['chat']['id'] ?? null;

        return new BotUpdate(
            platform: $this->platform(),
            platformUserId: (int) ($from['id'] ?? 0),
            chatId: (int) ($chatId ?? ($from['id'] ?? 0)),
            callbackData: is_string($query['data'] ?? null) ? $query['data'] : null,
            raw: $payload,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $message
     */
    private function fromMessage(array $payload, array $message): BotUpdate
    {
        $from = is_array($message['from'] ?? null) ? $message['from'] : [];
        $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
        $voice = is_array($message['voice'] ?? null) ? $message['voice'] : [];
        $forwardFromChat = is_array($message['forward_from_chat'] ?? null) ? $message['forward_from_chat'] : null;

        return new BotUpdate(
            platform: $this->platform(),
            platformUserId: (int) ($from['id'] ?? 0),
            chatId: (int) ($chat['id'] ?? 0),
            text: is_string($message['text'] ?? null) ? $message['text'] : null,
            photoFileId: $this->largestPhotoFileId($message['photo'] ?? null),
            voiceFileId: is_string($voice['file_id'] ?? null) ? $voice['file_id'] : null,
            voiceDuration: is_int($voice['duration'] ?? null) ? $voice['duration'] : null,
            forwardedChatId: $forwardFromChat !== null && is_int($forwardFromChat['id'] ?? null)
                ? $forwardFromChat['id']
                : null,
            raw: $payload,
        );
    }

    /**
     * Telegram sends photos as a ladder of sizes; the largest is the one worth
     * keeping, because it is the one a creator zooms into when deciding whether
     * the proof counts.
     */
    private function largestPhotoFileId(mixed $photo): ?string
    {
        if (! is_array($photo)) {
            return null;
        }

        $largest = collect($photo)
            ->filter(fn (mixed $size): bool => is_array($size) && is_string($size['file_id'] ?? null))
            ->sortByDesc(fn (array $size): int => (int) ($size['width'] ?? 0))
            ->first();

        // The filter above only passes sizes with a string `file_id`, so the
        // survivor's id is safe to read directly.
        return $largest['file_id'] ?? null;
    }

    /**
     * @param  list<list<array<string, string>>>|null  $inlineKeyboard
     */
    public function sendMessage(int|string $chatId, string $text, ?array $inlineKeyboard = null): SentMessage
    {
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
        ];

        if ($inlineKeyboard !== null) {
            // The SDK passes params straight through as form fields and does not
            // serialise this one, and Telegram documents `reply_markup` as a
            // JSON-serialized object — form-encoding the nested array would be
            // rejected.
            $params['reply_markup'] = json_encode(
                ['inline_keyboard' => $inlineKeyboard],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            );
        }

        return $this->sent(fn (): int => (int) $this->telegram->sendMessage($params)->get('message_id'));
    }

    public function sendPhoto(int $chatId, string $bytes, string $filename, array $captionLines): SentMessage
    {
        $caption = implode("\n\n", array_filter(
            $captionLines,
            static fn (?string $line): bool => $line !== null && trim($line) !== '',
        ));

        return $this->sent(fn (): int => (int) $this->telegram->sendPhoto([
            'chat_id' => $chatId,
            // From contents rather than a path: the bytes are on our disk, not
            // at a URL Telegram can fetch, and the SDK's path-flavoured factory
            // would look for a file named after them.
            'photo' => InputFile::createFromContents($bytes, $filename),
            'caption' => $caption,
        ])->get('message_id'));
    }

    public function answerCallbackQuery(string $callbackQueryId): void
    {
        try {
            $this->telegram->answerCallbackQuery(['callback_query_id' => $callbackQueryId]);
        } catch (TelegramSDKException $failure) {
            throw MessengerException::fromTelegram($failure);
        }
    }

    public function getChatMember(int|string $chatId, int $platformUserId): ChatMemberSnapshot
    {
        try {
            $member = $this->telegram->getChatMember([
                'chat_id' => $chatId,
                'user_id' => $platformUserId,
            ]);
        } catch (TelegramSDKException $failure) {
            throw MessengerException::fromTelegram($failure);
        }

        // Read through the collection rather than the SDK's magic `__get`,
        // which returns mixed and snake-cases behind the scenes; the shape
        // Telegram documents is `status` plus, for a restriction, `is_member`.
        return new ChatMemberSnapshot(
            status: (string) $member->get('status'),
            isMember: $this->flag($member->get('is_member')),
            canPostMessages: $this->flag($member->get('can_post_messages')),
        );
    }

    public function botId(): int
    {
        try {
            return $this->identity->id();
        } catch (TelegramSDKException $failure) {
            throw MessengerException::fromTelegram($failure);
        }
    }

    public function downloadFile(string $fileId): string
    {
        try {
            // `getFile` answers with a path relative to the bot's own file
            // store; the bytes live at that path under a URL keyed by the
            // token, which is why the token never appears in anything we
            // persist.
            $file = $this->telegram->getFile(['file_id' => $fileId]);
        } catch (TelegramSDKException $failure) {
            throw MessengerException::fromTelegram($failure);
        }

        $filePath = $file->get('file_path');

        if (! is_string($filePath) || $filePath === '') {
            throw new MessengerException('getFile returned a file with no file_path.');
        }

        $bytes = Http::get(self::FILE_URL.$this->telegram->getAccessToken().'/'.$filePath)->body();

        if ($bytes === '') {
            throw new MessengerException("The file at {$filePath} could not be downloaded.");
        }

        return $bytes;
    }

    /**
     * `getFile` answers with a path relative to the bot's own file store; the
     * bytes live at that path under a URL keyed by the token.
     */
    private const FILE_URL = 'https://api.telegram.org/file/bot';

    public function answerPreCheckoutQuery(string $preCheckoutQueryId, bool $ok, string $errorMessage = ''): void
    {
        try {
            $params = [
                'pre_checkout_query_id' => $preCheckoutQueryId,
                'ok' => $ok,
            ];

            if ($errorMessage !== '') {
                $params['error_message'] = $errorMessage;
            }

            $this->telegram->answerPreCheckoutQuery($params);
        } catch (TelegramSDKException $failure) {
            throw MessengerException::fromTelegram($failure);
        }
    }

    /**
     * @param  list<array{label: string, amount: int}>  $prices
     */
    public function createInvoiceLink(
        string $title,
        string $description,
        string $payload,
        string $currency,
        array $prices,
    ): string {
        try {
            $link = $this->telegram->createInvoiceLink([
                'title' => $title,
                'description' => $description,

                // Bot-defined, opaque to the user, and what ties a later
                // `successful_payment` back to this row.
                'payload' => $payload,

                // CLAUDE.md, verified against core.telegram.org: XTR is the
                // Stars currency tag, and the provider token is an *empty
                // string* for Stars — not null, not omitted.
                'currency' => $currency,
                'provider_token' => '',

                'prices' => $prices,
            ]);
        } catch (TelegramSDKException $failure) {
            throw MessengerException::fromTelegram($failure);
        }

        if ($link === '') {
            throw new MessengerException('createInvoiceLink returned no usable link.');
        }

        return $link;
    }

    public function refundPayment(string $platformPaymentChargeId, int $platformUserId): void
    {
        try {
            // The SDK (3.16) has no wrapper for this method, so it travels as a
            // raw post — over the same fakeable transport as every other call.
            $this->telegram->post('refundStarPayment', [
                'user_id' => $platformUserId,
                'telegram_payment_charge_id' => $platformPaymentChargeId,
            ]);
        } catch (TelegramSDKException $failure) {
            throw MessengerException::fromTelegram($failure);
        }
    }

    /**
     * Run one send and lift its message id out.
     *
     * @param  Closure(): int  $send
     */
    private function sent(Closure $send): SentMessage
    {
        try {
            return new SentMessage($send());
        } catch (TelegramSDKException $failure) {
            throw MessengerException::fromTelegram($failure);
        } catch (Throwable $failure) {
            // `json_encode` under `JSON_THROW_ON_ERROR`, or anything else the
            // SDK throws that is not a refusal — still a send that did not
            // happen, and callers catch one family.
            throw new MessengerException($failure->getMessage(), previous: $failure);
        }
    }

    /**
     * Telegram's optional booleans arrive as absent, not as false.
     */
    private function flag(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }
}
