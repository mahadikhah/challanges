<?php

use App\Enums\SettingKey;
use App\Enums\SettingType;
use App\Models\Setting;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * @return array<string, array{SettingKey}>
 */
function settingKeyDataset(): array
{
    return collect(SettingKey::cases())
        ->mapWithKeys(fn (SettingKey $key): array => [$key->value => [$key]])
        ->all();
}

describe('the registry', function () {
    it('declares a default that matches its own type', function (SettingKey $key) {
        expect($key->type()->matches($key->default()))->toBeTrue();
    })->with(settingKeyDataset());

    it('covers every tunable CLAUDE.md requires to be admin-configurable', function () {
        expect(array_column(SettingKey::cases(), 'value'))
            ->toContain(
                'invite_coin_reward',
                'create_slot_coin_price',
                'join_slot_coin_price',
                'freeze_coin_price',
                'challenge_completion_coin_reward',
                'stars_packages',
                'required_channel',
                'default_challenge_freezes',
            );
    });

    it('exposes every tunable for the admin panel to render', function () {
        expect(array_keys(app(Settings::class)->all()))
            ->toBe(array_column(SettingKey::cases(), 'value'));
    });
});

describe('defaults', function () {
    it('resolves against an empty table, so a missing seeder cannot take pricing down', function () {
        expect(app(Settings::class)->integer(SettingKey::FreezeCoinPrice))
            ->toBe(SettingKey::FreezeCoinPrice->default())
            ->and(Setting::query()->count())->toBe(0);
    });

    it('ships the free baseline of one challenge created and one joined', function () {
        $settings = app(Settings::class);

        expect($settings->integer(SettingKey::FreeCreateSlots))->toBe(1)
            ->and($settings->integer(SettingKey::FreeJoinSlots))->toBe(1);
    });

    it('ships Stars packages the payments flow can turn into an invoice', function () {
        $packages = app(Settings::class)->array(SettingKey::StarsPackages);

        expect($packages)->not->toBeEmpty();

        foreach ($packages as $package) {
            expect($package)->toBeArray()->toHaveKeys(['stars', 'coins'])
                ->and($package['stars'])->toBeInt()->toBeGreaterThan(0)
                ->and($package['coins'])->toBeInt()->toBeGreaterThan(0);
        }
    });

    it('reads env-derived defaults through config, so a fresh deployment is correct before any tuning', function () {
        Config::set('services.telegram.required_channel', '@challenges_announcements');

        expect(app(Settings::class)->string(SettingKey::RequiredChannel))
            ->toBe('@challenges_announcements');
    });
});

describe('overrides', function () {
    it('prefers a stored override over the shipped default', function () {
        Setting::factory()->override(SettingKey::FreezeCoinPrice, 99)->create();

        expect(app(Settings::class)->integer(SettingKey::FreezeCoinPrice))->toBe(99);
    });

    it('persists an override and serves it immediately', function () {
        $settings = app(Settings::class);

        // Warm the cache first, so this also proves the write invalidates it.
        $settings->integer(SettingKey::CreateSlotCoinPrice);
        $settings->set(SettingKey::CreateSlotCoinPrice, 250);

        expect($settings->integer(SettingKey::CreateSlotCoinPrice))->toBe(250)
            ->and(Setting::query()->where('key', 'create_slot_coin_price')->count())->toBe(1);
    });

    it('updates the existing row rather than stacking duplicates', function () {
        $settings = app(Settings::class);

        $settings->set(SettingKey::InviteCoinReward, 20);
        $settings->set(SettingKey::InviteCoinReward, 30);

        expect(Setting::query()->count())->toBe(1)
            ->and($settings->integer(SettingKey::InviteCoinReward))->toBe(30);
    });

    it('reverts to the shipped default when the override is forgotten', function () {
        $settings = app(Settings::class);
        $settings->set(SettingKey::FreezeCoinPrice, 99);

        $settings->forget(SettingKey::FreezeCoinPrice);

        expect($settings->integer(SettingKey::FreezeCoinPrice))
            ->toBe(SettingKey::FreezeCoinPrice->default())
            ->and(Setting::query()->count())->toBe(0);
    });

    it('round-trips a structured value without flattening it to a string', function () {
        $settings = app(Settings::class);
        $packages = [['stars' => 25, 'coins' => 25], ['stars' => 50, 'coins' => 55]];

        $settings->set(SettingKey::StarsPackages, $packages);
        $stored = $settings->array(SettingKey::StarsPackages);

        /*
        | MySQL's native JSON type sorts object keys, so a strict `toBe()` would
        | fail on the keys within each package. Order *within the list* is
        | preserved, and that is the part users see.
        */
        expect($stored)->toEqual($packages)
            ->and($stored[0])->toMatchArray(['stars' => 25, 'coins' => 25])
            ->and($stored[1])->toMatchArray(['stars' => 50, 'coins' => 55]);
    });

    it('ignores a row whose key is no longer in the registry', function () {
        DB::table((new Setting)->getTable())->insert([
            'key' => 'a_tunable_we_removed',
            'value' => json_encode(1),
        ]);

        expect(app(Settings::class)->all())
            ->not->toHaveKey('a_tunable_we_removed')
            ->and(array_keys(app(Settings::class)->all()))
            ->toBe(array_column(SettingKey::cases(), 'value'));
    });
});

