<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * `telegram:set-menu-button` is the repo's answer to "how does a user open the
 * Mini App at all?". Registering the app with BotFather makes it reachable at a
 * URL nobody types; the menu button is the permanent tap target in the chat, and
 * until this command existed the repo had no way to set it — an operator had to
 * remember a BotFather screen, and the repo's own guides never mentioned it.
 *
 * Two properties are held here hardest. **Nothing is sent when MINIAPP_URL
 * cannot work** — Telegram rejects a non-HTTPS Web App button at the API, so a
 * pre-flight that sent anyway would turn a config mistake into a raw API error
 * for the operator and a dead button for every user. And **the read-back is
 * compared, not just printed**: `setChatMenuButton` answering `true` means
 * Telegram accepted the call, not that the button now points where it was asked
 * to.
 */

beforeEach(function () {
    config([
        'services.telegram.bot_token' => '123456:TEST-TOKEN',
        'services.telegram.miniapp_url' => 'https://challenges.test/miniapp',
    ]);

    // An unstubbed call throws rather than letting the suite dial out. Each test
    // states its own reply, because `Http::fake()` *appends* to the stub list and
    // the first match wins — a catch-all registered here would silently shadow
    // every one of them.
    Http::preventStrayRequests();
});

/**
 * Telegram accepting the call, and holding a button at `$stored` when asked.
 */
function menuButtonStored(string $stored): void
{
    Http::fake([
        'api.telegram.org/*/setChatMenuButton' => Http::response(['ok' => true, 'result' => true]),
        'api.telegram.org/*/getChatMenuButton' => Http::response([
            'ok' => true,
            'result' => [
                'type' => 'web_app',
                'web_app' => ['url' => $stored],
            ],
        ]),
    ]);
}

/**
 * Whether a recorded request is the call named, as opposed to the other one the
 * same command also makes.
 */
function sentTo(Request $request, string $endpoint): bool
{
    return str_ends_with($request->url(), '/'.$endpoint);
}

describe('telegram:set-menu-button', function () {
    it('points the button at the configured Mini App', function () {
        menuButtonStored('https://challenges.test/miniapp');

        $this->artisan('telegram:set-menu-button')
            ->expectsOutputToContain('Menu button registered.')
            ->assertSuccessful();

        Http::assertSent(function (Request $request): bool {
            if (! sentTo($request, 'setChatMenuButton')) {
                return false;
            }

            expect($request->url())->toBe('https://api.telegram.org/bot123456:TEST-TOKEN/setChatMenuButton');

            parse_str($request->body(), $sent);

            // Telegram's `menu_button` is a JSON-serialized object, not a nested
            // form field — sending it as an array would be silently ignored.
            expect(json_decode((string) $sent['menu_button'], true))->toBe([
                'type' => 'web_app',
                'web_app' => ['url' => 'https://challenges.test/miniapp'],
            ]);

            return true;
        });
    });

    it('leaves the button label to Telegram rather than hardcoding a language', function () {
        // `text` is optional and Telegram renders a localised default without it.
        // Any label written here would be one language shown to every user of a
        // bot that ships in two.
        menuButtonStored('https://challenges.test/miniapp');

        $this->artisan('telegram:set-menu-button')->assertSuccessful();

        Http::assertSent(function (Request $request): bool {
            if (! sentTo($request, 'setChatMenuButton')) {
                return false;
            }

            parse_str($request->body(), $sent);

            expect(json_decode((string) $sent['menu_button'], true))->not->toHaveKey('text');

            return true;
        });
    });

    it('reads the button back so one command both sets and confirms', function () {
        menuButtonStored('https://challenges.test/miniapp');

        $this->artisan('telegram:set-menu-button')
            ->expectsOutputToContain('https://challenges.test/miniapp')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => sentTo($request, 'getChatMenuButton'));
    });

    it('fails when Telegram stored a button pointing somewhere else', function () {
        // The failure this command exists to catch: everything looks configured
        // at both ends, the button opens, and the initData it carries is signed
        // for a bot this server does not hold.
        menuButtonStored('https://somewhere-else.test/miniapp');

        $this->artisan('telegram:set-menu-button')
            ->expectsOutputToContain('Telegram stored a different button')
            ->assertFailed();
    });

    it('succeeds when the read-back itself fails, since the button was set', function () {
        // Reporting a successful set as a failed command would send an operator
        // to fix something that is already right.
        Http::fake([
            'api.telegram.org/*/setChatMenuButton' => Http::response(['ok' => true, 'result' => true]),
            'api.telegram.org/*/getChatMenuButton' => Http::response([
                'ok' => false,
                'error_code' => 400,
                'description' => 'Bad Request: method not found',
            ], 400),
        ]);

        $this->artisan('telegram:set-menu-button')
            ->expectsOutputToContain('would not read it back')
            ->assertSuccessful();
    });

    it('fails when Telegram refuses the call', function () {
        Http::fake([
            'api.telegram.org/*/setChatMenuButton' => Http::response([
                'ok' => false,
                'error_code' => 401,
                'description' => 'Unauthorized',
            ], 401),
        ]);

        $this->artisan('telegram:set-menu-button')
            ->expectsOutputToContain('Telegram refused the menu button')
            ->assertFailed();
    });

    it('refuses an unset Mini App URL without calling Telegram', function () {
        config(['services.telegram.miniapp_url' => null]);

        // A catch-all fake, so `assertNothingSent()` below has something to be
        // wrong about: Laravel only records requests once something turns
        // recording on, and with no fake at all the assertion passes vacuously.
        Http::fake();

        $this->artisan('telegram:set-menu-button')
            ->expectsOutputToContain('Set MINIAPP_URL first')
            ->assertFailed();

        Http::assertNothingSent();
    });

    it('refuses a plain-HTTP Mini App URL without calling Telegram', function () {
        // Telegram rejects a non-HTTPS `web_app` button at the API, so sending
        // this would turn a config mistake into a raw API error for the operator
        // and a button that opens nothing for every user.
        config(['services.telegram.miniapp_url' => 'http://challenges.test/miniapp']);

        Http::fake();

        $this->artisan('telegram:set-menu-button')
            ->expectsOutputToContain('only accepts an HTTPS Mini App URL')
            ->assertFailed();

        Http::assertNothingSent();
    });
});
