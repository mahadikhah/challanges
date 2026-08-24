<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Telegram Bot Webhook
|--------------------------------------------------------------------------
|
| Webhook-driven only — never long-polling in production. These routes are
| stateless: they are registered outside the `web` group (no session/cookies)
| and excluded from CSRF (see bootstrap/app.php). Authenticity is verified in
| Bot Core Task 1 via the URL secret + `X-Telegram-Bot-Api-Secret-Token`
| header. The real handler records the update (idempotent on update_id),
| returns 200 immediately, then dispatches a queued job.
|
*/
Route::post('/telegram/webhook/{token?}', function (Request $request) {
    // Placeholder — real update handling arrives in Bot Core Task 1.
    return response()->json(['ok' => true]);
})->name('telegram.webhook');
