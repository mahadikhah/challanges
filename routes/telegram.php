<?php

use App\Http\Controllers\Telegram\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Telegram Bot Webhook
|--------------------------------------------------------------------------
|
| Webhook-driven only — never long-polling in production. This route is
| stateless: registered outside the `web` group (no session/cookies) and
| excluded from CSRF (see bootstrap/app.php), because Telegram has no cookie
| jar and no token to send.
|
| The `{token}` segment is the first of two secrets; the second is the
| `X-Telegram-Bot-Api-Secret-Token` header. Both are verified in
| `WebhookRequest`, which answers 404 on a mismatch so a probe cannot tell
| this path from one that was never routed. It is declared optional so a
| call with no token reaches that check and gets the same 404 the router
| would have given, rather than a routing error that confirms the shape of
| the URL.
|
*/
Route::post('/telegram/webhook/{token?}', WebhookController::class)->name('telegram.webhook');
