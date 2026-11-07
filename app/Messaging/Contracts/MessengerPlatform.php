<?php

namespace App\Messaging\Contracts;

use App\Enums\MessagingPlatform;
use App\Messaging\DTO\BotUpdate;
use App\Messaging\DTO\ChatMemberSnapshot;
use App\Messaging\DTO\PaymentTransaction;
use App\Messaging\DTO\SentMessage;

/**
 * The one way shared code talks to a messenger platform.
 *
 * The method set is exactly what the codebase calls today — nothing
 * speculative (no `editMessageText` or `getChat`: nothing calls them yet;
 * they join when a caller does). Each method takes named, typed parameters
 * describing *intent*, so a second platform (Bale) implements the intent
 * rather than Telegram's wire format.
 *
 * Semantics every implementation must hold:
 *
 * - **Plain text, no `parse_mode`.** Messages interpolate user-supplied names
 *   and titles; under markup an unescaped character turns a reply into an
 *   error or into attacker-chosen formatting (see `BotMessenger`).
 * - **Inline keyboards are rows of buttons** (`list<list<InlineButton>>`),
 *   JSON-encoded by the implementation where the wire wants a serialized
 *   `reply_markup` — callers never build wire formats.
 * - **Throws `MessengerException` (or a subclass) on refusal or transport
 *   failure**, so shared code catches one family, not one exception per SDK.
 * - **`getChatMember` returns a normalized snapshot**, never a platform
 *   collection: callers compare `status`/`isMember`/`canPostMessages`, which
 *   is all anyone reads today.
 *
 * Resolution discipline: callers receive the platform instance matching the
 * actor — the webhook resolves it from the incoming update, jobs from the
 * recipient's stored `users.platform`. Never assume a singleton default.
 */
interface MessengerPlatform
{
    /**
     * Which platform this instance talks to.
     */
    public function platform(): MessagingPlatform;

    /**
     * Normalize one raw update payload into the shared shape.
     *
     * @param  array<string, mixed>  $payload  the update exactly as the platform delivered it
     */
    public function normalizeUpdate(array $payload): ?BotUpdate;

    /**
     * Send a text message into a chat.
     *
     * @param  int|string  $chatId  numeric chat ids, and
     *                              `@username` channels where
     *                              the platform accepts them
     * @param  list<list<array<string, string>>>|null  $inlineKeyboard  rows of buttons
     *                                                                  (text + url or callback data)
     *
     * @throws MessengerException when the platform refuses or cannot be reached
     */
    public function sendMessage(int|string $chatId, string $text, ?array $inlineKeyboard = null): SentMessage;

    /**
     * Send a photo (raw bytes) with a caption into a chat.
     *
     * @param  list<string|null>  $captionLines  blank-line separated, nulls dropped
     * @param  list<list<array<string, string>>>|null  $inlineKeyboard  rows of buttons;
     *                                                                  they ride on the media message rather than following it, because the platform allows roughly a message a second per chat and a second send is the one that gets refused
     *
     * @throws MessengerException
     */
    public function sendPhoto(
        int $chatId,
        string $bytes,
        string $filename,
        array $captionLines,
        ?array $inlineKeyboard = null,
    ): SentMessage;

    /**
     * Send a voice message (raw bytes) with a caption into a chat.
     *
     * The caller decides what is voice by the same extension mapping the
     * upload pipeline uses (`CheckIn::proofKind()`); this method uploads bytes
     * and never inspects the container.
     *
     * @param  list<string|null>  $captionLines  blank-line separated, nulls dropped
     * @param  list<list<array<string, string>>>|null  $inlineKeyboard  rows of buttons
     *
     * @throws MessengerException
     */
    public function sendVoice(
        int $chatId,
        string $bytes,
        string $filename,
        array $captionLines,
        ?array $inlineKeyboard = null,
    ): SentMessage;

    /**
     * Send a video (raw bytes) with a caption into a chat.
     *
     * @param  list<string|null>  $captionLines  blank-line separated, nulls dropped
     * @param  list<list<array<string, string>>>|null  $inlineKeyboard  rows of buttons
     *
     * @throws MessengerException
     */
    public function sendVideo(
        int $chatId,
        string $bytes,
        string $filename,
        array $captionLines,
        ?array $inlineKeyboard = null,
    ): SentMessage;

    /**
     * Acknowledge a tapped inline button (stops the client's spinner).
     *
     * Best-effort by design: cosmetic, and the id may already be stale on a
     * retry. Implementations still throw on transport failure; callers decide
     * whether to swallow.
     *
     * @throws MessengerException
     */
    public function answerCallbackQuery(string $callbackQueryId): void;

    /**
     * Look up one user's standing in one chat.
     *
     * @param  int|string  $chatId  numeric ids, and `@username` channels where
     *                              the platform accepts them
     *
     * @throws MessengerException
     */
    public function getChatMember(int|string $chatId, int $platformUserId): ChatMemberSnapshot;

    /**
     * The bot's own platform user id.
     *
     * @throws MessengerException
     */
    public function botId(): int;

    /**
     * Fetch a file's bytes by the platform's file id.
     *
     * @param  string  $fileId  as `BotUpdate` carried it (photo ladder already resolved)
     * @return string the raw bytes
     *
     * @throws MessengerException
     */
    public function downloadFile(string $fileId): string;

    /**
     * Answer the platform's pre-checkout checkpoint for a native payment.
     *
     * The sheet hangs until answered (Bale cancels after ten seconds), so
     * implementations must always answer — a decline with `$ok = false` and a
     * reason, never silence.
     *
     * @throws MessengerException
     */
    public function answerPreCheckoutQuery(string $preCheckoutQueryId, bool $ok, string $errorMessage = ''): void;

    /**
     * Create a payment invoice link on the platform's native payment rail.
     *
     * Only rails whose payment sheet opens from a URL can honour this; a rail
     * with no payable link refuses here rather than returning something a
     * button could not open.
     *
     * @param  list<array{label: string, amount: int}>  $prices  one line for Stars invoices
     *
     * @throws MessengerException when the platform refuses or has no payable link
     */
    public function createInvoiceLink(
        string $title,
        string $description,
        string $payload,
        string $currency,
        array $prices,
    ): string;

    /**
     * Send a payment invoice as a message into a chat.
     *
     * The currency and payment-provider credentials are facts of the platform's
     * own rail (Stars' `XTR` + empty provider token; Bale's Rial prices +
     * wallet token), resolved inside the implementation — callers state intent
     * and a price, never a wire format.
     *
     * @param  int|string  $chatId  the payer's chat, which the invoice must
     *                              already live in on rails with no payable link
     * @param  list<array{label: string, amount: int}>  $prices  amounts in the rail's own currency
     * @return SentMessage the invoice message, when the platform reports one
     *
     * @throws MessengerException when the platform refuses or cannot be reached
     */
    public function sendInvoice(
        int|string $chatId,
        string $title,
        string $description,
        string $payload,
        array $prices,
    ): SentMessage;

    /**
     * Ask the platform's rail what became of one payment.
     *
     * The `verify()` of a request-then-verify rail: what the webhook *said*
     * about money is display data until this answers. Rails that confirm a
     * payment synchronously in the payment update itself have no inquiry to
     * make and refuse here.
     *
     * @throws MessengerException when the rail has no inquiry step, or cannot be reached
     */
    public function inquireTransaction(string $transactionId): PaymentTransaction;

    /**
     * Refund a completed payment on the platform's rail.
     *
     * @throws MessengerException when the platform refuses or has no refund path
     */
    public function refundPayment(string $platformPaymentChargeId, int $platformUserId): void;
}
