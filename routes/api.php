<?php

use App\Http\Controllers\MiniApp\AuthController;
use App\Http\Controllers\MiniApp\ChallengeController;
use App\Http\Controllers\MiniApp\MeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mini App JSON API (versioned)
|--------------------------------------------------------------------------
|
| The standalone Mini App SPA consumes this versioned surface: it cannot be
| Inertia, because the Mini App's identity (initData) only arrives after the
| page has loaded — see CLAUDE.md's "Surfaces & Auth" for the settled why.
|
| Auth is one unauthenticated exchange — initData in, short-lived Sanctum
| bearer token out — and everything else hangs off `auth:sanctum` with the
| `miniapp` ability, so the SPA never holds anything longer-lived than the
| token or broader than the Mini App.
|
*/
Route::prefix('v1')->name('miniapp.')->group(function (): void {
    Route::get('/ping', fn () => response()->json(['ok' => true, 'surface' => 'api']))
        ->name('ping');

    Route::prefix('miniapp')->group(function (): void {
        Route::post('/auth', [AuthController::class, 'store'])->name('auth');

        Route::middleware(['auth:sanctum', 'ability:miniapp'])->group(function (): void {
            Route::get('/me', MeController::class)->name('me');
            Route::get('/challenges', [ChallengeController::class, 'index'])->name('challenges.index');
            Route::get('/challenges/{challenge}', [ChallengeController::class, 'show'])->name('challenges.show');
        });
    });
});
