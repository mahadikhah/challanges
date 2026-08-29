<?php

namespace App\Messaging;

use App\Actions\Observability\RecordExternalCall;
use App\Enums\ExternalCallProvider;
use App\Enums\MessagingPlatform;
use App\Enums\PaymentProvider;
use App\Messaging\Contracts\MessengerPlatform;
use App\Messaging\DTO\BotUpdate;
use App\Messaging\DTO\ChatMemberSnapshot;
use App\Messaging\DTO\PaymentTransaction;
use App\Messaging\DTO\SentMessage;
use Closure;

/**
 * The counting skin every `MessengerPlatform` wears in production.
 *
 * Wraps one implementation and records each outbound call's outcome in the
 * `external_call_stats` counters (§3.9): messaging methods count against the
 * platform (`telegram`/`bale`), payment methods against the rail
 * (`telegram_stars`/`bale_pay`) — the two questions an operator asks are
 * "is a messenger down?" and "is a payment rail down?", and those have
 * different answers even though the same class performs both.
 *
 * The counting is transparent by construction: every method forwards its
 * arguments verbatim, returns the inner result verbatim, and rethrows the
 * inner exception verbatim. `PlatformRegistry` is the only place this wrap
 * happens, so every consumer that resolves a platform through the registry
 * counts, and nothing else has to know the counters exist.
 */
class RecordingMessengerPlatform implements MessengerPlatform
{
    public function __construct(
        private readonly MessengerPlatform $inner,
        private readonly RecordExternalCall $record,
    ) {}

    public function platform(): MessagingPlatform
    {
        return $this->inner->platform();
    }

    public function normalizeUpdate(array $payload): ?BotUpdate
    {
        // Local-only — no wire, nothing to count.
        return $this->inner->normalizeUpdate($payload);
    }

    public function sendMessage(int|string $chatId, string $text, ?array $inlineKeyboard = null): SentMessage
    {
        return $this->messaging(fn (): SentMessage => $this->inner->sendMessage($chatId, $text, $inlineKeyboard));
    }

    public function sendPhoto(int $chatId, string $bytes, string $filename, array $captionLines): SentMessage
    {
        return $this->messaging(fn (): SentMessage => $this->inner->sendPhoto($chatId, $bytes, $filename, $captionLines));
    }

    public function answerCallbackQuery(string $callbackQueryId): void
    {
        $this->messaging(fn () => $this->inner->answerCallbackQuery($callbackQueryId));
    }

    public function getChatMember(int|string $chatId, int $platformUserId): ChatMemberSnapshot
    {
        return $this->messaging(fn (): ChatMemberSnapshot => $this->inner->getChatMember($chatId, $platformUserId));
    }

    public function botId(): int
    {
        return $this->messaging(fn (): int => $this->inner->botId());
    }

    public function downloadFile(string $fileId): string
    {
        return $this->messaging(fn (): string => $this->inner->downloadFile($fileId));
    }

    public function answerPreCheckoutQuery(string $preCheckoutQueryId, bool $ok, string $errorMessage = ''): void
    {
        $this->payment(fn () => $this->inner->answerPreCheckoutQuery($preCheckoutQueryId, $ok, $errorMessage));
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
        return $this->payment(fn (): string => $this->inner->createInvoiceLink($title, $description, $payload, $currency, $prices));
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
        return $this->payment(fn (): SentMessage => $this->inner->sendInvoice($chatId, $title, $description, $payload, $prices));
    }

    public function inquireTransaction(string $transactionId): PaymentTransaction
    {
        return $this->payment(fn (): PaymentTransaction => $this->inner->inquireTransaction($transactionId));
    }

    public function refundPayment(string $platformPaymentChargeId, int $platformUserId): void
    {
        $this->payment(fn () => $this->inner->refundPayment($platformPaymentChargeId, $platformUserId));
    }

    /**
     * Count one messaging call against the platform's counter.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $call
     * @return TResult
     */
    private function messaging(Closure $call): mixed
    {
        return $this->record->attempt(
            ExternalCallProvider::forMessaging($this->inner->platform()),
            $call,
        );
    }

    /**
     * Count one payment call against the rail's counter — the rail the
     * platform standing under this instance pays through.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $call
     * @return TResult
     */
    private function payment(Closure $call): mixed
    {
        return $this->record->attempt(
            ExternalCallProvider::forPayment(PaymentProvider::forPlatform($this->inner->platform())),
            $call,
        );
    }
}
