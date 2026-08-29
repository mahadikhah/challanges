<?php

namespace App\Actions\Payments;

use App\Enums\CoinTransactionReason;
use App\Enums\PaymentProvider;
use App\Enums\PaymentTransactionStatus;
use App\Enums\StarPaymentStatus;
use App\Messaging\Contracts\MessengerException;
use App\Messaging\DTO\PaymentTransaction;
use App\Messaging\PlatformRegistry;
use App\Models\StarPayment;
use App\Models\User;
use App\Services\CoinLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Credit a `successful_payment`: the one place a rail's payment becomes coins.
 *
 * Two rails reach here, and the row's own `provider` decides how much to trust
 * the payload:
 *
 * - **Telegram Stars** confirms a payment inside `successful_payment` itself,
 *   delivered over the secret-token-authenticated webhook, so the payload's
 *   currency and total are checked against the row and credited.
 * - **Bale Pay** is a request-then-verify rail (the `bale-payments` skill:
 *   "trust the inquiry, not the update"), and its webhook carries no
 *   authenticity mechanism at all — the URL's secret path segment is the whole
 *   gate. So before crediting, the charge id is put to
 *   `inquireTransaction`, and *that* status and amount are what get compared.
 *
 * §6 of `prompts/main.md` names this as a verification target — replay a
 * `successful_payment` carrying a duplicate `telegram_payment_charge_id` and
 * assert exactly one credit. Three layers hold that, on both rails:
 *
 * 1. **The row, locked.** The payment is found by `invoice_payload` *scoped to
 *    this user* — never by anything the client supplied about who is paying —
 *    and locked, so a replay racing the original waits, then sees it paid.
 * 2. **The status transition.** A row already `Paid` is returned untouched, and
 *    the Pending→Paid write happens inside the same transaction as the ledger
 *    entry, so there is no state in which coins exist without the row saying
 *    they should.
 * 3. **The charge id.** It is unique on the table and derives the ledger's
 *    idempotency key, so even a charge id reaching a second row — or a second
 *    credit attempt for the same charge — cannot write twice.
 *
 * Amounts are validated against the row, not the payload: the row was priced
 * by the invoice-creating action from the admin-tuned table, so anything that
 * disagrees with it is a payment we did not issue.
 */
class CompleteStarsPayment
{
    public function __construct(
        private readonly CoinLedger $ledger,
        private readonly PlatformRegistry $platforms,
    ) {}

