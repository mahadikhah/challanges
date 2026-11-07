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
| The filter reads the slow-query threshold from `Settings`. On the FIRST
| query of a fresh `migrate --force` neither the `settings` table nor the
| `cache` table exists yet, and before the fix the resulting QueryException
| escaped the migrator and aborted the command having applied nothing, on
| every documented install path. That tolerance now lives in
| `Settings::overrides()` itself — see SettingsMissingSchemaTest — so this
| file only has to prove the filter's own behaviour: what the bar drops,
| what it keeps, and that a genuinely broken database still propagates.
|
| These run the real registered filter via Telescope::recordQuery() rather
| than calling the private method, because the callback Telescope holds is
| what production actually executes.
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

describe('a real database failure', function () {
    it('still propagates rather than silently using the default', function () {
        // An admin-tuned threshold must not revert to the registry default
        // because the database is down: only a missing table is tolerated
        // (inside Settings::overrides()), and that can only happen before
        // migrations run.
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
