<?php

namespace App\Console\Commands\Challenges;

use App\Actions\Challenges\RollOverPeriod;
use App\Enums\ChallengeStatus;
use App\Exceptions\PeriodNotEndedException;
use App\Models\Challenge;
use App\Models\ChallengePeriod;
use Illuminate\Console\Command;

/**
 * The minute hand of every challenge: close what has ended.
 *
 * Nothing else calls `RollOverPeriod` — a period that nobody checked in on is
 * decided here, once, and the sweep is the whole reason the action is safe to
 * re-run. This command is also where the lifecycle turns: a `Scheduled`
 * challenge whose first period has begun becomes `Active`, and an `Active`
 * challenge whose timeline has fully elapsed becomes `Completed`. Both belong
 * to the clock, so both belong here rather than smeared across whatever
 * surface happened to notice first.
 *
 * Runs every minute on cron. Each pass is cheap when there is nothing to do
 * (one indexed query on un-swept elapsed periods) and self-healing when a pass
 * was missed — the next one finds the same rows and finishes them.
 */
class RollOverDuePeriodsCommand extends Command
{
    protected $signature = 'challenges:roll-over';

    protected $description = 'Activate started challenges, sweep ended periods, complete finished timelines';

    /**
     * Periods settled per query while sweeping.
     */
    private const CHUNK = 100;

    public function handle(RollOverPeriod $rollOver): int
    {
        $activated = $this->activateStartedChallenges();
        $swept = $this->sweepEndedPeriods($rollOver);
        $completed = $this->completeElapsedChallenges();

        $this->components->twoColumnDetail('Challenges activated', (string) $activated);
        $this->components->twoColumnDetail('Periods swept', (string) $swept);
        $this->components->twoColumnDetail('Challenges completed', (string) $completed);

        return self::SUCCESS;
    }

    /**
     * `Scheduled` → `Active` for every challenge whose timeline has begun.
     *
     * A status left `Scheduled` past its start would silently refuse check-ins
     * and joins even though periods are open, so the flip happens on the clock
     * rather than on whatever user action happens to notice the date.
     */
    private function activateStartedChallenges(): int
    {
        $activated = 0;

        Challenge::query()
            ->where('status', ChallengeStatus::Scheduled)
            ->whereHas('periods', fn ($query) => $query->where('starts_at', '<=', now()))
            ->eachById(function (Challenge $challenge) use (&$activated): void {
                $challenge->update(['status' => ChallengeStatus::Active]);
                $activated++;
            });

        return $activated;
    }

    /**
     * Settle every period that has elapsed and not been swept.
     *
     * `chunkById` with a re-queried scope: settling a period writes to the very
     * column the scope filters on, so an offset-based chunk could skip a row the
     * sweep itself shifted.
     */
    private function sweepEndedPeriods(RollOverPeriod $rollOver): int
    {
        $swept = 0;

        ChallengePeriod::query()
            ->awaitingRollover()
            ->with('challenge')
            ->chunkById(self::CHUNK, function ($periods) use ($rollOver, &$swept): void {
                foreach ($periods as $period) {
                    try {
                        $rollOver->handle($period);
                        $swept++;
                    } catch (PeriodNotEndedException) {
                        // The clock moved between the query and the settle. The
                        // next pass picks the period up; settling it now would
                        // mark people missed while they still had time.
                    }
                }
            });

        return $swept;
    }

    /**
     * `Active` → `Completed` for every challenge whose periods are all swept.
     *
     * "Some period rolled over, and none are left awaiting it" can only mean
     * the timeline is done: periods are materialised whole up front, so a
     * challenge with a period still to come always has an un-swept period. The
     * completion reward is *not* paid here — it is a coin movement and belongs
     * to the payments phase, layered onto this transition when that lands.
     */
    private function completeElapsedChallenges(): int
    {
        $completed = 0;

        Challenge::query()
            ->where('status', ChallengeStatus::Active)
            ->whereHas('periods', fn ($query) => $query->whereNotNull('rolled_over_at'))
            ->whereDoesntHave('periods', fn ($query) => $query->whereNull('rolled_over_at'))
            ->eachById(function (Challenge $challenge) use (&$completed): void {
                $challenge->update(['status' => ChallengeStatus::Completed]);
                $completed++;
            });

        return $completed;
    }
}
