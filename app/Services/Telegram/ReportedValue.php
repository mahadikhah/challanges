<?php

namespace App\Services\Telegram;

use App\Services\Localization;

/**
 * Parse a participant's typed quantity report into a number the ledger can
 * score.
 *
 * The value arrives as free text from a chat box, so it arrives in every shape
 * a keyboard produces: `45`, `45.5`, `45,5` — a decimal comma is ordinary on a
 * Farsi layout — and Persian and Eastern Arabic digits that no `is_numeric`
 * will accept. One parser, shared by the check-in flow and the timed-session
 * flow, so both ask and both score the same way.
 *
 * Deliberately strict about everything else: a value that is not a plain
 * non-negative number parses to null and the surface asks again, rather than
 * being coerced — `12kg` becoming 12 would let a participant's typo silently
 * score, and a minus sign has no meaning in a quantity report.
 */
class ReportedValue
{
    /**
     * Fold, normalise and validate a typed report. Null means "ask again".
     */
    public function normalise(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        $folded = str_replace(
            [' ', "\u{200c}"],
            '',
            Localization::foldDigits(trim($input)),
        );

        // A decimal comma is a decimal point; Persian thousands separators and
        // currency marks are not invited.
        $folded = str_replace(',', '.', $folded);

        if (preg_match('/^\d+(\.\d+)?$/', $folded) !== 1) {
            return null;
        }

        return $folded;
    }
}
