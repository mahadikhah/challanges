<?php

use App\Actions\CheckIns\SettleCheckIn;
use App\Models\Challenge;
use App\Models\ChallengeParticipant;
use App\Models\ChallengePeriod;
use App\Models\CheckIn;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Score concurrency
|--------------------------------------------------------------------------
|
| The mirror of CoinLedgerConcurrencyTest for `total_score`: the accumulator
| moves inside the settlement's participant lock, so two settlements arriving
| together cannot both read the same total and lose an increment between them.
| That has to be demonstrated against the real InnoDB lock manager, not
| asserted in a docblock.
|
| Same discipline as the coin suite: no RefreshDatabase (its wrapping
| transaction would hide every write from every other connection), forked
| workers with their own connections, and the parent disconnects *before*
| forking so no child inherits a socket its siblings are still using.
|
*/

uses(DatabaseTruncation::class);

beforeEach(function () {
    if (! function_exists('pcntl_fork')) {
        // The Sail image ships pcntl, so this runs locally; a host without it
        // loses this coverage and must be told.
        $this->markTestSkipped('Genuine concurrency needs the pcntl extension.');
    }
});

afterEach(function () {
    // DatabaseTruncation clears before each test, not after — these writes are
    // really committed, so leave the tables clean for whichever RefreshDatabase
    // file runs next.
    CheckIn::query()->delete();
    ChallengePeriod::query()->delete();
    ChallengeParticipant::query()->delete();
    Challenge::query()->delete();
    User::query()->delete();
});

/**
 * Run `$count` copies of `$work` in genuinely parallel processes, exiting 0 on
 * success and 1 on any throw — the same contract the coin suite's helper uses.
 *
 * @param  Closure(int): int  $work
 * @return list<int> the exit code of each worker, in launch order
 */
function settledInParallel(int $count, Closure $work): array
{
    DB::disconnect();

    $pids = [];

    for ($index = 0; $index < $count; $index++) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('Could not fork a worker.');
        }

        if ($pid === 0) {
            $code = 1;

            try {
                $code = $work($index);
            } catch (Throwable) {
                $code = 1;
            }

            exit($code);
        }

        $pids[] = $pid;
    }

    $codes = [];

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
        $codes[] = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 1;
    }

    return $codes;
}

it('loses no score when eight periods settle at once', function () {
    // The creator opted into partial reports, so every report settles as done —
    // the odd workers' short reports at half score. Freezes would change
    // nothing here (nothing misses) and are left at the factory default.
    $challenge = Challenge::factory()->active()->quantity(partialCountsAsDone: true)->create();
    $participant = ChallengeParticipant::factory()->for($challenge)->create();

    $checkInIds = collect(range(0, 7))->map(fn (int $index): int => CheckIn::factory()
        ->on(
            $participant,
            ChallengePeriod::factory()->for($challenge)->atIndex($index)->create([
                'starts_at' => now()->subHour(),
                'ends_at' => now()->addHour(),
            ]),
        )
        ->create()
        ->getKey());

    // Eight workers, one participant row, alternating reports: 30 → 100 pts,
    // 15 → 50 pts. The exact total is 600 — a lost update leaves it short.
    $codes = settledInParallel(8, function (int $index) use ($checkInIds): int {
        app(SettleCheckIn::class)->approve(
            CheckIn::query()->findOrFail($checkInIds[$index]),
            $index % 2 === 0 ? 30 : 15,
        );

        return 0;
    });

    expect($codes)->each->toBe(0)
        ->and(CheckIn::query()->whereNotNull('score')->count())->toBe(8)
        ->and((float) $participant->refresh()->total_score)->toBe(600.0)
        ->and($participant->current_streak)->toBe(8);
});

it('scores a period exactly once when two workers race to settle it', function () {
    $challenge = Challenge::factory()->active()->quantity()->create();
    $participant = ChallengeParticipant::factory()->for($challenge)->create();

    $checkIn = CheckIn::factory()
        ->on(
            $participant,
            ChallengePeriod::factory()->for($challenge)->atIndex(0)->create([
                'starts_at' => now()->subHour(),
                'ends_at' => now()->addHour(),
            ]),
        )
        ->create();

    $checkInId = $checkIn->getKey();

    $codes = settledInParallel(4, function () use ($checkInId): int {
        app(SettleCheckIn::class)->approve(CheckIn::query()->findOrFail($checkInId), 30);

        return 0;
    });

    // The status transition is the idempotency token: every worker exits
    // cleanly (the row comes back settled to the losers), but the score and
    // the streak move once.
    expect($codes)->each->toBe(0)
        ->and((float) $participant->refresh()->total_score)->toBe(100.0)
        ->and($participant->current_streak)->toBe(1);
});
