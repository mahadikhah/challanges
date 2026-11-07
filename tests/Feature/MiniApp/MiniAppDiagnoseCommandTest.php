<?php

use App\Enums\SettingKey;
use App\Exceptions\InvalidInitDataException;
use App\Services\Settings;
use App\Services\Telegram\InitDataVerifier;
use App\Services\Telegram\VerifiedInitData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
 * `telegram:miniapp-diagnose` exists because the Mini App's failure modes are
 * invisible from both ends at once: the user sees one uniform sentence, and the
 * log sees one uniform refusal, whatever the cause. The cause is nearly always
 * server-side configuration — an unset token, a plain-HTTP URL — and this
 * command is where an operator can see it.
 *
 * Three properties matter more than the reporting and are held here hardest:
 * **the token is never printed** (there is no debugging session worth leaking a
 * bot token into), **the self-test can actually fail** (a check that passes no
 * matter what is worse than no check, because it is believed), and **the
 * outward half is faked rather than skipped** (the command now talks to Telegram
 * and to the Mini App URL, and CLAUDE.md forbids a test reaching either for
 * real).
 */

const DIAGNOSTIC_TOKEN = '424242:DIAGNOSTIC-TOKEN';

const MINIAPP_URL = 'https://challenges.test/miniapp';

/**
 * The three outward calls the command makes, faked to a healthy answer.
 *
 * Every test in this file goes through here, including the ones that predate the
 * outward checks: the command used to be offline, and adding a network call to
 * it silently turned "runs anywhere" into "reaches api.telegram.org" for tests
 * that never asked to.
 *
 * @param  array<string, mixed>  $menuButton
 */
function telegramIsHealthy(
    array $menuButton = ['type' => 'web_app', 'web_app' => ['url' => MINIAPP_URL]],
    string $username = 'challenges_test_bot',
    int $miniAppStatus = 200,
): void {
    Http::preventStrayRequests();

    Http::fake([
        'api.telegram.org/*/getMe' => Http::response([
            'ok' => true,
            'result' => [
                'id' => 42,
                'is_bot' => true,
                'first_name' => 'Diagnostics',
                'username' => $username,
            ],
        ]),
        'api.telegram.org/*/getChatMenuButton' => Http::response([
            'ok' => true,
            'result' => $menuButton,
        ]),
        'challenges.test/*' => Http::response('', $miniAppStatus),
    ]);
}

beforeEach(function () {
    config([
        'services.telegram.bot_token' => DIAGNOSTIC_TOKEN,
        'services.telegram.miniapp_url' => MINIAPP_URL,
    ]);
});

/**
 * Run the command and hand back both halves of what it did.
 *
 * @return array{0: int, 1: string}
 */
function diagnose(): array
{
    $exitCode = Artisan::call('telegram:miniapp-diagnose');

    return [$exitCode, Artisan::output()];
}

describe('a server that can verify initData', function () {
    // Laravel matches fake stubs in the order they were registered and takes the
    // first hit, so a default installed for every test cannot be overridden by a
    // later `Http::fake()` — it silently shadows it. The default therefore lives
    // on the describes whose world does not vary, and the describe below that is
    // *about* the varying answers states each one itself.
    beforeEach(fn () => telegramIsHealthy());

    it('passes its own self-test', function () {
        [$exitCode, $output] = diagnose();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('verifies');
    });

    it('proves the configured token works, rather than printing it', function () {
        // The length is the operator's evidence — "is this the 46-character
        // shape Telegram issues, or has the variable been pasted half-empty?"
        // The value itself belongs in the secret store and nowhere else, and
        // this includes anywhere it could later be found in a terminal
        // scrollback or a CI log.
        [$exitCode, $output] = diagnose();

        expect($exitCode)->toBe(0)
            ->and($output)->not->toContain(DIAGNOSTIC_TOKEN)
            ->not->toContain('DIAGNOSTIC-TOKEN')
            ->and($output)->toContain('set ('.strlen(DIAGNOSTIC_TOKEN).' characters)');
    });

    it('reports the token lifetime the exchange will actually use', function () {
        // The floor is applied at minting, so reporting the bare row would tell
        // an operator their app has a zero-minute token when it does not.
        app(Settings::class)->set(SettingKey::MiniAppTokenTtlMinutes, 0);

        [$exitCode, $output] = diagnose();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('floored to 1');
    });

    it('warns when the staleness window has been switched off', function () {
        // 0 disables the check rather than failing it, which is permissive
        // rather than broken — and therefore exactly the kind of setting that
        // gets flipped during an incident and never flipped back.
        app(Settings::class)->set(SettingKey::InitDataMaxAgeSeconds, 0);

        [$exitCode, $output] = diagnose();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('staleness accepted')
            ->and($output)->toContain('still accepted');
    });

    it('warns about a Mini App Telegram will not hand initData to', function () {
        // Telegram only hands `initData` to an HTTPS page, so a plain-HTTP URL
        // produces an app that boots to "open me from Telegram" while looking
        // perfectly reachable — the least guessable failure of the set.
        config(['services.telegram.miniapp_url' => 'http://challenges.test/miniapp']);

        [$exitCode, $output] = diagnose();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('not https')
            ->and($output)->toContain('HTTPS');
    });
});

