<?php

use App\Enums\SettingKey;
use App\Providers\TelescopeServiceProvider;
use App\Services\Settings;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Telescope\EntryType;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;

uses(RefreshDatabase::class);

/*
| The filter reads the slow-query threshold from `Settings`, which needs the
| `settings` table and — because the cache store is the `database` driver — the
| `cache` table. Telescope's filter runs on the FIRST query of a fresh
| `migrate --force`, when neither table exists yet. Before the guard in
| TelescopeServiceProvider, that QueryException escaped the migrator and the
| command aborted having applied nothing, on every documented install path.
|
| These run the real registered filter via Telescope::recordQuery() rather than
| calling the private method, because the callback Telescope holds is what
| production actually executes.
|
| The missing-schema cases stub the exception rather than dropping real tables:
| the guard's discrimination between "table not there yet" and "database
| broken" is what is under test, and the end-to-end behaviour is proven by
| deploying the image against a genuinely fresh database. Reproducing it here
| with live DDL would auto-commit RefreshDatabase's test transaction and bleed
| one test's schema into the next.
*/

/**
 * Invoke the application's registered filter on one entry.
 *
 * `recordQuery()` is the public entry point the framework's own query listener
 * uses, and it consults the filter exactly as production does. (`record()`
 * itself is protected.)
 */
function filterAccepts(IncomingEntry $entry): bool
{
    Telescope::$entriesQueue = [];

    Telescope::startRecording();
    Telescope::recordQuery($entry);

    return Telescope::$entriesQueue !== [];
}

function queryEntry(float $timeMs): IncomingEntry
{
    return IncomingEntry::make(['sql' => 'select 1', 'time' => $timeMs])
        ->type(EntryType::QUERY);
}

/**
 * A QueryException shaped exactly like the one MySQL throws for a missing
 * table — SQLSTATE code `42S02` as a *string*, which is how the driver
 * reports it and what the guard matches on.
 */
function missingTableException(): QueryException
{
    $previous = new PDOException(
        'SQLSTATE[42S02]: Base table or view not found: 1146 Table \'challenges\'.'.
        '"settings" doesn\'t exist',
    );
    (new ReflectionProperty(PDOException::class, 'code'))->setValue($previous, '42S02');

    return new QueryException('mysql', 'select * from `settings`', [], $previous);
}

beforeEach(function () {
    // The filter short-circuits to "record everything" in local, which would
    // make every assertion below pass without reading a Setting at all.
    app()->detectEnvironment(fn () => 'production');

    // Telescope::filter() APPENDS rather than replaces, and every test's app
    // boot plus this re-registration would stack a filter per test. The chain
    // short-circuits on the first false, so a memoised earlier filter would
    // leave ours uninvoked and the assertions below vacuous. Reset to exactly
    // one filter: the one under test.
    Telescope::$filterUsing = [];

    (new TelescopeServiceProvider(app()))->register();
});

describe('the slow-query bar', function () {
    it('drops a query faster than the threshold', function () {
        expect(filterAccepts(queryEntry(10)))->toBeFalse();
    });

    it('keeps a query at or above the threshold', function () {
        $threshold = SettingKey::TelescopeSlowQueryMs->default();

        expect(filterAccepts(queryEntry((float) $threshold + 1)))->toBeTrue();
    });

    it('honours an admin override', function () {
        app(Settings::class)->set(SettingKey::TelescopeSlowQueryMs, 5);

        expect(filterAccepts(queryEntry(10)))->toBeTrue();
    });
});

describe('when the schema is not there yet', function () {
    it('does not let a missing table escape into the caller', function () {
        $this->mock(Settings::class)
            ->shouldReceive('integer')
            ->andThrow(missingTableException());

        // The assertion is the absence of a QueryException. Before the guard
        // this threw, which is precisely what aborted the migration.
        expect(fn () => filterAccepts(queryEntry(10)))->not->toThrow(QueryException::class);
    });

    it('recognises the sqlite spelling of a missing table too', function () {
        // The guard's comment promises both spellings: MySQL says 42S02,
        // sqlite says "no such table" — and the asset build runs on sqlite.
        $previous = new PDOException(
            'SQLSTATE[HY000]: General error: 1 no such table: settings',
        );
        $this->mock(Settings::class)
            ->shouldReceive('integer')
            ->andThrow(new QueryException('sqlite', 'select * from "settings"', [], $previous));

        expect(fn () => filterAccepts(queryEntry(10)))->not->toThrow(QueryException::class);
    });

    it('falls back to the registry default rather than recording everything', function () {
        $this->mock(Settings::class)
            ->shouldReceive('integer')
            ->andThrow(missingTableException());

        $threshold = (float) SettingKey::TelescopeSlowQueryMs->default();

        expect(filterAccepts(queryEntry($threshold - 1)))->toBeFalse()
            ->and(filterAccepts(queryEntry($threshold + 1)))->toBeTrue();
    });

    it('stops querying once it knows the tables are gone', function () {
        // `once()` is the assertion: a second read would re-issue the SELECT
        // that just failed, and a 39-migration run would pay that per query.
        $this->mock(Settings::class)
            ->shouldReceive('integer')
            ->once()
            ->andThrow(missingTableException());

        filterAccepts(queryEntry(10));
        filterAccepts(queryEntry(10));
    });
});

describe('a real database failure', function () {
    it('still propagates rather than silently using the default', function () {
        // An admin-tuned threshold must not revert to the registry default
        // because the database is down: only a missing table is tolerated,
        // and that can only happen before migrations run.
        $this->mock(Settings::class)
            ->shouldReceive('integer')
            ->andThrow(new QueryException(
                'mysql',
                'select * from `settings`',
                [],
                new PDOException('SQLSTATE[HY000] [2002] Connection refused'),
            ));

        expect(fn () => filterAccepts(queryEntry(10)))->toThrow(QueryException::class);
    });
});
