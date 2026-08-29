<?php

use App\Http\Controllers\Bale\BaleWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Bale Bot Webhook
|--------------------------------------------------------------------------
|
| Webhook-driven only, exactly like the Telegram route. Stateless: outside
| the `web` group and excluded from CSRF (see bootstrap/app.php), because
| Bale has no cookie jar and no token to send.
|
| The `{token}` segment is the *only* secret. Bale's `setWebhook` accepts
| just a URL — no `secret_token`, no signature header — so the secret path
| segment is the whole authenticity mechanism and must be long and random.
| It is declared optional so a call with no token reaches that check and
| gets the same 404 the router would have given, rather than a routing
| error that confirms the shape of the URL.
|
*/
Route::post('/bale/webhook/{token?}', BaleWebhookController::class)->name('bale.webhook');
