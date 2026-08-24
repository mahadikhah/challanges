<?php

use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

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
Route::get('/miniapp/{any?}', fn () => view('miniapp'))
    ->where('any', '.*')
    ->name('miniapp');

require __DIR__.'/settings.php';
