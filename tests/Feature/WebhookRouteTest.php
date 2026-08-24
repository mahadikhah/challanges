<?php

use Illuminate\Support\Facades\Http;

/*
 * The webhook route is stateless: registered outside the `web` group (no
 * session/cookies) and excluded from CSRF in bootstrap/app.php. Laravel
 * disables CSRF verification entirely in the test environment
 * (PreventRequestForgery::runningUnitTests()), so this test cannot assert a
 * 419 to prove the exemption — that was verified against the running container
 * (POST -> 200, not 419). Here we assert the route is registered and
 * acknowledges an update, which is the behaviour Telegram depends on.
 */
it('accepts a Telegram webhook POST and returns 200', function () {
    // Bot API is never hit in tests; fake defensively for future handlers.
    Http::fake();

    $this->postJson('/telegram/webhook/any-secret-token', [
        'update_id' => 1,
    ])->assertOk()->assertJson(['ok' => true]);
});
