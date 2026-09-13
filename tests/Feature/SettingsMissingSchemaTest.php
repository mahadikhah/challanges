<?php

use App\Enums\SettingKey;
use App\Services\Settings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/*
| Settings must answer registry defaults while the schema has not been
| migrated yet. `composer setup` runs `key:generate` before `migrate --force`,
| the Telescope filter reads a threshold on the first query of the migration
| itself, and the exception-report path reads the alert gates when Telescope
| fails to store its first entry on a fresh database — CI crashed on exactly
| that last one until overrides() learned the difference between "not
| migrated yet" and "database broken".
|
| These tests deliberately avoid RefreshDatabase: they point the default
| connection at a private SQLite database that never gets migrated, and the
| trait's transaction would turn that swap into a cross-test schema bleed.
*/

beforeEach(function () {
    // A private, unmigrated SQLite database as the default connection. The
    // Setting model reads the default connection, and nothing else in this
    // file needs the test database.
    config([
        'database.default' => 'unmigrated',
        'database.connections.unmigrated' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ],
    ]);
});

it('answers the registry default when no table exists at all', function () {
    expect(app(Settings::class)->boolean(SettingKey::AlertsEnabled))
        ->toBe(SettingKey::AlertsEnabled->default());
});

it('answers the registry default when only the cache table is missing', function () {
    // The exact CI shape: the settings table exists but the database cache
    // store does not, so rememberForever fails before load() is ever reached.
    config([
        'cache.default' => 'database',
        'cache.stores.database.connection' => 'unmigrated',
    ]);

    Schema::connection('unmigrated')->create('settings', function ($table) {
        $table->string('key')->primary();
        $table->text('value')->nullable();
    });

    expect(app(Settings::class)->integer(SettingKey::AlertCooldownMinutes))
        ->toBe(SettingKey::AlertCooldownMinutes->default());
});

it('does not cache the pre-migration answer', function () {
    // An empty overrides entry written through rememberForever would outlive
    // the migration that creates the tables and hide admin overrides until a
    // cache flush — the reason the fallback memoises in memory only.
    app(Settings::class)->boolean(SettingKey::AlertsEnabled);

    expect(Cache::has(Settings::CACHE_KEY))->toBeFalse();
});

it('still throws when the database is broken rather than unmigrated', function () {
    // SQLite on a directory that cannot exist: a connection failure, not a
    // missing table, and it must propagate — an admin-tuned value silently
    // reverting to the default because the DB is down is the failure mode
    // this catch must never hide.
    config(['database.connections.unmigrated.database' => '/nonexistent-dir-'.uniqid().'/db.sqlite']);

    expect(fn () => app(Settings::class)->boolean(SettingKey::AlertsEnabled))
        ->toThrow(QueryException::class);
});
