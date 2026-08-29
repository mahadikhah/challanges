<?php

namespace App\Actions\Payments;

use App\Enums\CoinTransactionReason;
use App\Enums\StarPaymentStatus;
use App\Messaging\Contracts\MessengerException;
use App\Messaging\PlatformRegistry;
use App\Models\StarPayment;
use App\Services\CoinLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

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
        private readonly PlatformRegistry $platforms,
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

            $platformUserId = $row->user->platform_user_id;

            if ($platformUserId === null) {
                throw new LogicException(
                    "Star payment {$row->getKey()} belongs to a user with no platform id to refund."
                );
            }

            // Telegram first, database second — deliberately. If the call fails
            // the row stays `Paid` and the whole thing is retryable; the other
            // order would leave a refund Telegram never performed. The platform
            // is the payer's own — only the messenger that took the payment can
            // return it.
            try {
                $this->platforms->for($row->user->platform)->refundPayment($row->telegram_payment_charge_id, $platformUserId);
            } catch (MessengerException $refused) {
                // The file-log backstop for the provider leg: this is money
                // returning to a payer, and the refusal leaves the row retryable
                // — the operator needs the attempt in the logs even when the
                // database side of the story is fine.
                Log::error('A payment refund was refused by the platform.', [
                    'star_payment_id' => $row->getKey(),
                    'provider' => $row->provider->value,
                    'charge_id' => $row->telegram_payment_charge_id,
                    'reason' => $refused->getMessage(),
                ]);

                throw $refused;
            }

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

            Log::info('A payment was refunded.', [
                'star_payment_id' => $row->getKey(),
                'provider' => $row->provider->value,
                'charge_id' => $row->telegram_payment_charge_id,
                'coins_clawed_back' => $row->coin_amount,
                'idempotency_key' => $row->refundIdempotencyKey(),
            ]);

            return $row;
        });
    }
}
