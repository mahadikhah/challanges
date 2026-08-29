<?php

namespace App\Messaging\Bale;

use App\Enums\MessagingPlatform;
use App\Enums\PaymentTransactionStatus;
use App\Messaging\Contracts\MessengerException;
use App\Messaging\Contracts\MessengerPlatform;
use App\Messaging\DTO\BotUpdate;
use App\Messaging\DTO\ChatMemberSnapshot;
use App\Messaging\DTO\PaymentTransaction;
use App\Messaging\DTO\SentMessage;
use App\Services\Telegram\LaravelHttpClient;
use Closure;
use Illuminate\Support\Facades\Http;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramSDKException;
use Telegram\Bot\FileUpload\InputFile;
use Throwable;

/**
 * `MessengerPlatform` for Bale — the second implementation.
 *
 * Bale's Bot API is Telegram-shaped (verified against docs.bale.ai; see
 * progress-phase-11.md): same method names, same update envelopes, same
 * `InlineKeyboardMarkup`/`callback_query` mechanics, so the SDK speaks it once
 * pointed at Bale's base URL. That makes this class a sibling of
 * `TelegramMessengerPlatform`, not a subclass of it: the wire shapes coincide
 * today, but each class owns its own platform's behaviour, and a divergence
 * (Bale's payment surface already differs — Phase 11 Task 3) must be able to
 * land here without reading as a change to Telegram's.
 *
 * Bale-specific facts this absorbs:
 *
 * - The base URL goes through the Api **constructor** (`tapi.bale.ai/bot`);
 *   the SDK's `setBaseBotUrl()` mangles the `/bot` suffix. Same transport as
 *   Telegram (`LaravelHttpClient`) so `Http::fake()` sees every call.
 * - `answerCallbackQuery` must always be answered, and old Bale clients that
 *   cannot render the answer announce themselves with a callback query id
 *   starting `"1"`. We answer unconditionally and ignore the response either
 *   way, which is already the correct posture.
 * - File downloads go to `tapi.bale.ai/file/bot<token>/<file_path>`, valid
 *   one hour, 20 MB max.
 * - Bale Pay has **no payable link** — an invoice exists only as a message in
 *   a chat (`sendInvoice`), is priced in Rial with no currency parameter, and
 *   carries a wallet `provider_token` that is a different secret from the bot
 *   token. What a payment update *said* is verified with `inquireTransaction`
 *   before anything is credited. No refund method exists on the rail.
 *
 * Failures are wrapped in `MessengerException` exactly as the Telegram
 * implementation wraps them — one family for shared code to catch.
 */
class BaleMessengerPlatform implements MessengerPlatform
{
    private const BASE_URL = 'https://tapi.bale.ai/bot';

    private const FILE_URL = 'https://tapi.bale.ai/file/bot';

    private ?Api $bale = null;

    /**
     * The bot's own Bale user id, memoised — same reasoning as Telegram's
     * `BotIdentity`: the answer cannot change while the worker lives, and a
     * `getMe` per verification would double the cost of every one. A failure
     * is not cached; `null` stays `null`.
     */
    private ?int $botId = null;

    public function __construct(private readonly LaravelHttpClient $http) {}

