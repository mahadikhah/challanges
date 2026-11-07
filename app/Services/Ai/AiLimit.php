<?php

namespace App\Services\Ai;

use App\Enums\AiLimitPeriod;
use Carbon\CarbonImmutable;

/**
 * An account's (or a global window's) limit block, and the calendar math to
 * turn it into a concrete half-open window in that owner's timezone.
 */
final readonly class AiLimit
{
    public function __construct(
        public ?int $inputTokens,
        public ?int $outputTokens,
        public ?int $totalTokens,
        public AiLimitPeriod $period,
        public string $timezone,
    ) {}

    /**
     * Null = unlimited for every dimension.
     */
    public function hasAnyLimit(): bool
    {
        return $this->inputTokens !== null || $this->outputTokens !== null || $this->totalTokens !== null;
    }

    public function windowContaining(CarbonImmutable $moment): AiLimitWindow
    {
        $local = $moment->setTimezone($this->timezone);

        $start = $this->period === AiLimitPeriod::Daily ? $local->startOfDay() : $local->startOfMonth();
        $end = $this->period === AiLimitPeriod::Daily ? $start->addDay() : $start->addMonth();

        return new AiLimitWindow($start, $end);
    }
}
