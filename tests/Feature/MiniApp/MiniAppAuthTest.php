<?php

use App\Enums\CoinTransactionReason;
use App\Enums\SettingKey;
use App\Models\User;
use App\Services\CoinLedger;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;

uses(RefreshDatabase::class);

/*
 * The Mini App's identity exchange: `POST /api/v1/miniapp/auth` swaps a signed
 * `Telegram.WebApp.initData` for a short-lived Sanctum bearer token, and the
 * authenticated surface re-resolves the actor from that token alone.
 *
 * This file owns two of main.md §6's verification targets: a tampered
 * initData hash must be rejected, and so must a stale `auth_date`. The hashes
 * here are computed exactly the way `InitDataVerifier` expects them — the
 * same HMAC chain Telegram performs client-side — so a forged payload is
 * forged the only way a real one could be: by changing the contents after
 * signing, or by signing them with the wrong key.
 */

const BOT_TOKEN = '123456:TEST-TOKEN';

beforeEach(function () {
    config(['services.telegram.bot_token' => BOT_TOKEN]);

    $this->settings = app(Settings::class);
});

/**
 * A well-formed initData string, signed the way Telegram signs it.
 *
 * Fields land in a deliberately unsorted order, because alphabetical order is
 * something the verifier must impose, not something it may assume.
 *
 * @param  array<string, mixed>  $user  the `user` object, JSON-encoded as Telegram sends it
 * @param  array<string, string>  $extra  additional fields, e.g. a `start_param`
 * @param  int|null  $authDate  Unix timestamp; null means "now"
 */
