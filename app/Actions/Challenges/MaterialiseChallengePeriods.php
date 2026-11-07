<?php

namespace App\Actions\Challenges;

use App\Enums\PeriodType;
use App\Models\Challenge;
use App\Models\ChallengePeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

/**
 * Build a challenge's timeline of `ChallengePeriod` rows.
 *
 * Every participant is measured against **one shared, fixed timeline** — a late
 * joiner catches up rather than getting a personal clock — so the timeline is a
 * property of the challenge and is materialised once, up front.
 *
 * **The arithmetic happens in the challenge's timezone, and only the results are
 * UTC.** This is the whole point of the class, and getting it the other way round
 * is the bug it exists to prevent. A daily challenge means "one period per local
 * day", so period boundaries advance in *calendar* units in the creator's zone,
 * where PHP keeps the wall-clock time fixed across a DST shift. Adding 24 hours
 * to a UTC instant instead would drift by an hour on the changeover and stay
 * drifted, so a check-in window would open at 23:00 for the rest of the
 * challenge.
 *
 * **Every boundary is computed from the original anchor, never from the previous
 * boundary.** Stepping incrementally accumulates whatever a clamp did: a monthly
 * challenge starting 31 January would go 31 Jan → 29 Feb → 29 Mar and lose the
 * month-end anchor for good. Multiplying the step off the anchor gives
 * 31 Jan → 29 Feb → 31 Mar → 30 Apr, which is what "monthly on the 31st" means.
 *
 * **No-overflow month and year addition.** PHP's native `+1 month` on 31 January
 * yields 2 or 3 March, silently skipping February; `addMonthsNoOverflow()` clamps
 * to the last day of the shorter month. Same for a 29 February anchor advancing a
 * year: 28 February, not 1 March.
 */
class MaterialiseChallengePeriods
{
    /**
     * Rows per insert statement.
     *
     * `total_periods` is an unsigned smallint, so a pathological-but-legal
     * challenge could ask for tens of thousands of periods. Chunking keeps that
     * off a single oversized statement.
     */
    private const INSERT_CHUNK = 500;

    /**
     * How many months one `Seasonal` period spans.
     *
     * Four seasons to a year, so a quarter. Deliberately not tied to the
     * astronomical solstices: those differ by hemisphere, and a participant
     * needs to know when their period closes, not when the earth tilted.
     */
    private const MONTHS_PER_SEASON = 3;

    /**
     * Ensure the challenge's timeline exists, and return it.
     *
     * Safe to re-run, and safe for two callers to run at once: the insert leans
     * on the `(challenge_id, index)` unique index rather than reading first.
     * Missing periods are added and existing ones are left completely alone —
     * this never *moves* a boundary, because a check-in may already be attached
     * to it. Raising `total_periods` and calling again therefore extends the
     * timeline, which is the one edit that is safe to make in place.
     *
     * @return Collection<int, ChallengePeriod>
     */
    public function handle(Challenge $challenge): Collection
    {
        $now = now();

        $rows = array_map(fn (array $period): array => [
            'challenge_id' => $challenge->getKey(),
            'index' => $period['index'],
            'starts_at' => $period['starts_at'],
            'ends_at' => $period['ends_at'],
            'created_at' => $now,
            'updated_at' => $now,
        ], $this->boundaries($challenge));

        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            ChallengePeriod::query()->insertOrIgnore($chunk);
        }

        return $challenge->periods()->get();
    }

    /**
     * The timeline this challenge's configuration implies, without touching the
     * database.
     *
     * Boundaries are a half-open interval — `starts_at` inclusive, `ends_at`
     * exclusive — and period N's `ends_at` is exactly period N+1's `starts_at`,
     * so no instant belongs to two periods and there is no gap between them.
     *
     * The returned instants are **UTC**, converted here rather than at the call
     * site on purpose: the query builder formats a `DateTimeInterface` binding in
     * whatever timezone the object itself carries, so handing it a Tehran-local
     * Carbon would write the local wall clock into a UTC column and lose three
     * and a half hours without complaining.
     *
     * @return list<array{index: int, starts_at: CarbonImmutable, ends_at: CarbonImmutable}>
     *
     * @throws InvalidArgumentException when the challenge is not configured well enough to have a timeline
     */
    public function boundaries(Challenge $challenge): array
    {
        $total = $challenge->total_periods;

        if ($total < 1) {
            throw new InvalidArgumentException("A challenge needs at least one period, got {$total}.");
        }

        $type = $challenge->period_type;
        $customDays = $challenge->customPeriodDays();

        if ($type->requiresCustomDays() && ($customDays === null || $customDays < 1)) {
            throw new InvalidArgumentException(
                "A {$type->value} challenge needs a positive custom_period_days, got ".var_export($customDays, true).'.',
            );
        }

        // Back to the wall-clock time the creator actually chose. `starts_at` is
        // stored UTC, so this is where "09:00 in Tehran" becomes 09:00 again.
        $anchor = CarbonImmutable::instance($challenge->starts_at)->setTimezone($challenge->timezone);

        $periods = [];
        $opensAt = $anchor;

        for ($index = 0; $index < $total; $index++) {
            $closesAt = $this->boundaryAt($anchor, $type, $customDays, $index + 1);

            $periods[] = [
                'index' => $index,
                'starts_at' => $opensAt->utc(),
                'ends_at' => $closesAt->utc(),
            ];

            // Chained so the intervals touch exactly, but each end is still
            // derived from the anchor above rather than from its predecessor.
            $opensAt = $closesAt;
        }

        return $periods;
    }

    /**
     * The instant `$step` periods after the anchor, in the anchor's timezone.
     */
    private function boundaryAt(
        CarbonImmutable $anchor,
        PeriodType $type,
        ?int $customDays,
        int $step,
    ): CarbonImmutable {
        return match ($type) {
            PeriodType::Daily => $anchor->addDays($step),
            PeriodType::Weekly => $anchor->addWeeks($step),
            PeriodType::Custom => $anchor->addDays($step * (int) $customDays),
            PeriodType::Monthly => $anchor->addMonthsNoOverflow($step),
            PeriodType::Seasonal => $anchor->addMonthsNoOverflow($step * self::MONTHS_PER_SEASON),
            PeriodType::Yearly => $anchor->addYearsNoOverflow($step),
        };
    }
}
