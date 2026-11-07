<?php

use App\Enums\CoinTransactionReason;
use App\Exceptions\InsufficientCoinsException;
use App\Models\CoinTransaction;
use App\Models\User;
use App\Services\CoinLedger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Coin concurrency
|--------------------------------------------------------------------------
|
| Required by prompts/main.md §6. Coins are bought with real money, so "two
| taps at once cannot spend the same coins twice" has to be demonstrated, not
| asserted in a docblock.
|
| These tests deliberately do NOT use RefreshDatabase. Its wrapping transaction
| would hide every write from every other connection, which is precisely the
| thing under test — the suite would pass while proving nothing. DatabaseTruncation
| commits for real and cleans up afterwards.
|
| Parallelism is real: each writer is a forked process with its own MySQL
| connection, contending for the same row lock through the real InnoDB lock
| manager. The parent disconnects *before* forking, so no child inherits and then
| tears down a socket the others are still using.
|
*/

uses(DatabaseTruncation::class);

beforeEach(function () {
    if (! function_exists('pcntl_fork')) {
        // Recorded in prompts/progress.md: the Sail image ships pcntl, so this
        // runs locally. A host without it loses this coverage and must be told.
        $this->markTestSkipped('Genuine concurrency needs the pcntl extension.');
    }

    $this->ledger = app(CoinLedger::class);
    $this->user = User::factory()->telegram()->create();
});

afterEach(function () {
    // DatabaseTruncation clears the tables *before* each test in this file, which
    // says nothing about what the last one leaves behind. These writes are really
    // committed, so without this the final test's rows are still sitting there
    // when the next file opens its RefreshDatabase transaction on top of them —
    // and that file then counts rows it never wrote.
    CoinTransaction::query()->delete();
    User::query()->delete();
});

/**
 * Run `$count` copies of `$work` in genuinely parallel processes.
 *
 * `$work` receives the worker's index and returns an exit code, so the parent can
 * tell "wrote the entry" from "was correctly refused". Anything thrown becomes
 * exit code 1, which no assertion below accepts.
 *
 * @param  Closure(int): int  $work
 * @return list<int> the exit code of each worker, in launch order
 */
function inParallel(int $count, Closure $work): array
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

            // Leave immediately, without unwinding the test runner in a process
            // that was never meant to report results.
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

describe('the row lock is real', function () {
    it('blocks a second connection from entering the critical section', function () {
        // Two live connections, one row. If the lock were advisory or absent, the
        // second SELECT ... FOR UPDATE would return immediately and both writers
        // would read the same pre-spend balance.
        $connection = config()->string('database.default');
        config()->set('database.connections.probe', config("database.connections.{$connection}"));

        $probe = DB::connection('probe');
        $probe->statement('SET SESSION innodb_lock_wait_timeout = 1');

        DB::beginTransaction();
        DB::table('users')->where('id', $this->user->id)->lockForUpdate()->first();

        $probe->beginTransaction();

        try {
            expect(fn () => $probe->table('users')->where('id', $this->user->id)->lockForUpdate()->first())
                ->toThrow(QueryException::class);
        } finally {
            $probe->rollBack();
            DB::rollBack();
            $probe->disconnect();
        }
    });

    it('lets the second connection through once the first commits', function () {
        $connection = config()->string('database.default');
        config()->set('database.connections.probe', config("database.connections.{$connection}"));

        $probe = DB::connection('probe');
        $probe->statement('SET SESSION innodb_lock_wait_timeout = 1');

        DB::transaction(function () {
            DB::table('users')->where('id', $this->user->id)->lockForUpdate()->first();
        });

        $locked = $probe->table('users')->where('id', $this->user->id)->lockForUpdate()->first();

        expect($locked)->not->toBeNull();

        $probe->disconnect();
    });
});

