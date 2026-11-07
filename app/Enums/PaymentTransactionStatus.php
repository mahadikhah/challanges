<?php

namespace App\Enums;

/**
 * A payment rail's own status for one transaction, as its inquiry reports it.
 *
 * Parsed at the boundary exactly as `ChatMemberStatus` parses a chat-member
 * status: the rail documents a closed set (`pending | paid | failed |
 * `rejected`), and a value outside that set becomes `Unknown`, which every
 * caller treats as *not paid*. An unrecognised status must never be read as
 * money that arrived.
 *
 * Deliberately **no** `label()`: these are never shown to a user — the payer
 * is told what happened to their coins, not what the rail's ledger called it.
 */
enum PaymentTransactionStatus: string
{
    case Pending = 'pending';

    case Paid = 'paid';

    case Failed = 'failed';

    case Rejected = 'rejected';

    /**
     * A status this deploy does not recognise.
     *
     * Not a documented value. `Unknown` exists so an unparseable answer has
     * somewhere to go that refuses to credit.
     */
    case Unknown = 'unknown';

    /**
     * Read the rail's `status` string, tolerating anything.
     */
    public static function fromRail(mixed $status): self
    {
        return is_string($status)
            ? self::tryFrom($status) ?? self::Unknown
            : self::Unknown;
    }

    /**
     * The one status money has provably moved under.
     */
    public function isPaid(): bool
    {
        return $this === self::Paid;
    }
}
