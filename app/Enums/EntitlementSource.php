<?php

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use App\Enums\Contracts\HasTranslatedLabel as HasTranslatedLabelContract;

/**
 * Where an entitlement came from.
 *
 * Kept distinct from the type so support can answer "why does this user have
 * three create-slots?" from the row alone, without reading the coin ledger.
 */
enum EntitlementSource: string implements HasTranslatedLabelContract
{
    use HasTranslatedLabel;

    /** The allowance every user gets for free. Never billed, never refunded. */
    case FreeBaseline = 'free_baseline';

    case CoinPurchase = 'coin_purchase';
    case AdminGrant = 'admin_grant';

    /**
     * Whether a coin transaction should exist for this entitlement.
     */
    public function isPaid(): bool
    {
        return $this === self::CoinPurchase;
    }
}
