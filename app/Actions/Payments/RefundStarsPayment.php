<?php

namespace App\Actions\Payments;

use App\Enums\CoinTransactionReason;
use App\Enums\StarPaymentStatus;
use App\Models\StarPayment;
use App\Services\CoinLedger;
use Illuminate\Support\Facades\DB;
use LogicException;
use Telegram\Bot\Api;

/**
 * Return the Stars, claw back the coins.
 *
 * Telegram first, database second — deliberately. If the API call fails the row
 * stays `Paid` and the whole thing is retryable; the other order would leave a
 * refund Telegram never performed. If instead Telegram succeeds and our writes
 * then fail, the retry re-calls `refundStarPayment` against an already-refunded
 * charge, which Telegram refuses — loudly, not silently — while the refund's
 * own idempotency key keeps the coin side from running twice.
 *
 * The clawback may take the balance negative, on purpose: a user who bought
 * coins, spent them, and then had the purchase refunded has had both the goods
 * and the money, and a negative balance is the ledger saying so until it is
 * cleared.
 */
class RefundStarsPayment
{
    public function __construct(
        private readonly Api $telegram,
        private readonly CoinLedger $ledger,
    ) {}

    /**
     * @throws LogicException when the payment is not a paid one to refund
     */
    public function handle(StarPayment $payment): StarPayment
    {
        return DB::transaction(function () use ($payment): StarPayment {
            // Re-read under the lock: the row may have been refunded (or never
            // have been paid) since the caller loaded it.
            $row = StarPayment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if (! $row->isRefundable()) {
                throw new LogicException(
                    "Star payment {$row->getKey()} is {$row->status->value}, so it cannot be refunded."
                );
            }

            $telegramId = $row->user->telegram_id;

            if ($telegramId === null) {
                throw new LogicException(
                    "Star payment {$row->getKey()} belongs to a user with no telegram_id to refund."
                );
            }

            // The SDK (3.16) has no wrapper for this method, so it travels as a
            // raw post — over the same fakeable transport as every other call.
            $this->telegram->post('refundStarPayment', [
                'user_id' => $telegramId,
                'telegram_payment_charge_id' => $row->telegram_payment_charge_id,
            ]);

            $row->forceFill([
                'status' => StarPaymentStatus::Refunded,
                'refunded_at' => now(),
            ])->save();

            $this->ledger->debit(
                $row->user,
                $row->coin_amount,
                CoinTransactionReason::StarsRefund,
                $row->refundIdempotencyKey(),
                $row,
            );

            return $row;
        });
    }
}
