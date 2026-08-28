<?php

use App\Http\Controllers\LocaleController;
use App\Services\Localization;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/*
| The marketing landing. The bot link comes from config on every request —
| `Route::inertia` would freeze the prop at boot — and the page must render on
| a box where no bot is configured yet, so the CTA degrades to a note rather
| than disappearing the page's whole point.
*/
Route::get('/', function (): InertiaResponse {
    $username = (string) config('services.telegram.bot_username');

    return Inertia::render('welcome', [
        'bot_url' => $username === '' ? null : 'https://t.me/'.$username,
    ]);
})->name('home');

/*
| Language switching is open to guests — the public website and the login pages
| must be readable in Farsi before anyone has an account.
*/
Route::post('locale', [LocaleController::class, 'update'])->name('locale.update');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

/*
|--------------------------------------------------------------------------
| Telegram Mini App shell
|--------------------------------------------------------------------------
|
| The Mini App is a standalone React SPA (its own Vite entry) served from a
| single Blade shell. This catch-all lets the SPA own client-side routing
| under /miniapp/* while never swallowing /, /dashboard, /admin or /api. The
| SPA authenticates via Telegram initData -> Sanctum bearer token (Phase 5).
|
*/
Route::get('/miniapp/{any?}', fn (Localization $localization) => view('miniapp', [
    'localization' => $localization->payload(),
]))
    ->where('any', '.*')
    ->name('miniapp');

require __DIR__.'/settings.php';
