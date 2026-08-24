<?php

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * The life of one invite code.
 *
 * `Claimed` and `Credited` are separate on purpose: an invite may be used by
 * someone who already had an account, which attributes the arrival without
 * paying the inviter. Collapsing the two would make "used but unpaid"
 * indistinguishable from "never used", and the inviter would rightly ask why.
 */
enum InviteStatus: string
{
    use HasTranslatedLabel;

    /** Minted, nobody has arrived through it yet. */
    case Pending = 'pending';

    /** Used by an existing user — attributed, but no coins were paid. */
    case Claimed = 'claimed';

    /** Used by a brand-new user, and the inviter was paid. */
    case Credited = 'credited';

    /**
     * Whether the code has been used and can never be used again.
     */
    public function isClaimed(): bool
    {
        return $this !== self::Pending;
    }

    /**
     * Whether the code is still open to a new arrival.
     */
    public function isOpen(): bool
    {
        return $this === self::Pending;
    }

    /**
     * Whether this invite earned the inviter coins.
     */
    public function wasPaid(): bool
    {
        return $this === self::Credited;
    }
}