describe('a server that cannot verify initData', function () {
    // A catch-all fake rather than nothing at all. With no fake installed the
    // `assertNothingSent()` below is vacuous — Laravel only records requests once
    // something has turned recording on — and a command that did reach for the
    // network here would reach it *for real*, against api.telegram.org, which
    // CLAUDE.md forbids outright.
    beforeEach(fn () => Http::fake());

    it('says so and fails, instead of reporting a self-test it could not run', function () {
        config(['services.telegram.bot_token' => null]);

        [$exitCode, $output] = diagnose();

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('TELEGRAM_BOT_TOKEN')
            ->and($output)->toContain('not set')

            // A green self-test on a server with no token would be the worst
            // possible output: it would send the operator looking at Telegram
            // for a problem that is one missing environment variable.
            ->not->toContain('verifies');
    });

    it('treats an empty token the same as an unset one', function () {
        config(['services.telegram.bot_token' => '']);

        expect(diagnose()[0])->toBe(1);
    });

    it('reports the missing token without blowing up on the way there', function () {
        // The Bot API client is resolved lazily for exactly this reason. The
        // container's binding for it throws when the token is empty, so an
        // injected `Api` would be resolved *before* the command body ran and
        // would turn the one misconfiguration this command exists to explain
        // into an uncaught stack trace.
        config(['services.telegram.bot_token' => '']);

        [$exitCode, $output] = diagnose();

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('not set');

        Http::assertNothingSent();
    });
});

