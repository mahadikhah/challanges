<?php

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use App\Enums\Contracts\HasTranslatedLabel as HasTranslatedLabelContract;

/**
 * What an entitlement lets its owner do.
 *
 * Slots are the unit of access in the economy: creating and joining are metered
 * separately, so spending coins on one never silently consumes the other.
 */
enum EntitlementType: string implements HasTranslatedLabelContract
{
    use HasTranslatedLabel;

    case CreateSlot = 'create_slot';
    case JoinSlot = 'join_slot';

    /**
     * The `Setting` holding the free allowance for this type.
     */
    public function freeAllowanceSetting(): SettingKey
    {
        return match ($this) {
            self::CreateSlot => SettingKey::FreeCreateSlots,
            self::JoinSlot => SettingKey::FreeJoinSlots,
        };
    }

    /**
     * The `Setting` holding the coin price of one extra slot of this type.
     */
    public function priceSetting(): SettingKey
    {
        return match ($this) {
            self::CreateSlot => SettingKey::CreateSlotCoinPrice,
            self::JoinSlot => SettingKey::JoinSlotCoinPrice,
        };
    }

    /**
     * The ledger reason recorded when this slot is bought with coins.
     */
    public function purchaseReason(): CoinTransactionReason
    {
        return match ($this) {
            self::CreateSlot => CoinTransactionReason::CreateSlotPurchase,
            self::JoinSlot => CoinTransactionReason::JoinSlotPurchase,
        };
    }
}
