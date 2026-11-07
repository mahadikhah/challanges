<?php

use App\Services\CoinLedger;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The per-user economy mutex
|--------------------------------------------------------------------------
|
| `CoinLedger::lockUser()` refuses to run outside a transaction, because a
| `lockForUpdate()` on an autocommitted select releases the moment the statement
| finishes — the caller would get no serialisation and no warning.
|
| That guard cannot be tested under RefreshDatabase: it wraps every test in a
| transaction of its own, so `DB::transactionLevel()` is never 0 and the guard can
| never fire. DatabaseTruncation is used instead purely because it leaves the
| ambient transaction level alone. **Do not add RefreshDatabase to this file** —
| it would make both tests here vacuous while still reporting green.
|
| Nothing here writes, so there is nothing to clean up afterwards.
|
*/

uses(DatabaseTruncation::class);

it('refuses to pretend it is holding a lock outside a transaction', function () {
    expect(fn () => app(CoinLedger::class)->lockUser(1))
        ->toThrow(LogicException::class);
});

it('takes the lock when there is a transaction to hold it for', function () {
    // Proves the guard is level-sensitive rather than simply always throwing.
    DB::transaction(function () {
        expect(fn () => app(CoinLedger::class)->lockUser(1))->not->toThrow(LogicException::class);
    });
});

it('confirms the ambient transaction level this file depends on', function () {
    // If a future global `uses(RefreshDatabase::class)` lands in tests/Pest.php,
    // this fails loudly instead of quietly hollowing out the two tests above.
    expect(DB::transactionLevel())->toBe(0);
});
