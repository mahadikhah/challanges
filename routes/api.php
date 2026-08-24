<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

/*
|--------------------------------------------------------------------------
| Mini App JSON API (versioned)
|--------------------------------------------------------------------------
|
| The standalone Mini App SPA consumes this versioned surface. Authentication
| (Telegram initData -> short-lived Sanctum bearer token) and the real
| endpoints are built in Phase 5; this is the registered-but-empty scaffold.
|
*/
Route::prefix('v1')->group(function (): void {
    Route::get('/ping', fn () => response()->json(['ok' => true, 'surface' => 'api']));

    Route::prefix('miniapp')->name('miniapp.')->group(function (): void {
        // /api/v1/miniapp/* — Phase 5 (auth, challenge details, status, progress).
    });
});