    /**
     * @param  array<string, mixed>  $successfulPayment  the rail's `successful_payment` object
     * @return StarPayment|null the payment as it stands now; null when the
     *                          payload names no purchasable invoice of this user's
     */
    public function handle(User $user, array $successfulPayment): ?StarPayment
    {
        $chargeId = $successfulPayment['telegram_payment_charge_id'] ?? null;
        $invoicePayload = $successfulPayment['invoice_payload'] ?? null;

        if (! is_string($chargeId) || $chargeId === '' || ! is_string($invoicePayload) || $invoicePayload === '') {
            Log::warning('A successful_payment arrived without a charge id or an invoice payload.', [
                'user_id' => $user->getKey(),
            ]);

            return null;
        }

        try {
            /*
             * An unlocked read first, only to learn the rail and settle replays
             * cheaply. Bale's verification is a network call, and it happens
             * *before* the transaction: no HTTP while holding the row's lock.
             */
            $payment = StarPayment::query()
                ->where('invoice_payload', $invoicePayload)
                ->where('user_id', $user->getKey())
                ->first();

            if ($payment === null) {
                // Not this user's invoice — or nobody's. Either way there is
                // nothing to credit, and money moving without a row behind
                // it is worth a loud log line rather than a shrug.
                Log::error('A successful_payment named an invoice that does not exist for this user.', [
                    'user_id' => $user->getKey(),
                    'invoice_payload' => $invoicePayload,
                    'telegram_payment_charge_id' => $chargeId,
                ]);

                return null;
            }

            $verified = $this->verifyWithBale($user, $payment, $chargeId);

            if ($verified === null) {
                // The rail has not confirmed the money; the row says whatever
                // it said before, and a redelivery retries the inquiry.
                return $payment;
            }

            $currency = $payment->provider === PaymentProvider::BalePay
                ? $payment->provider->currency()
                : $successfulPayment['currency'] ?? null;
            $totalAmount = $payment->provider === PaymentProvider::BalePay
                ? $verified->amount
                : $successfulPayment['total_amount'] ?? null;

            return DB::transaction(function () use ($user, $payment, $successfulPayment, $chargeId, $currency, $totalAmount): StarPayment {
                /** @var StarPayment $row */
                $row = StarPayment::query()
                    ->whereKey($payment->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($row->status === StarPaymentStatus::Paid) {
                    // The replay path. Whatever arrived, the coins for this
                    // invoice were already credited — to this user, under this
                    // row's charge id — and nothing here may run twice.
                    return $row;
                }

                if ($row->status !== StarPaymentStatus::Pending) {
                    Log::warning('A successful_payment arrived for an invoice that can no longer complete.', [
                        'star_payment_id' => $row->getKey(),
                        'status' => $row->status->value,
                    ]);

                    return $row;
                }

                if (! $row->acceptsPreCheckout($currency, $totalAmount)) {
                    // We priced this row ourselves when the invoice was issued, so
                    // a disagreement means the payload is not the payment we
                    // asked for. Decline to credit, and mark the row so the
                    // anomaly is visible rather than a pending cart forever.
                    Log::error('A successful_payment disagreed with the invoice it named.', [
                        'star_payment_id' => $row->getKey(),
                        'currency' => is_scalar($currency) ? (string) $currency : null,
                        'total_amount' => is_scalar($totalAmount) ? (string) $totalAmount : null,
                        'expected_price' => $row->price(),
                    ]);

                    $row->forceFill([
                        'status' => StarPaymentStatus::Failed,
                        'payload' => $successfulPayment,
                    ])->save();

                    return $row;
                }

                $row->forceFill([
                    'telegram_payment_charge_id' => $chargeId,
                    'status' => StarPaymentStatus::Paid,
                    'paid_at' => now(),
                    'payload' => $successfulPayment,
                ])->save();

                $this->ledger->credit(
                    $user,
                    $row->coin_amount,
                    $this->creditReason($row),
                    $row->creditIdempotencyKey(),
                    $row,
                );

                return $row;
            });
        } catch (Throwable $failure) {
            // Most plausibly the charge id colliding with another row's: the
            // unique index refusing is the system working, and the update rolls
            // back with the credit. Worth a line, because it means one payment
            // is being claimed twice.
            Log::error('A successful_payment could not be recorded.', [
                'user_id' => $user->getKey(),
                'invoice_payload' => $invoicePayload,
                'telegram_payment_charge_id' => $chargeId,
                'reason' => $failure->getMessage(),
            ]);

            throw $failure;
        }
    }

    /**
     * Ask Bale what actually became of this charge — the verify step the rail
     * requires before anything is credited.
     *
     * Returns the inquiry when it confirms the money moved; `null` when it did
     * not (row left as it stands — pending, or marked failed when the rail
     * said so). Telegram rows pass straight through with a synthetic "already
     * confirmed" answer, because Stars' `successful_payment` *is* its own
     * confirmation.
     *
     * @return PaymentTransaction|null `null` also on the not-yet-paid paths
     *
     * @throws MessengerException when the inquiry cannot be made — which
     *                            propagates, so the queue retries rather than
     *                            treating an unanswered question as a "no"
     */
    private function verifyWithBale(User $user, StarPayment $payment, string $chargeId): ?PaymentTransaction
    {
        if ($payment->provider !== PaymentProvider::BalePay) {
            // A non-null stand-in that asks no question: the payload itself is
            // the confirmation on this rail, and the caller reads the amounts
            // from the payload whenever this is not a Bale row.
            return new PaymentTransaction($chargeId, PaymentTransactionStatus::Paid, null, null);
        }

        if ($payment->status->isPaid()) {
            // Credited once already; re-inquiring a spent transaction is the
            // exact rework the skill warns about.
            return new PaymentTransaction($chargeId, PaymentTransactionStatus::Paid, null, null);
        }

        $transaction = $this->platforms->for($user->platform)->inquireTransaction($chargeId);

        if ($transaction->status->isPaid()) {
            return $transaction;
        }

        if ($transaction->status === PaymentTransactionStatus::Failed
            || $transaction->status === PaymentTransactionStatus::Rejected) {
            // A definitive "no" from the rail: the payment is dead, and the
            // row should say so rather than sit as a payable-looking cart.
            $payment->forceFill(['status' => StarPaymentStatus::Failed])->save();
        }

        // `pending` and `unknown` both stay pending: money may still land, and
        // a redelivery re-asks. Never credit on a status short of `paid`.
        Log::warning('A Bale payment was not confirmed by inquireTransaction.', [
            'star_payment_id' => $payment->getKey(),
            'status' => $transaction->status->value,
            'amount' => $transaction->amount,
        ]);

        return null;
    }

    /**
     * Which ledger reason a credit goes under — the rail's own name, so the
     * audit can tell a Stars purchase from a Bale one at a glance.
     */
    private function creditReason(StarPayment $payment): CoinTransactionReason
    {
        return match ($payment->provider) {
            PaymentProvider::TelegramStars => CoinTransactionReason::StarsPurchase,
            PaymentProvider::BalePay => CoinTransactionReason::BalePayPurchase,
        };
    }
}
