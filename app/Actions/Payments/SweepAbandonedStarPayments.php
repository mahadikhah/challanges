<?php

namespace App\Actions\Payments;

use App\Enums\StarPaymentStatus;
use App\Models\StarPayment;
use Illuminate\Support\Facades\DB;

/**
 * Close out abandoned carts.
 *
 * A pending `StarPayment` is an invoice link somebody opened and walked away
 * from. Telegram will never send anything more about it — there is no
 * `successful_payment` to wait for and no cancellation callback — so the row
 * would sit `Pending` forever, burying the payments audit under dead invoices.
 *
 * This is housekeeping, not a judgement: sweeping a row to `Failed` changes
 * nothing anyone bought or owed, because a pending row never credited a coin.
 * If the user *did* eventually pay an invoice swept by mistake, the
 * `successful_payment` handler still finds the row by its charge id and the
 * completion is refused loudly rather than crediting a failed purchase — and a
 * 24-hour-old invoice link is not payable in practice anyway.
 */
class SweepAbandonedStarPayments
{
    /**
     * How long an unpaid invoice is kept before it reads as abandoned.
     *
     * Not a rate or a price, so it is not admin-tuned: it is an operational
     * window chosen to be far past any real hesitation at a payment sheet.
     */
    public const ABANDONED_AFTER_HOURS = 24;

    /**
     * Mark every abandoned pending payment as failed.
     *
     * @return int how many rows were swept
     */
    public function handle(): int
    {
        return DB::transaction(function (): int {
            return StarPayment::query()
                ->pending()
                ->where('created_at', '<=', now()->subHours(self::ABANDONED_AFTER_HOURS))
                ->update(['status' => StarPaymentStatus::Failed]);
        });
    }
}