describe('asking Telegram what it knows', function () {
    it('names the bot this token belongs to', function () {
        // The self-test signs with whatever token is configured, so it verifies
        // just as happily against a token belonging to an entirely different
        // bot. Only the other end can answer "is this even the right bot?", and
        // a wrong bot fails every real user's initData exactly the way a forged
        // one does.
        telegramIsHealthy();

        [$exitCode, $output] = diagnose();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('@challenges_test_bot')

            // Naming the bot must not become a way of naming the token.
            ->not->toContain(DIAGNOSTIC_TOKEN)
            ->not->toContain('DIAGNOSTIC-TOKEN');
    });

    it('fails when Telegram rejects the token outright', function () {
        Http::fake([
            'api.telegram.org/*/getMe' => Http::response([
                'ok' => false,
                'error_code' => 401,
                'description' => 'Unauthorized',
            ], 401),
            'challenges.test/*' => Http::response('', 200),
        ]);

        [$exitCode, $output] = diagnose();

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('refused the bot token')
            ->and($output)->not->toContain(DIAGNOSTIC_TOKEN)

            // A token Telegram will not accept explains every failure there is,
            // so claiming the local HMAC chain is fine would be worse than
            // saying nothing — it would read as "the token is good".
            ->not->toContain('verifies, and a tampered one is refused');
    });

    it('warns but carries on when Telegram is unreachable', function () {
        // A host with no route to api.telegram.org is a different problem from a
        // host that cannot authenticate anyone. Failing on the first would make
        // this command useless exactly when the network is what is broken, which
        // is also when an operator is most likely to be running it.
        Http::fake([
            'api.telegram.org/*' => fn () => throw new ConnectionException('Connection timed out'),
            'challenges.test/*' => Http::response('', 200),
        ]);

        [$exitCode, $output] = diagnose();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Could not reach Telegram')
            ->and($output)->toContain('verifies, and a tampered one is refused');
    });

    it('reports a menu button that points at this Mini App', function () {
        telegramIsHealthy();

        [$exitCode, $output] = diagnose();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain(MINIAPP_URL)
            ->and($output)->not->toContain('not the configured MINIAPP_URL');
    });

    it('warns when the menu button opens a different app', function () {
        // This is the failure that looks correct from both ends: the button
        // opens, the app boots, and the initData it carries is signed for a bot
        // this server does not hold. Everything is configured; none of it agrees.
        telegramIsHealthy(['type' => 'web_app', 'web_app' => ['url' => 'https://other.test/miniapp']]);

        [$exitCode, $output] = diagnose();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('https://other.test/miniapp')
            ->and($output)->toContain('not the configured MINIAPP_URL');
    });

    it('warns when the menu button is not a Mini App at all', function () {
        // `default` is Telegram's own menu button and `commands` is the command
        // list. Neither opens anything, so a user has nothing in the chat to
        // tap — and nothing in this app can tell them that.
        telegramIsHealthy(['type' => 'commands']);

        [$exitCode, $output] = diagnose();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('not a Mini App')
            ->and($output)->toContain('telegram:set-menu-button');
    });

    it('warns when the Mini App URL does not serve', function () {
        // A 404 on the SPA is invisible from inside Telegram — the app simply
        // never boots — and the shell's own error page hides it from ours too.
        // This request is the only vantage point outside both.
        telegramIsHealthy(miniAppStatus: 404);

        [$exitCode, $output] = diagnose();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('MINIAPP_URL responds')
            ->and($output)->toContain('404');
    });

    it('warns when the Mini App URL cannot be reached from this host', function () {
        // A warning rather than a failure: a host that cannot reach itself is
        // not evidence that Telegram cannot reach it.
        Http::preventStrayRequests();

        Http::fake([
            'api.telegram.org/*/getMe' => Http::response([
                'ok' => true,
                'result' => ['id' => 42, 'is_bot' => true, 'username' => 'challenges_test_bot'],
            ]),
            'api.telegram.org/*/getChatMenuButton' => Http::response([
                'ok' => true,
                'result' => ['type' => 'web_app', 'web_app' => ['url' => MINIAPP_URL]],
            ]),
            'challenges.test/*' => fn () => throw new ConnectionException('cURL error 28'),
        ]);

        [$exitCode, $output] = diagnose();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('MINIAPP_URL did not answer');
    });
});

describe('the self-test itself', function () {
    beforeEach(fn () => telegramIsHealthy());

    it('fails when the verifier accepts a payload it should not', function () {
        // The half that is easy to leave out and impossible to notice: a check
        // that only asserts "a good payload verifies" passes just as happily
        // against a verifier that returns `true` unconditionally. Binding one
        // of those proves the command would catch it.
        app()->bind(InitDataVerifier::class, fn (): InitDataVerifier => new class(app(Settings::class)) extends InitDataVerifier
        {
            public function verify(string $initData): VerifiedInitData
            {
                return new VerifiedInitData(['id' => 1, 'first_name' => 'Nobody'], []);
            }
        });

        [$exitCode, $output] = diagnose();

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('altered hash was accepted')
            ->not->toContain('verifies, and a tampered one is refused');
    });

    it('fails when the verifier refuses a payload that is signed correctly', function () {
        app()->bind(InitDataVerifier::class, fn (): InitDataVerifier => new class(app(Settings::class)) extends InitDataVerifier
        {
            public function verify(string $initData): VerifiedInitData
            {
                throw InvalidInitDataException::tampered();
            }
        });

        [$exitCode, $output] = diagnose();

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('correctly signed payload was refused');
    });
});

it('resolves through the container like every other Telegram command', function () {
    expect(array_keys(Artisan::all()))->toContain('telegram:miniapp-diagnose');
});