    public function platform(): MessagingPlatform
    {
        return MessagingPlatform::Bale;
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
            // Bale documents a `Voice` object whose duration field is not
            // guaranteed; a missing duration reads as null and the timed-
            // session step treats null as "no cap to enforce" rather than as
            // a zero-second voice.
            voiceDuration: is_int($voice['duration'] ?? null) ? $voice['duration'] : null,
            forwardedChatId: $forwardFromChat !== null && is_int($forwardFromChat['id'] ?? null)
                ? $forwardFromChat['id']
                : null,
            raw: $payload,
        );
    }

    /**
     * Bale sends photos as the same ladder of sizes Telegram does; the largest
     * is the one worth keeping, for the same reason — it is the one a creator
     * zooms into when deciding whether the proof counts.
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
            // Same wire convention as Telegram: `reply_markup` is a
            // JSON-serialized object, and the SDK passes params through as
            // form fields without serializing this one.
            $params['reply_markup'] = json_encode(
                ['inline_keyboard' => $inlineKeyboard],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            );
        }

        return $this->sent(fn (): int => (int) $this->api()->sendMessage($params)->get('message_id'));
    }

    public function sendPhoto(int $chatId, string $bytes, string $filename, array $captionLines): SentMessage
    {
        $caption = implode("\n\n", array_filter(
            $captionLines,
            static fn (?string $line): bool => $line !== null && trim($line) !== '',
        ));

        return $this->sent(fn (): int => (int) $this->api()->sendPhoto([
            'chat_id' => $chatId,
            'photo' => InputFile::createFromContents($bytes, $filename),
            'caption' => $caption,
        ])->get('message_id'));
    }

    public function answerCallbackQuery(string $callbackQueryId): void
    {
        try {
            // Answered unconditionally: Bale's own docs say a callback must
            // always be answered, and the platform never surfaces the answer's
            // content to the user on clients that support it. Ids beginning
            // with "1" mark clients too old to render an answer — the call is
            // still made, since the platform tolerates it and the handler
            // treats the acknowledgement as best-effort either way.
            $this->api()->answerCallbackQuery(['callback_query_id' => $callbackQueryId]);
        } catch (TelegramSDKException $failure) {
            throw MessengerException::fromTelegram($failure);
        }
    }

    public function getChatMember(int|string $chatId, int $platformUserId): ChatMemberSnapshot
    {
        try {
            $member = $this->api()->getChatMember([
                'chat_id' => $chatId,
                'user_id' => $platformUserId,
            ]);
        } catch (TelegramSDKException $failure) {
            throw MessengerException::fromTelegram($failure);
        }

        // Bale's ChatMember types carry the same status tokens Telegram's do
        // ("creator", "administrator", "member", "restricted"), with
        // `is_member` on the restricted variant — read defensively, exactly
        // as the Telegram implementation does.
        return new ChatMemberSnapshot(
            status: (string) $member->get('status'),
            isMember: $this->flag($member->get('is_member')),
            canPostMessages: $this->flag($member->get('can_post_messages')),
        );
    }

    public function botId(): int
    {
        if ($this->botId !== null) {
            return $this->botId;
        }

        try {
            $me = $this->api()->getMe();
        } catch (TelegramSDKException $failure) {
            throw MessengerException::fromTelegram($failure);
        }

        $id = $me->get('id');

        if (! is_int($id) || $id <= 0) {
            throw new MessengerException('getMe returned no usable Bale bot id.');
        }

        return $this->botId = $id;
    }

    public function downloadFile(string $fileId): string
    {
        try {
            $file = $this->api()->getFile(['file_id' => $fileId]);
        } catch (TelegramSDKException $failure) {
            throw MessengerException::fromTelegram($failure);
        }

        $filePath = $file->get('file_path');

        if (! is_string($filePath) || $filePath === '') {
            throw new MessengerException('getFile returned a file with no file_path.');
        }

        $bytes = Http::get(self::FILE_URL.$this->api()->getAccessToken().'/'.$filePath)->body();

        if ($bytes === '') {
            throw new MessengerException("The file at {$filePath} could not be downloaded.");
        }

        return $bytes;
    }

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

            $this->api()->answerPreCheckoutQuery($params);
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
        // The bale-payments skill's trap #1: there is no payable payment link
        // on Bale. `createInvoiceLink` exists but returns an invoice *id* for
        // mini-apps to open with `openInvoice` — not a URL — so no button can
        // be built from it. An invoice lives in a chat, via `sendInvoice`.
        throw new MessengerException(
            'Bale Pay has no payable invoice link; an invoice exists only as a message sent into a chat.'
        );
    }

    /**
     * @param  list<array{label: string, amount: int}>  $prices
     */
    public function sendInvoice(
        int|string $chatId,
        string $title,
        string $description,
        string $payload,
        array $prices,
    ): SentMessage {
        try {
            return $this->sent(fn (): int => (int) $this->api()->sendInvoice([
                'chat_id' => $chatId,
                'title' => $title,
                'description' => $description,

                // Bot-defined, opaque to the user, and what ties the later
                // `successful_payment` back to this row.
                'payload' => $payload,

                // The wallet payment token from @botfather — a *different*
                // secret from the bot token this client carries (the
                // bale-payments skill's trap #2). No `currency` key at all:
                // Bale Pay prices in Rial and the method takes no currency
                // parameter.
                'provider_token' => $this->providerToken(),

                'prices' => $prices,
            ])->get('message_id'));
        } catch (TelegramSDKException $failure) {
            throw MessengerException::fromTelegram($failure);
        }
    }

    /**
     * The wallet token Bale Pay invoices are issued against, refused loudly
     * when unconfigured: an invoice without it cannot charge, and pretending
     * otherwise would sell a button that only errors.
     */
    private function providerToken(): string
    {
        $token = trim((string) config('services.bale.provider_token'));

        if ($token === '') {
            throw new MessengerException(
                'BALE_PROVIDER_TOKEN is not set, so no Bale Pay invoice can be sent.'
            );
        }

        return $token;
    }

    public function inquireTransaction(string $transactionId): PaymentTransaction
    {
        try {
            // `inquireTransaction` is absent from Telegram SDKs (the skill
            // documents this), so it travels as a raw post over the same
            // fakeable transport as every other call — exactly the posture
            // `refundStarPayment` already has on the Telegram side.
            $result = $this->api()->post('inquireTransaction', [
                'transaction_id' => $transactionId,
            ])->getResult();
        } catch (TelegramSDKException $failure) {
            throw MessengerException::fromTelegram($failure);
        }

        if (! is_array($result)) {
            throw new MessengerException('inquireTransaction returned no transaction.');
        }

        return new PaymentTransaction(
            id: is_string($result['id'] ?? null) ? $result['id'] : $transactionId,
            status: PaymentTransactionStatus::fromRail($result['status'] ?? null),

            // Rial. An amount that did not arrive as an integer stays null —
            // the caller compares against the row's price and a null amount
            // is a disagreement, which is the safe reading.
            amount: is_int($result['amount'] ?? null) ? $result['amount'] : null,
            userId: is_int($result['userID'] ?? null) ? $result['userID'] : null,
        );
    }

    public function refundPayment(string $platformPaymentChargeId, int $platformUserId): void
    {
        // Bale documents no refund method on its payment rail (verified
        // against docs.bale.ai; recorded in progress-phase-11.md). Refusing
        // loudly is the honest answer, and `StarPayment::isRefundable()`
        // hides the lever, so arriving here means a caller bypassed the
        // check — say so rather than approximate an endpoint that does not
        // exist.
        throw new MessengerException(
            'Bale Pay documents no refund path, so a Bale purchase cannot be reversed through the API.'
        );
    }

    /**
     * The SDK client against Bale's base URL, built once per process.
     *
     * The base URL travels through the constructor because the SDK's
     * `setBaseBotUrl()` strips the trailing `/bot` and would silently produce
     * `tapi.bale.ai<token>/METHOD` — the skill documents this trap.
     */
    private function api(): Api
    {
        if ($this->bale !== null) {
            return $this->bale;
        }

        $token = (string) config('services.bale.bot_token');

        if ($token === '') {
            throw new MessengerException(
                'BALE_BOT_TOKEN is not set, so no Bale Bot API request can be made.'
            );
        }

        return $this->bale = new Api(
            token: $token,
            async: false,
            httpClientHandler: $this->http,
            baseBotUrl: self::BASE_URL,
        );
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
            throw new MessengerException($failure->getMessage(), previous: $failure);
        }
    }

    /**
     * Bale's optional booleans arrive as absent, not as false.
     */
    private function flag(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }
}
