<?php

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * The life of one Telegram Stars purchase.
 *
 * `Pending` exists because an invoice link is created before the user decides.
 * Most pending rows are simply abandoned carts, not failures.
 */
enum StarPaymentStatus: string
{
    use HasTranslatedLabel;

    /** Invoice created; Telegram has not confirmed anything yet. */
    case Pending = 'pending';

    /** `successful_payment` received and the coins were credited. */
    case Paid = 'paid';

    /** Stars returned to the user; the coins were clawed back. */
    case Refunded = 'refunded';

    /** Declined at pre-checkout, or abandoned and swept up. */
    case Failed = 'failed';

    /**
     * Whether coins have been credited for this payment.
     */
    public function isPaid(): bool
    {
        return $this === self::Paid;
    }

    /**
     * Whether this payment can still transition — a refund is only meaningful
     * against a paid row, and a paid row cannot go back to pending.
     */
    public function isRefundable(): bool
    {
        return $this === self::Paid;
    }

    public function isTerminal(): bool
    {
        return $this === self::Refunded || $this === self::Failed;
    }
}
