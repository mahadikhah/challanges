<?php

use App\Http\Controllers\LocaleController;
use App\Services\Localization;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

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
