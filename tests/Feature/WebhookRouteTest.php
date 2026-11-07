<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
 * Route wiring only — the webhook's behaviour is covered in
 * tests/Feature/Bot/TelegramWebhookTest.php.
 *
 * The webhook route is stateless: registered outside the `web` group (no
 * session/cookies) and excluded from CSRF in bootstrap/app.php. Laravel
 * disables CSRF verification entirely in the test environment
 * (PreventRequestForgery::runningUnitTests()), so this test cannot assert a
 * 419 to prove the exemption — that was verified against the running container
 * (POST -> 200, not 419). Here we assert the route is registered under the name
 * the rest of the app builds URLs from, and that it acknowledges an update.
 */
it('registers the webhook route and acknowledges an update', function () {
    // Bot API is never hit in tests.
    Http::fake();
    Queue::fake();

    config([
        'services.telegram.webhook_secret' => 'route-test-path',
        'services.telegram.webhook_header_secret' => 'route-test-header',
    ]);

    expect(route('telegram.webhook', ['token' => 'route-test-path'], false))
        ->toBe('/telegram/webhook/route-test-path');

    $this->postJson(
        '/telegram/webhook/route-test-path',
        ['update_id' => 1],
        ['X-Telegram-Bot-Api-Secret-Token' => 'route-test-header'],
    )->assertOk()->assertJson(['ok' => true]);
});
