<?php

use App\Models\TelegramUpdate;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Telegram\Bot\Api;

/*
 * `telegram:set-webhook` is what keeps the endpoint's two secrets in step. The
 * webhook accepts a call only when it carries both the path secret and the
 * `X-Telegram-Bot-Api-Secret-Token` header, and Telegram only sends that header if
 * it was registered with `secret_token`. Register with one half and every update is
 * refused as 404 — a bot that is silently dead with nothing in our own logs, because
 * the refusal happens before anything logs. These tests hold that contract.
 *
 * The command registers two things now — the webhook and the command menu — so an
 * assertion here says which call it means before it reads anything off it. The
 * menu half has its own file, `BotCommandMenuTest`.
 */

beforeEach(function () {
    config([
        'services.telegram.bot_token' => '123456:TEST-TOKEN',
        'services.telegram.webhook_secret' => 'path-secret',
        'services.telegram.webhook_header_secret' => 'header-secret',
    ]);

    // In console the URL generator takes its root and scheme from the request
    // Laravel synthesises at boot, so changing app.url mid-test would not reach
    // `route()`. Force both instead.
    URL::forceRootUrl('https://challenges.test');
    URL::forceScheme('https');

    // Telegram is never reached, on any path: an unstubbed call throws rather than
    // leaving the suite free to dial out. Each test states its own reply, because
    // `Http::fake()` *appends* to the stub list and the first match wins — a
    // catch-all registered here would silently shadow every one of them.
    Http::preventStrayRequests();
});

/**
 * Registers the one reply Telegram is allowed to give for the test at hand.
 *
 * @param  array<string, mixed>  $body
 */
function telegramReplies(array $body, int $status = 200): void
{
    Http::fake(['*' => Http::response($body, $status)]);
}

/**
 * Telegram's reply to getWebhookInfo, describing our own registration by default.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function webhookInfo(array $overrides = []): array
{
    return ['ok' => true, 'result' => array_replace([
        'url' => 'https://challenges.test/telegram/webhook/path-secret',
        'has_custom_certificate' => false,
        'pending_update_count' => 0,
        'allowed_updates' => TelegramUpdate::HANDLED_KINDS,
    ], $overrides)];
}

/**
 * Telegram's reply to a setWebhook that it accepted.
 */
function webhookAccepted(): void
{
    telegramReplies(['ok' => true, 'result' => true]);
}

/**
 * Whether a recorded request is the setWebhook call, as opposed to the
 * setMyCommands calls the same command also makes.
 *
 * `Http::assertSent()` runs its closure against every recorded request, so an
 * assertion that reads `url` or `drop_pending_updates` off whichever one it is
 * handed would be reading it off the wrong call half the time.
 */
function sentToTheWebhook(Request $request): bool
{
    return str_ends_with($request->url(), '/setWebhook');
}

