<?php

use App\Enums\MessagingPlatform;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * The migration this exercises is a real data migration — every deployment
 * already has users with `telegram_id` set, so the backfill has to carry them
 * across, not assume an empty table.
 *
 * Migrating down first (from the suite's fully-migrated state) is what makes
 * this test honest: the split migration then runs against rows that look like
 * production's.
 */
it('backfills existing telegram users onto the platform identity', function () {
    // Production-shaped rows: a messenger user, and an email-only admin.
    $messenger = User::factory()->telegram()->create();
    $admin = User::factory()->create();

    Artisan::call('migrate:rollback', [
        '--path' => 'database/migrations/2026_08_29_110000_split_platform_identity_on_users_table.php',
    ]);

    expect(Schema::hasColumn('users', 'telegram_id'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'platform'))->toBeFalse();

    // The rollback carried the identity back onto telegram_id; the admin's
    // stays null, exactly as in production.
    DB::table('users')->where('id', $messenger->getKey())->update(['telegram_id' => 777_600_1]);

    Artisan::call('migrate', [
        '--path' => 'database/migrations/2026_08_29_110000_split_platform_identity_on_users_table.php',
    ]);

    $migrated = DB::table('users')->where('id', $messenger->getKey())->first();

    expect($migrated->platform)->toBe('telegram')
        ->and((int) $migrated->platform_user_id)->toBe(777_600_1)
        ->and(Schema::hasColumn('users', 'telegram_id'))->toBeFalse();

    // The DDL above implicitly committed the test's transaction, so these rows
    // will not roll back with the suite — delete them or they leak into every
    // test file that runs after this one.
    DB::table('users')->whereIn('id', [$messenger->getKey(), $admin->getKey()])->delete();
});

it('keeps one account per platform id, refusing a duplicate identity', function () {
    $first = User::factory()->telegram(777_700_1)->create();

    expect(fn () => User::factory()->telegram(777_700_1)->create())
        ->toThrow(UniqueConstraintViolationException::class)
        ->and($first->refresh()->platform)->toBe(MessagingPlatform::Telegram);
});

it('creates the composite unique index on a fresh install', function () {
    $indexes = collect(DB::select('SHOW INDEXES FROM users'))
        ->filter(fn (stdClass $index): bool => (string) $index->Key_name === 'users_platform_platform_user_id_unique')
        ->map(fn (stdClass $index): string => (string) $index->Column_name)
        ->values();

    expect($indexes->all())->toBe(['platform', 'platform_user_id']);
});
