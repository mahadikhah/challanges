<?php

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Why a coin ledger entry exists.
 *
 * The sign of a transaction is a property of its reason, not a free choice by
 * the caller — a `StarsPurchase` can never be a debit, and a `FreezePurchase`
 * can never be a credit. `CoinLedger` asserts that, which is why admin
 * adjustments are split into two cases instead of one signed "adjustment".
 */
enum CoinTransactionReason: string
{
    use HasTranslatedLabel;

    case StarsPurchase = 'stars_purchase';
    case InviteCredit = 'invite_credit';
    case ChallengeCompletion = 'challenge_completion';
    case AdminCredit = 'admin_credit';

    case CreateSlotPurchase = 'create_slot_purchase';
    case JoinSlotPurchase = 'join_slot_purchase';
    case FreezePurchase = 'freeze_purchase';
    case StarsRefund = 'stars_refund';
    case AdminDebit = 'admin_debit';

    /**
     * Whether this reason adds coins to a balance.
     */
    public function isCredit(): bool
    {
        return match ($this) {
            self::StarsPurchase,
            self::InviteCredit,
            self::ChallengeCompletion,
            self::AdminCredit => true,
            default => false,
        };
    }

    public function isDebit(): bool
    {
        return ! $this->isCredit();
    }

    /**
     * The sign a transaction with this reason must carry: `1` or `-1`.
     *
     * `CoinLedger` multiplies an absolute amount by this rather than trusting a
     * caller-supplied sign, so a mistyped minus cannot invent coins.
     */
    public function sign(): int
    {
        return $this->isCredit() ? 1 : -1;
    }
}