describe('telegram:set-webhook', function () {
    it('registers the URL with the path secret and the header secret together', function () {
        webhookAccepted();

        $this->artisan('telegram:set-webhook')->assertSuccessful();

        Http::assertSent(function (Request $request): bool {
            if (! sentToTheWebhook($request)) {
                return false;
            }

            expect($request->url())->toBe('https://api.telegram.org/bot123456:TEST-TOKEN/setWebhook');

            parse_str($request->body(), $sent);

            // Both halves, in one call. This is the whole point of the command.
            expect($sent['url'])->toBe('https://challenges.test/telegram/webhook/path-secret')
                ->and($sent['secret_token'])->toBe('header-secret');

            return true;
        });
    });

    it('asks only for the update kinds the app handles', function () {
        webhookAccepted();

        $this->artisan('telegram:set-webhook')->assertSuccessful();

        Http::assertSent(function (Request $request): bool {
            if (! sentToTheWebhook($request)) {
                return false;
            }

            parse_str($request->body(), $sent);

            expect(json_decode((string) $sent['allowed_updates'], true))->toBe(TelegramUpdate::HANDLED_KINDS);

            return true;
        });
    });

    it('keeps pending updates unless told to drop them', function (array $options, string $expected) {
        webhookAccepted();

        $this->artisan('telegram:set-webhook', $options)->assertSuccessful();

        Http::assertSent(function (Request $request) use ($expected): bool {
            if (! sentToTheWebhook($request)) {
                return false;
            }

            parse_str($request->body(), $sent);

            return $sent['drop_pending_updates'] === $expected;
        });
    })->with([
        'default' => [[], '0'],
        'dropping' => [['--drop-pending-updates' => true], '1'],
    ]);

    it('does not print either secret', function () {
        webhookAccepted();

        $this->artisan('telegram:set-webhook')
            ->doesntExpectOutputToContain('path-secret')
            ->doesntExpectOutputToContain('header-secret')
            ->assertSuccessful();
    });

    it('refuses to register while a secret is missing', function (string $unset) {
        // Registering with half the pair produces a bot that looks configured and
        // answers nothing. Refuse before the call rather than after. Telegram is
        // stubbed as willing, so `assertNothingSent` means we chose not to ask.
        webhookAccepted();
        config(["services.telegram.{$unset}" => null]);

        $this->artisan('telegram:set-webhook')->assertFailed();

        Http::assertNothingSent();
    })->with([
        'no path secret' => 'webhook_secret',
        'no header secret' => 'webhook_header_secret',
    ]);

    it('refuses a non-HTTPS URL rather than letting Telegram reject it', function () {
        webhookAccepted();
        URL::forceRootUrl('http://localhost');
        URL::forceScheme('http');

        $this->artisan('telegram:set-webhook')
            ->expectsOutputToContain('HTTPS')
            ->assertFailed();

        Http::assertNothingSent();
    });

    it('reports Telegram’s own reason when Telegram refuses', function () {
        telegramReplies([
            'ok' => false,
            'error_code' => 400,
            'description' => 'Bad Request: bad webhook: an HTTPS URL must be provided',
        ], 400);

        $this->artisan('telegram:set-webhook')
            ->expectsOutputToContain('bad webhook')
            ->assertFailed();
    });
});

describe('telegram:webhook-info', function () {
    it('confirms Telegram is delivering here', function () {
        telegramReplies(webhookInfo());

        $this->artisan('telegram:webhook-info')
            ->expectsOutputToContain('delivering')
            ->assertSuccessful();
    });

    it('fails on a webhook pointing somewhere else', function () {
        telegramReplies(webhookInfo(['url' => 'https://old-deploy.test/telegram/webhook/path-secret']));

        $this->artisan('telegram:webhook-info')
            ->expectsOutputToContain('different URL')
            ->assertFailed();
    });

    it('reports the last delivery error, which is how a wrong secret token shows up', function () {
        // Telegram never returns the secret token, so a mismatch is only ever
        // visible as our own 404 coming back as its last error.
        telegramReplies(webhookInfo([
            'last_error_message' => 'Wrong response from the webhook: 404 Not Found',
        ]));

        $this->artisan('telegram:webhook-info')
            ->expectsOutputToContain('404 Not Found')
            ->assertFailed();
    });

    it('says so when no webhook is registered at all', function () {
        telegramReplies(webhookInfo(['url' => '']));

        $this->artisan('telegram:webhook-info')
            ->expectsOutputToContain('telegram:set-webhook')
            ->assertFailed();
    });

    it('does not print the secret it is checking', function () {
        telegramReplies(webhookInfo());

        $this->artisan('telegram:webhook-info')
            ->doesntExpectOutputToContain('path-secret')
            ->assertSuccessful();
    });

    it('reports a failure to reach Telegram rather than throwing', function () {
        Http::fake(['*' => Http::failedConnection()]);

        $this->artisan('telegram:webhook-info')->assertFailed();
    });
});

it('resolves both commands through the container', function () {
    // Guards the provider wiring: the commands type-hint Api, which only resolves
    // because our provider replaced the SDK's.
    expect(app(Api::class))->toBeInstanceOf(Api::class)
        ->and(array_keys(Artisan::all()))
        ->toContain('telegram:set-webhook')
        ->toContain('telegram:webhook-info');
});
