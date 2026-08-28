<?php

namespace App\Actions\Payments;

use App\Enums\CoinTransactionReason;
use App\Enums\StarPaymentStatus;
use App\Models\StarPayment;
use App\Models\User;
use App\Services\CoinLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Credit a `successful_payment`: the one place Stars become coins.
 *
 * §6 of `prompts/main.md` names this as a verification target — replay a
 * `successful_payment` carrying a duplicate `telegram_payment_charge_id` and
 * assert exactly one credit. Three layers hold that:
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
 * Amounts are validated against the row, not the payload: the row was priced by
 * `CreateStarsInvoice` from the admin-tuned table, so a `total_amount` or
 * `currency` that disagrees with it is a payload we did not issue.
 */
class CompleteStarsPayment
{
    public function __construct(private readonly CoinLedger $ledger) {}

    /**
     * @param  array<string, mixed>  $successfulPayment  Telegram's `successful_payment` object
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
            return DB::transaction(function () use ($user, $successfulPayment, $chargeId, $invoicePayload): ?StarPayment {
                $payment = StarPayment::query()
                    ->where('invoice_payload', $invoicePayload)
                    ->where('user_id', $user->getKey())
                    ->lockForUpdate()
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

                if ($payment->status === StarPaymentStatus::Paid) {
                    // The replay path. Whatever arrived, the coins for this
                    // invoice were already credited — to this user, under this
                    // row's charge id — and nothing here may run twice.
                    return $payment;
                }

                if ($payment->status !== StarPaymentStatus::Pending) {
                    Log::warning('A successful_payment arrived for an invoice that can no longer complete.', [
                        'star_payment_id' => $payment->getKey(),
                        'status' => $payment->status->value,
                    ]);

                    return $payment;
                }

                $currency = $successfulPayment['currency'] ?? null;
                $totalAmount = $successfulPayment['total_amount'] ?? null;

                if ($currency !== 'XTR' || $totalAmount !== $payment->stars_amount) {
                    // We priced this row ourselves when the link was issued, so
                    // a disagreement means the payload is not the payment we
                    // asked for. Decline to credit, and mark the row so the
                    // anomaly is visible rather than a pending cart forever.
                    Log::error('A successful_payment disagreed with the invoice it named.', [
                        'star_payment_id' => $payment->getKey(),
                        'currency' => is_scalar($currency) ? (string) $currency : null,
                        'total_amount' => is_scalar($totalAmount) ? (string) $totalAmount : null,
                        'expected_stars' => $payment->stars_amount,
                    ]);

                    $payment->forceFill([
                        'status' => StarPaymentStatus::Failed,
                        'payload' => $successfulPayment,
                    ])->save();

                    return $payment;
                }

                $payment->forceFill([
                    'telegram_payment_charge_id' => $chargeId,
                    'status' => StarPaymentStatus::Paid,
                    'paid_at' => now(),
                    'payload' => $successfulPayment,
                ])->save();

                $this->ledger->credit(
                    $user,
                    $payment->coin_amount,
                    CoinTransactionReason::StarsPurchase,
                    $payment->creditIdempotencyKey(),
                    $payment,
                );

                return $payment;
            });
        } catch (Throwable $failure) {
            // Most plausibly the charge id colliding with another row's: the
            // unique index refusing is the system working, and the update rolls
            // back with the credit. Worth a line, because it means one Telegram
            // charge is being claimed twice.
            Log::error('A successful_payment could not be recorded.', [
                'user_id' => $user->getKey(),
                'invoice_payload' => $invoicePayload,
                'telegram_payment_charge_id' => $chargeId,
                'reason' => $failure->getMessage(),
            ]);

            throw $failure;
        }
    }
}
