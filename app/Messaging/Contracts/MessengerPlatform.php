<?php

namespace App\Messaging\Contracts;

use App\Enums\MessagingPlatform;
use App\Messaging\DTO\BotUpdate;
use App\Messaging\DTO\ChatMemberSnapshot;
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
     *
     * @throws MessengerException
     */
    public function sendPhoto(int $chatId, string $bytes, string $filename, array $captionLines): SentMessage;

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
     * The sheet hangs until answered, so implementations must always answer —
     * a decline with `$ok = false` and a reason, never silence.
     *
     * @throws MessengerException
     */
    public function answerPreCheckoutQuery(string $preCheckoutQueryId, bool $ok, string $errorMessage = ''): void;

    /**
     * Create a payment invoice link on the platform's native payment rail.
     *
     * @param  list<array{label: string, amount: int}>  $prices  one line for Stars invoices
     *
     * @throws MessengerException
     */
    public function createInvoiceLink(
        string $title,
        string $description,
        string $payload,
        string $currency,
        array $prices,
    ): string;

    /**
     * Refund a completed payment on the platform's rail.
     *
     * @throws MessengerException when the platform refuses or has no refund path
     */
    public function refundPayment(string $platformPaymentChargeId, int $platformUserId): void;
}