function initData(
    array $user = [
        'id' => 777_000_5,
        'first_name' => 'Sara',
        'last_name' => 'Ahmadi',
        'username' => 'sarahmad',
        'language_code' => 'en',
    ],
    array $extra = [],
    ?int $authDate = null,
): string {
    $fields = array_merge($extra, [
        'auth_date' => (string) ($authDate ?? now()->getTimestamp()),
        'query_id' => 'AAF1q2W4vQ5x8z0b3C6d9E2f',
        'user' => json_encode($user, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    ]);

    ksort($fields);

    // Telegram signs every field except `hash` (not present yet) and
    // `signature` — the third-party path, which is exactly what the
    // "excludes the optional signature" test hangs a hat on.
    $signed = array_filter(
        $fields,
        fn (string $key): bool => $key !== 'signature',
        ARRAY_FILTER_USE_KEY,
    );

    $checkString = implode(
        "\n",
        array_map(fn (string $key): string => $key.'='.$signed[$key], array_keys($signed)),
    );

    $secretKey = hash_hmac('sha256', BOT_TOKEN, 'WebAppData');

    $fields['hash'] = hash_hmac('sha256', $checkString, $secretKey);

    return http_build_query($fields);
}

/**
 * initData signed with an arbitrary secret, for attacks rather than sessions.
 *
 * @param  array<string, string>  $fields
 */
function initDataSignedWith(string $botToken, array $fields): string
{
    ksort($fields);

    $checkString = implode(
        "\n",
        array_map(fn (string $key): string => $key.'='.$fields[$key], array_keys($fields)),
    );

    $fields['hash'] = hash_hmac(
        'sha256',
        $checkString,
        hash_hmac('sha256', $botToken, 'WebAppData'),
    );

    return http_build_query($fields);
}

/**
 * The token exchange, as the SPA makes it.
 */
function exchangesInitData(string $initData): TestResponse
{
    return test()->postJson('/api/v1/miniapp/auth', ['init_data' => $initData]);
}

/**
 * A Mini App session established the honest way, returning the bearer token.
 */
function aMiniAppSession(): string
{
    $response = exchangesInitData(initData());
    $response->assertOk();

    return $response->json('token');
}

describe('the token exchange', function () {
    it('issues a bearer token for a valid initData', function () {
        // Frozen at a whole second: the TTL assertions below compare
        // timestamps, and a microsecond boundary would make them flaky.
        test()->travelTo(now()->startOfSecond());

        $response = exchangesInitData(initData());

        $token = $response->json('token');
        $row = PersonalAccessToken::query()->sole();

        expect($response)
            ->assertOk()
            ->and($response->json('token_type'))->toBe('Bearer')
            ->and($token)->toMatch('/^\d+\|[\w\d]+$/')

            // The token the client holds is not the token in the table:
            // only its SHA-256 is stored.
            ->and($row->token)->toBe(hash('sha256', explode('|', $token)[1]))
            ->and($row->name)->toBe('miniapp')
            ->and($row->abilities)->toBe(['miniapp'])

            // The TTL is the admin-tuned setting, not a constant here.
            ->and($row->expires_at->format('Y-m-d H:i:s'))->toBe(now()->addMinutes(60)->format('Y-m-d H:i:s'))
            ->and($response->json('expires_at'))->toBe(now()->addMinutes(60)->toIso8601String());

        $user = User::query()->sole();

        expect($user)
            ->platform_user_id->toBe(777_000_5)
            ->first_name->toBe('Sara')
            ->telegram_username->toBe('sarahmad')
            ->and($response->json('user'))->toBe([
                'id' => $user->getKey(),
                'first_name' => 'Sara',
                'username' => 'sarahmad',
                'locale' => 'en',
            ]);
    });

    it('honours a tightened token lifetime', function () {
        test()->travelTo(now()->startOfSecond());
        $this->settings->set(SettingKey::MiniAppTokenTtlMinutes, 5);

        $response = exchangesInitData(initData());

        $response->assertOk();
        expect($response->json('expires_at'))->toBe(now()->addMinutes(5)->toIso8601String());
    });

    it('resolves to the user the bot already knows, without clobbering their chosen locale', function () {
        // They set Farsi in the bot; their Telegram client language must not
        // quietly flip it back on the next Mini App open.
        User::factory()->telegram(777_000_5)->preferring('fa')->create();

        exchangesInitData(initData())->assertOk();

        $user = User::query()->sole();

        expect($user->locale)->toBe('fa')
            ->and($user->first_name)->toBe('Sara')
            ->and(User::query()->count())->toBe(1);
    });

    it('signs in a user whose name is not ASCII', function () {
        $response = exchangesInitData(initData([
            'id' => 777_000_6,
            'first_name' => 'زهرا',
            'language_code' => 'fa',
        ]));

        $response->assertOk();

        $user = User::query()->sole();

        expect($user->first_name)->toBe('زهرا')
            ->and($user->locale)->toBe('fa');
    });

    it('excludes the optional signature from the check string', function () {
        // A third-party-verifiable `signature` may ride along; ours must not
        // be asked to check it, and it must not break ours.
        exchangesInitData(initData(extra: [
            'signature' => 'TW90aGFtbWVkIEthcmltIGRvZXMgbm90IHNpZ24gaGVyZQ',
        ]))->assertOk();
    });
});

describe('a rejected exchange', function () {
    it('rejects a tampered hash', function () {
        $initData = initData();
        parse_str($initData, $fields);
        $fields['hash'] = strrev($fields['hash']);

        $response = exchangesInitData(http_build_query($fields));

        $response->assertStatus(401);

        // One refusal message for every reason — "tampered" versus
        // "outdated" is a log line, not reconnaissance for the attacker.
        expect($response->json('message'))->toBe('The Mini App identity could not be verified.')

            // Nothing was created: no user for a forged identity, and no
            // token that could ever have been spent.
            ->and(User::query()->count())->toBe(0)
            ->and(PersonalAccessToken::query()->count())->toBe(0);
    });

    it('rejects a payload signed over different contents', function () {
        // The hash is genuinely a hash — of a payload that said something
        // else. Swapping the user in afterwards must not authenticate it.
        $initData = initData();
        parse_str($initData, $fields);
        $fields['user'] = json_encode([
            'id' => 777_000_5,
            'first_name' => 'Sara',
            'username' => 'sarahmad',
            'language_code' => 'en',
            'is_premium' => true,
        ], JSON_THROW_ON_ERROR);

        exchangesInitData(http_build_query($fields))->assertStatus(401);

        expect(User::query()->count())->toBe(0);
    });

    it('rejects a stale auth_date', function () {
        // One second past the default 3600s window.
        exchangesInitData(initData(authDate: now()->getTimestamp() - 3601))
            ->assertStatus(401);

        expect(User::query()->count())->toBe(0);
    });

    it('accepts an auth_date exactly at the window’s edge', function () {
        exchangesInitData(initData(authDate: now()->getTimestamp() - 3600))->assertOk();
    });

    it('honours a tightened freshness window', function () {
        $this->settings->set(SettingKey::InitDataMaxAgeSeconds, 60);

        exchangesInitData(initData(authDate: now()->getTimestamp() - 120))
            ->assertStatus(401);
    });

    it('rejects an unsigned payload', function () {
        $initData = initData();
        parse_str($initData, $fields);
        unset($fields['hash']);

        exchangesInitData(http_build_query($fields))->assertStatus(401);
    });

    it('rejects a correctly signed payload with no user to authenticate', function () {
        $initData = initDataSignedWith(BOT_TOKEN, [
            'auth_date' => (string) now()->getTimestamp(),
            'query_id' => 'AAF1q2W4vQ5x8z0b3C6d9E2f',
        ]);

        exchangesInitData($initData)->assertStatus(401);

        expect(User::query()->count())->toBe(0);
    });

    it('rejects a signed payload whose user object has no id', function () {
        exchangesInitData(initData(['first_name' => 'Nobody']))->assertStatus(401);

        expect(User::query()->count())->toBe(0);
    });

    it('refuses initData signed with somebody else’s bot token', function () {
        // The attack that matters: a genuine Telegram signature, produced by
        // a different bot's Mini App.
        $initData = initDataSignedWith('999999:OTHER-BOT', [
            'auth_date' => (string) now()->getTimestamp(),
            'query_id' => 'AAF1q2W4vQ5x8z0b3C6d9E2f',
            'user' => json_encode(['id' => 777_000_5, 'first_name' => 'Sara'], JSON_THROW_ON_ERROR),
        ]);

        exchangesInitData($initData)->assertStatus(401);

        expect(User::query()->count())->toBe(0);
    });

    it('refuses a missing or malformed body', function (array $body) {
        test()->postJson('/api/v1/miniapp/auth', $body)->assertStatus(422);
    })->with([
        'no field at all' => [[]],
        'empty string' => [['init_data' => '']],
        'not a string' => [['init_data' => ['an' => 'array']]],
    ]);
});

describe('the authenticated surface', function () {
    it('serves the token’s own user at /me', function () {
        $token = aMiniAppSession();

        // A balance to prove the ledger is behind the number, not a constant.
        $user = User::query()->sole();
        app(CoinLedger::class)->credit(
            $user,
            25,
            CoinTransactionReason::AdminCredit,
            'test:miniapp-opening-balance',
        );

        $me = test()->withToken($token)->getJson('/api/v1/miniapp/me');

        $me->assertOk();
        expect($me->json())->toBe([
            'id' => $user->getKey(),
            'first_name' => 'Sara',
            'username' => 'sarahmad',
            'locale' => 'en',
            'coins' => 25,
        ]);
    });

    it('refuses /me without a token', function () {
        test()->getJson('/api/v1/miniapp/me')->assertStatus(401);
    });

    it('refuses a token past its lifetime', function () {
        $token = aMiniAppSession();

        test()->travelTo(now()->addMinutes(61));

        test()->withToken($token)->getJson('/api/v1/miniapp/me')->assertStatus(401);
    });

    it('refuses a token without the miniapp ability', function () {
        $user = User::factory()->telegram(888_000_1)->create();
        $plain = $user->createToken('elsewhere', ['elsewhere'])->plainTextToken;

        test()->withToken($plain)->getJson('/api/v1/miniapp/me')->assertStatus(403);
    });
});
