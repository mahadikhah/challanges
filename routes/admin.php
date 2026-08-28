<?php

use App\Http\Controllers\Admin\ChallengesController;
use App\Http\Controllers\Admin\ReviewQueueController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Middleware\EnsureUserIsAdmin;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin Panel Routes (Inertia + React)
|--------------------------------------------------------------------------
|
| The admin surface: Fortify session authentication, then the `is_admin`
| gate on every request. `auth` runs first so a guest is redirected to the
| login page rather than refused with a bare 403.
|
| Every mutating route here re-derives the actor server-side and routes
| through the same Actions the bot and Mini App use — the panel gets no
| rules of its own.
|
*/

Route::middleware(['auth', EnsureUserIsAdmin::class])->group(function (): void {
    Route::redirect('/', '/admin/settings')->name('home');

    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::put('/settings/{setting}', [SettingsController::class, 'update'])->name('settings.update');
    Route::delete('/settings/{setting}', [SettingsController::class, 'destroy'])->name('settings.destroy');

    Route::get('/challenges', [ChallengesController::class, 'index'])->name('challenges.index');
    Route::get('/challenges/{challenge}', [ChallengesController::class, 'show'])->name('challenges.show');
    Route::post('/challenges/{challenge}/cancel', [ChallengesController::class, 'cancel'])->name('challenges.cancel');

    Route::get('/reviews', [ReviewQueueController::class, 'index'])->name('reviews.index');
    Route::get('/reviews/{checkIn}/proof', [ReviewQueueController::class, 'proof'])->name('reviews.proof');
    Route::post('/reviews/{checkIn}/approve', [ReviewQueueController::class, 'approve'])->name('reviews.approve');
    Route::post('/reviews/{checkIn}/reject', [ReviewQueueController::class, 'reject'])->name('reviews.reject');
});
