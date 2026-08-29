<?php

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use App\Enums\Contracts\HasTranslatedLabel as HasTranslatedLabelContract;
use InvalidArgumentException;

/**
 * The fixed, safe set of strategies that turn a reported value into a score.
 *
 * A fixed enum rather than a creator-supplied formula, deliberately: an
 * expression evaluated server-side is a code-injection surface for exactly the
 * reason arbitrary formulas always are, and a small set of named strategies
 * covers the stated use case without that risk. New strategies are new enum
 * cases plus one branch in `CalculateQuantityScore` — no schema change, which
 * is why the enum exists at all when only `Proportional` ships.
 */
enum ScoringStrategy: string implements HasTranslatedLabelContract
{
    use HasTranslatedLabel;

    /**
     * `score = round(reported / target * base_points)`, uncapped above the
     * target: exceeding it scores above `base_points`, falling short below it.
     */
    case Proportional = 'proportional';

    /**
     * Compute the strategy's score.
     *
     * Lives on the enum so "one branch per strategy" is one place a new case
     * must answer — adding a case without a branch is a match on an unhandled
     * value, caught here rather than silently scoring zero.
     */
    public function score(int|float|string $targetValue, int|float|string $basePoints, int|float|string $reportedValue): int
    {
        $target = (float) $targetValue;
        $base = (float) $basePoints;
        $reported = (float) $reportedValue;

        return (int) round(match ($this) {
            self::Proportional => $target > 0
                ? $reported / $target * $base
                : throw new InvalidArgumentException('A quantity challenge needs a positive target_value.'),
        });
    }
}
