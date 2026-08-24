<?php

namespace App\Exceptions;

use App\Models\ChallengePeriod;
use Carbon\CarbonInterface;
use RuntimeException;

/**
 * Something tried to settle a period that is still open.
 *
 * Refused rather than tolerated because settling early is destructive: every
 * participant who has not checked in yet would be marked `Missed` and lose a
 * streak they still had time to keep. A caller sweeping
 * `ChallengePeriod::awaitingRollover()` can never trigger this, so reaching it
 * means a period was passed in by hand.
 */
class PeriodNotEndedException extends RuntimeException
{
    public function __construct(
        public readonly ChallengePeriod $period,
        public readonly CarbonInterface $at,
    ) {
        parent::__construct(sprintf(
            'Period %d of challenge %s does not close until %s; it is %s.',
            $period->index,
            $period->challenge_id,
            $period->ends_at->toIso8601String(),
            $at->toIso8601String(),
        ));
    }
}
