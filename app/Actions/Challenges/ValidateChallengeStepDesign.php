<?php

namespace App\Actions\Challenges;

use App\Enums\PeriodType;
use App\Enums\StepInputType;
use App\Models\Challenge;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Refuse a step design that could not physically be completed inside a period.
 *
 * The earliest a timed session can finish is the sum of its steps' minimum
 * waits — waits are floors, so no participant can beat the sum. If that sum
 * overruns one period of the challenge's type, the design is asking the
 * impossible and is rejected *at design time*, before the challenge exists and
 * before a single participant is stranded mid-session.
 *
 * **The period's length comes from the existing materialiser, not from a
 * second table of seconds-per-type.** `MaterialiseChallengePeriods::boundaries()`
 * is asked about a one-period, unsaved `Challenge` carrying exactly the
 * configuration under validation, and the first boundary pair is diffed. That
 * keeps seasonal lengths, custom day counts and DST behaviour defined in one
 * place — a divergence here would let a design pass that the timeline itself
 * would later contradict.
 */
class ValidateChallengeStepDesign
{
    public function __construct(private readonly MaterialiseChallengePeriods $periods) {}

    /**
     * Validate a proposed step list against the period it must fit inside.
     *
     * `$steps` is ordered: the first element is step 1, the second step 2, and
     * so on — position is the order, so a caller cannot propose gaps.
     *
     * @param  list<array{input_type: StepInputType, min_wait_seconds: int, voice_max_seconds?: int|null, label?: string|null}>  $steps
     *
     * @throws InvalidArgumentException when the list is empty, a step is malformed, or the waits overrun the period
     */
    public function handle(
        PeriodType $periodType,
        ?int $customPeriodDays,
        CarbonInterface $startsAt,
        string $timezone,
        array $steps,
    ): void {
        if ($steps === []) {
            throw new InvalidArgumentException('A timed-session challenge needs at least one step.');
        }

        // Shape before arithmetic: the sum below indexes the fields this loop
        // has already vouched for.
        foreach ($steps as $index => $step) {
            $this->assertWellFormed($step, $index + 1);
        }

        $minimum = $this->minimumSeconds($steps);

        $periodSeconds = $this->periodSeconds($periodType, $customPeriodDays, $startsAt, $timezone);

        if ($minimum > $periodSeconds) {
            $excess = $minimum - $periodSeconds;

            throw new InvalidArgumentException(
                "The steps need at least {$minimum} seconds, but one {$periodType->value} period of this "
                ."challenge lasts only {$periodSeconds} seconds — {$excess} seconds too many."
            );
        }
    }

    /**
     * The earliest this design can possibly be finished: the waits, summed.
     *
     * @param  list<array{min_wait_seconds: int, ...}>  $steps
     */
    public function minimumSeconds(array $steps): int
    {
        return array_sum(array_map(
            static fn (array $step): int => $step['min_wait_seconds'],
            $steps,
        ));
    }

    /**
     * How long one period of this configuration lasts, in seconds.
     *
     * Measured on the challenge's first period — the anchor period, before any
     * DST shift could shorten a later sibling by an hour. The materialiser
     * computes the pair in the challenge's timezone, as it must; only the
     * finished UTC instants are diffed here.
     */
    public function periodSeconds(
        PeriodType $periodType,
        ?int $customPeriodDays,
        CarbonInterface $startsAt,
        string $timezone,
    ): int {
        $challenge = new Challenge;

        $challenge->period_type = $periodType;
        $challenge->custom_period_days = $periodType->requiresCustomDays() ? $customPeriodDays : null;
        $challenge->starts_at = CarbonImmutable::instance($startsAt);
        $challenge->timezone = $timezone;
        $challenge->total_periods = 1;

        $first = $this->periods->boundaries($challenge)[0];

        return (int) $first['starts_at']->diffInSeconds($first['ends_at'], absolute: true);
    }

    /**
     * A step's own coherence, independent of the timeline.
     *
     * @param  array{input_type: StepInputType, min_wait_seconds: int, voice_max_seconds?: int|null, label?: string|null}  $step
     *
     * @throws InvalidArgumentException
     */
    private function assertWellFormed(array $step, int $order): void
    {
        $inputType = $step['input_type'];

        if ($step['min_wait_seconds'] < 0) {
            throw new InvalidArgumentException("Step {$order}: min_wait_seconds may not be negative.");
        }

        $voiceMax = $step['voice_max_seconds'] ?? null;

        // Both directions, deliberately: a cap on a button step is not merely
        // useless, it is a lie about what the step accepts — and a voice step
        // without its cap would accept an hour-long recording as "a note".
        if ($inputType === StepInputType::Voice && ($voiceMax === null || $voiceMax < 1)) {
            throw new InvalidArgumentException(
                "Step {$order}: a voice step needs a voice_max_seconds of at least 1, got ".var_export($voiceMax, true).'.',
            );
        }

        if ($inputType !== StepInputType::Voice && $voiceMax !== null) {
            throw new InvalidArgumentException(
                "Step {$order}: voice_max_seconds is only allowed on a voice step, not {$inputType->value}.",
            );
        }
    }
}
