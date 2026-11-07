<?php

use App\Actions\Challenges\MaterialiseChallengePeriods;
use App\Actions\CheckIns\StartCheckInSession;
use App\Enums\CheckInSessionStatus;
use App\Models\Challenge;
use App\Models\ChallengeParticipant;
use App\Models\ChallengePeriod;
use App\Models\ChallengeStep;
use App\Models\CheckInSession;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;

uses(DatabaseTruncation::class);

/*
 * "Start" is a button, and buttons get double-tapped — and two queued jobs can
 * carry the two taps to two workers at once. The one-open-session line must
 * hold under genuinely parallel writers, not only inside one process, so this
 * file forks real children with their own MySQL connections, exactly as the
 * coin-concurrency file does for the ledger.
 *
 * No RefreshDatabase here, for the same reason as there: its wrapping
 * transaction would hide every write from every other connection, and the
 * whole point is what the other connection sees.
 */

beforeEach(function () {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Genuine concurrency needs the pcntl extension.');
    }

    $creator = User::factory()->telegram()->create();

    $this->challenge = Challenge::factory()
        ->active()
        ->timedSession()
        ->timeline(now()->startOfDay()->toDateTimeString(), 'UTC', 5)
        ->create(['creator_id' => $creator->getKey()]);

    app(MaterialiseChallengePeriods::class)->handle($this->challenge);

    ChallengeStep::factory()->for($this->challenge)->atOrder(1)->waiting(60)->create();

    $this->participant = ChallengeParticipant::factory()->create([
        'challenge_id' => $this->challenge->getKey(),
    ]);

    /** @var ChallengePeriod $period */
    $period = $this->challenge->periods()->where('index', 0)->firstOrFail();
    $this->period = $period;
});

afterEach(function () {
    // DatabaseTruncation clears before each test, not after the last one; the
    // forked children committed for real, so wipe their rows before the next
    // file opens its RefreshDatabase transaction on top of this database.
    CheckInSession::query()->delete();
    ChallengeParticipant::query()->delete();
    ChallengeStep::query()->delete();
    Challenge::query()->delete();
    User::query()->delete();
});

/**
 * Run `$count` copies of `$work` in genuinely parallel processes — the same
 * fork shape as the coin-concurrency file, declared here under its own name
 * because a Pest file's plain functions share one global namespace.
 *
 * @param  Closure(int): int  $work
 * @return list<int> the exit code of each worker, in launch order
 */
function inSessionParallel(int $count, Closure $work): array
{
    // Close before forking: a child that inherits an open PDO and then lets it
    // fall out of scope sends COM_QUIT down a socket its siblings are using.
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

it('converges eight racing starts into one session', function () {
    $participantId = $this->participant->getKey();
    $periodId = $this->period->getKey();

    $codes = inSessionParallel(8, function () use ($participantId, $periodId): int {
        $participant = ChallengeParticipant::query()->findOrFail($participantId);
        $period = ChallengePeriod::query()->findOrFail($periodId);

        $session = app(StartCheckInSession::class)->handle($participant, $period);

        // A worker that had to wait on the lock returns the winner's session;
        // either way, a session came back and no worker failed.
        return $session->exists ? 0 : 1;
    });

    expect($codes)->each->toBe(0);

    $open = CheckInSession::query()
        ->where('challenge_participant_id', $participantId)
        ->where('challenge_period_id', $periodId)
        ->where('status', CheckInSessionStatus::InProgress)
        ->get();

    expect($open)->toHaveCount(1)
        ->and(CheckInSession::query()->count())->toBe(1);
});
