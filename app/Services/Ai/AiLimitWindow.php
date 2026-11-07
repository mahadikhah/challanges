<?php

namespace App\Services\Ai;

use Carbon\CarbonImmutable;

/**
 * A half-open [start, end) window. Boundaries are computed local to the
 * owner's timezone, then compared in UTC — a "daily" limit that resets at UTC
 * midnight is wrong for everyone not on UTC, and shows up only as mysterious
 * late-evening refusals.
 */
final readonly class AiLimitWindow
{
    public function __construct(
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
    ) {}

    public function contains(CarbonImmutable $moment): bool
    {
        $utc = $moment->utc();

        return $utc->greaterThanOrEqualTo($this->startsAt->utc())
            && $utc->lessThan($this->endsAt->utc());
    }
}