describe('parallel credits', function () {
    it('loses no update when eight processes credit at once', function () {
        $codes = inParallel(8, fn (int $index): int => app(CoinLedger::class)
            ->credit($this->user, 10, CoinTransactionReason::AdminCredit, "parallel:credit:{$index}")
            ->exists ? 0 : 1);

        expect($codes)->each->toBe(0);

        // Eight distinct keys, so eight entries and nothing swallowed.
        expect(CoinTransaction::query()->count())->toBe(8)
            ->and($this->ledger->balanceFor($this->user))->toBe(80)
            ->and($this->ledger->sum($this->user))->toBe(80)
            ->and($this->ledger->drift($this->user))->toBe(0);
    });

    it('leaves the running total consistent at every step', function () {
        inParallel(8, fn (int $index): int => app(CoinLedger::class)
            ->credit($this->user, 10, CoinTransactionReason::AdminCredit, "step:{$index}")
            ->exists ? 0 : 1);

        // Serialised writes produce 10, 20, ... 80 in id order. A lost update
        // would repeat a value or skip one.
        expect(CoinTransaction::query()->orderBy('id')->pluck('balance_after')->all())
            ->toBe([10, 20, 30, 40, 50, 60, 70, 80]);
    });
});

describe('parallel debits', function () {
    it('cannot spend the same coins twice', function () {
        // Fund exactly five spends, then have eight processes race for them.
        $this->ledger->credit($this->user, 50, CoinTransactionReason::StarsPurchase, 'float');

        $codes = inParallel(8, function (int $index): int {
            try {
                app(CoinLedger::class)->debit(
                    $this->user,
                    10,
                    CoinTransactionReason::FreezePurchase,
                    "parallel:debit:{$index}",
                );

                return 0;
            } catch (InsufficientCoinsException) {
                return 3;
            }
        });

        $spent = count(array_filter($codes, fn (int $code): bool => $code === 0));
        $refused = count(array_filter($codes, fn (int $code): bool => $code === 3));

        expect($spent)->toBe(5)
            ->and($refused)->toBe(3)
            ->and($codes)->not->toContain(1);

        // The balance is the real assertion: never negative, never overspent.
        expect($this->ledger->balanceFor($this->user))->toBe(0)
            ->and($this->ledger->sum($this->user))->toBe(0)
            ->and($this->ledger->drift($this->user))->toBe(0);
    });

    it('never lets a race drive the balance below zero', function () {
        $this->ledger->credit($this->user, 25, CoinTransactionReason::StarsPurchase, 'float');

        inParallel(6, function (int $index): int {
            try {
                app(CoinLedger::class)->debit(
                    $this->user,
                    10,
                    CoinTransactionReason::JoinSlotPurchase,
                    "race:{$index}",
                );

                return 0;
            } catch (InsufficientCoinsException) {
                return 3;
            }
        });

        expect($this->ledger->balanceFor($this->user))->toBe(5)
            ->and(CoinTransaction::query()->min('balance_after'))->toBeGreaterThanOrEqual(0);
    });
});

describe('parallel replays', function () {
    it('writes once when eight processes replay the same key', function () {
        // What a Telegram webhook retry storm looks like: the same
        // successful_payment arriving eight times at once.
        $codes = inParallel(8, fn (int $index): int => app(CoinLedger::class)
            ->credit($this->user, 100, CoinTransactionReason::StarsPurchase, 'star_payment:credit:charge_1')
            ->exists ? 0 : 1);

        expect($codes)->each->toBe(0);

        expect(CoinTransaction::query()->count())->toBe(1)
            ->and($this->ledger->balanceFor($this->user))->toBe(100)
            ->and($this->ledger->drift($this->user))->toBe(0);
    });

    it('keeps distinct keys distinct while replays collapse', function () {
        // Four payments, each delivered twice, interleaved across eight workers.
        $codes = inParallel(8, fn (int $index): int => app(CoinLedger::class)
            ->credit($this->user, 25, CoinTransactionReason::StarsPurchase, 'charge:'.intdiv($index, 2))
            ->exists ? 0 : 1);

        expect($codes)->each->toBe(0);

        expect(CoinTransaction::query()->count())->toBe(4)
            ->and($this->ledger->balanceFor($this->user))->toBe(100);
    });
});