describe('write validation', function () {
    it('refuses a value that does not match the declared type', function (SettingKey $key, mixed $value) {
        expect(fn (): Setting => app(Settings::class)->set($key, $value))
            ->toThrow(InvalidArgumentException::class);

        expect(Setting::query()->count())->toBe(0);
    })->with([
        'numeric string into an integer price' => [SettingKey::FreezeCoinPrice, '15'],
        'boolean into an integer price' => [SettingKey::FreezeCoinPrice, true],
        'float into an integer price' => [SettingKey::FreezeCoinPrice, 15.5],
        'null into an integer price' => [SettingKey::FreezeCoinPrice, null],
        'integer into a text setting' => [SettingKey::RequiredChannel, 5],
        'string into a json setting' => [SettingKey::StarsPackages, 'nope'],
    ]);

    it('names the expected shape so an admin form can surface it', function () {
        expect(fn (): Setting => app(Settings::class)->set(SettingKey::FreezeCoinPrice, '15'))
            ->toThrow(InvalidArgumentException::class, 'Setting [freeze_coin_price] expects an integer.');
    });

    it('rejects the wrong accessor for a key, catching the mistake at the call site', function () {
        $settings = app(Settings::class);

        expect(fn (): int => $settings->integer(SettingKey::StarsPackages))
            ->toThrow(LogicException::class)
            ->and(fn (): string => $settings->string(SettingKey::FreezeCoinPrice))
            ->toThrow(LogicException::class)
            ->and(fn (): array => $settings->array(SettingKey::RequiredChannel))
            ->toThrow(LogicException::class);
    });

    it('falls back to the default when a stored value has the wrong shape', function () {
        // A hand-edited row must not be able to break check-ins.
        Setting::factory()->override(SettingKey::FreezeCoinPrice, 'corrupt')->create();

        expect(app(Settings::class)->integer(SettingKey::FreezeCoinPrice))
            ->toBe(SettingKey::FreezeCoinPrice->default());
    });
});

describe('caching', function () {
    it('reads the whole table once, however many tunables a request touches', function () {
        $settings = app(Settings::class);
        $settings->flush();

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $settings->integer(SettingKey::InviteCoinReward);
        $settings->integer(SettingKey::FreezeCoinPrice);
        $settings->integer(SettingKey::FreeJoinSlots);
        $settings->array(SettingKey::StarsPackages);

        expect($queries)->toBe(1);
    });

    it('requires a flush when the table is written to behind the service', function () {
        $settings = app(Settings::class);
        $settings->integer(SettingKey::FreezeCoinPrice);

        Setting::factory()->override(SettingKey::FreezeCoinPrice, 99)->create();

        expect($settings->integer(SettingKey::FreezeCoinPrice))
            ->toBe(SettingKey::FreezeCoinPrice->default());

        $settings->flush();

        expect($settings->integer(SettingKey::FreezeCoinPrice))->toBe(99);
    });

    it('is resolved as a singleton, so a write cannot leave a stale copy elsewhere', function () {
        expect(app(Settings::class))->toBe(app(Settings::class));

        app(Settings::class)->set(SettingKey::FreezeCoinPrice, 99);

        expect(app(Settings::class)->integer(SettingKey::FreezeCoinPrice))->toBe(99);
    });
});

describe('type matching', function () {
    it('accepts only its own shape', function (SettingType $type, mixed $value, bool $expected) {
        expect($type->matches($value))->toBe($expected);
    })->with([
        'text accepts a string' => [SettingType::Text, 'abc', true],
        'text rejects an integer' => [SettingType::Text, 1, false],
        'integer accepts an integer' => [SettingType::Integer, 1, true],
        'integer rejects a numeric string' => [SettingType::Integer, '1', false],
        'integer rejects a boolean' => [SettingType::Integer, true, false],
        'integer rejects a float' => [SettingType::Integer, 1.5, false],
        'boolean accepts false' => [SettingType::Boolean, false, true],
        'boolean rejects zero' => [SettingType::Boolean, 0, false],
        'json accepts an array' => [SettingType::Json, ['a'], true],
        'json rejects a string' => [SettingType::Json, 'a', false],
        'every type rejects null' => [SettingType::Text, null, false],
    ]);
});
