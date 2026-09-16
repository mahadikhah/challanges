<?php

use App\Enums\SettingKey;
use App\Exceptions\InvalidInitDataException;
use App\Services\Settings;
use App\Services\Telegram\InitDataVerifier;
use App\Services\Telegram\VerifiedInitData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

/*
 * `telegram:miniapp-diagnose` exists because the Mini App's failure modes are
 * invisible from both ends at once: the user sees one uniform sentence, and the
 * log sees one uniform refusal, whatever the cause. The cause is nearly always
 * server-side configuration — an unset token, a plain-HTTP URL — and this
 * command is where an operator can see it.
 *
 * Two properties matter more than the reporting and are held here hardest:
 * **the token is never printed** (there is no debugging session worth leaking a
 * bot token into), and **the self-test can actually fail** (a check that passes
 * no matter what is worse than no check, because it is believed).
 */

const DIAGNOSTIC_TOKEN = '424242:DIAGNOSTIC-TOKEN';

beforeEach(function () {
    config([
        'services.telegram.bot_token' => DIAGNOSTIC_TOKEN,
        'services.telegram.miniapp_url' => 'https://challenges.test/miniapp',
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
});

describe('the self-test itself', function () {
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
