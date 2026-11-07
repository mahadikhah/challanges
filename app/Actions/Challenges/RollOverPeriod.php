<?php

namespace App\Actions\Challenges;

use App\Actions\CheckIns\OpenCheckIn;
use App\Actions\CheckIns\SettleCheckIn;
use App\Exceptions\PeriodNotEndedException;
use App\Models\Challenge;
use App\Models\ChallengeParticipant;
use App\Models\ChallengePeriod;
use App\Models\CheckIn;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Close a period that has ended and settle everyone who owed it.
 *
 * The counterpart to the check-in path: whatever nobody submitted before the
 * period closed is decided here, once, by `SettleCheckIn::close()`.
 *
 * **Safe to re-run, and it heals a partial run.** There is deliberately no early
 * return on `rolled_over_at`: if the previous attempt died halfway through the
 * participant list, an early return would leave the rest unsettled forever.
 * Idempotency comes from the row level instead — a settled check-in is skipped and
 * an unsettled one is finished — so re-running only ever completes work.
 * `rolled_over_at` is the "this period has been swept" marker that keeps the
 * `awaitingRollover()` scope from re-scanning it, not the guard.
 *
 * **Who owes the period is decided here; what a settlement does is not.** This
 * class knows about late joiners and participants who have left;
 * `SettleCheckIn` knows about streaks and freezes. Neither second-guesses the
 * other.
 */
class RollOverPeriod
{
    /**
     * Participants loaded per query while sweeping.
     *
     * A popular public challenge can have a long participant list, and the sweep
     * holds a settled check-in per participant, so the participant models
     * themselves are not also kept in memory all at once.
     */
    private const PARTICIPANT_CHUNK = 200;

    public function __construct(
        private readonly OpenCheckIn $open,
        private readonly SettleCheckIn $settle,
    ) {}

    /**
     * Settle every outstanding obligation for this period, and mark it swept.
     *
     * `$now` is injectable so the scheduled sweep and the tests can agree on the
     * instant a period closed rather than racing the wall clock.
     *
     * @return Collection<int, CheckIn> every check-in this period now has, settled
     *
     * @throws PeriodNotEndedException when the period has not closed yet
     */
    public function handle(ChallengePeriod $period, ?CarbonInterface $now = null): Collection
    {
        $at = CarbonImmutable::instance($now ?? now());

        if (! $period->hasEnded($at)) {
            // Settling early would mark everyone who has not checked in yet as
            // missed, while they still had time.
            throw new PeriodNotEndedException($period, $at);
        }

        /** @var Collection<int, CheckIn> $settled */
        $settled = new Collection;

        $this->participantsOwing($period)->chunkById(
            self::PARTICIPANT_CHUNK,
            function (EloquentCollection $participants) use ($period, $settled): void {
                foreach ($participants as $participant) {
                    // Someone who never touched the bot has no row at all, and
                    // they still missed the period — so `OpenCheckIn` creates it
                    // rather than the sweep assuming it exists.
                    $settled->push($this->settle->close($this->open->handle($participant, $period)));
                }
            },
        );

        if (! $period->isRolledOver()) {
            $period->update(['rolled_over_at' => $at]);
        }

        return $settled;
    }

    /**
     * The participants this period is judged against.
     *
     * Two exclusions, both deliberate. **Late joiners owe nothing for periods that
     * closed before they arrived** — they catch up on the shared timeline rather
     * than being punished for history they were absent for. And a participant who
     * has left, been removed or finished is not `Active`, so they stop accruing
     * misses the moment they stop taking part.
     *
     * @return HasMany<ChallengeParticipant, Challenge>
     */
    private function participantsOwing(ChallengePeriod $period): HasMany
    {
        return $period->challenge->participants()
            ->active()
            ->where('joined_period_index', '<=', $period->index);
    }
}
