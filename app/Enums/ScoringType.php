<?php

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use App\Enums\Contracts\HasTranslatedLabel as HasTranslatedLabelContract;

/**
 * How a period's outcome becomes a score.
 *
 * `binary` — the default, and every challenge until Phase 15 — is done or not:
 * the streak is the whole story. `quantity` layers a number on top: each period
 * reports a value against a target, scored by the challenge's strategy and
 * accumulated on the participant.
 *
 * Orthogonal to `ProofType`: a quantity challenge might still be `button` (the
 * number *is* the check-in) or `image_approval` (a photo plus a number). The
 * value is collected in addition to whatever proof the type already demands.
 */
enum ScoringType: string implements HasTranslatedLabelContract
{
    use HasTranslatedLabel;

    /** Done or not; the streak is the score. No scoring columns populated. */
    case Binary = 'binary';

    /** A reported value per period, scored against a target. */
    case Quantity = 'quantity';

    /**
     * Whether periods of this challenge carry a reported value.
     */
    public function isQuantity(): bool
    {
        return $this === self::Quantity;
    }
}
