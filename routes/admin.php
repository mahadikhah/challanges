<?php

use App\Http\Controllers\Admin\ChallengesController;
use App\Http\Controllers\Admin\InvitesController;
use App\Http\Controllers\Admin\PaymentsController;
use App\Http\Controllers\Admin\ReviewQueueController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SystemHealthController;
use App\Http\Controllers\Admin\UsersController;
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
    Route::redirect('/', '/admin/settings/economy')->name('home');

    // The panel is tabbed (mirroring the user-facing /settings): each tab is
    // its own route, and the bare URL lands on the first one. The write
    // routes are per-setting and tab-agnostic on purpose — an update or
    // reset never has to know which tab its key is presented on.
    Route::redirect('/settings', '/admin/settings/economy')->name('settings.index');
    Route::get('/settings/{tab}', [SettingsController::class, 'show'])->name('settings.show');
    Route::put('/settings/{setting}', [SettingsController::class, 'update'])->name('settings.update');
    Route::delete('/settings/{setting}', [SettingsController::class, 'destroy'])->name('settings.destroy');

    Route::get('/challenges', [ChallengesController::class, 'index'])->name('challenges.index');
    Route::get('/challenges/{challenge}', [ChallengesController::class, 'show'])->name('challenges.show');
    Route::post('/challenges/{challenge}/cancel', [ChallengesController::class, 'cancel'])->name('challenges.cancel');

    Route::get('/reviews', [ReviewQueueController::class, 'index'])->name('reviews.index');
    Route::get('/reviews/{checkIn}/proof', [ReviewQueueController::class, 'proof'])->name('reviews.proof');
    Route::post('/reviews/{checkIn}/approve', [ReviewQueueController::class, 'approve'])->name('reviews.approve');
    Route::post('/reviews/{checkIn}/reject', [ReviewQueueController::class, 'reject'])->name('reviews.reject');
    Route::post('/reviews/{checkIn}/override/{verdict}', [ReviewQueueController::class, 'override'])->name('reviews.override');

    Route::get('/users', [UsersController::class, 'index'])->name('users.index');
    Route::get('/users/{user}', [UsersController::class, 'show'])->name('users.show');
    Route::post('/users/{user}/coins', [UsersController::class, 'coins'])->name('users.coins');

    Route::get('/payments', [PaymentsController::class, 'index'])->name('payments.index');
    Route::post('/payments/{payment}/refund', [PaymentsController::class, 'refund'])->name('payments.refund');

    Route::get('/invites', [InvitesController::class, 'index'])->name('invites.index');

    Route::get('/system-health', [SystemHealthController::class, 'index'])->name('system-health.index');
    Route::post('/system-health/failed-jobs/{uuid}/retry', [SystemHealthController::class, 'retryFailedJob'])->name('system-health.failed-jobs.retry');
    Route::post('/system-health/failed-jobs/{uuid}/discard', [SystemHealthController::class, 'discardFailedJob'])->name('system-health.failed-jobs.discard');
});
