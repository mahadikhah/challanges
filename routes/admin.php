<?php

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
*/

Route::middleware(['auth', EnsureUserIsAdmin::class])->group(function (): void {
    Route::redirect('/', '/admin/settings')->name('home');

    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::put('/settings/{setting}', [SettingsController::class, 'update'])->name('settings.update');
    Route::delete('/settings/{setting}', [SettingsController::class, 'destroy'])->name('settings.destroy');
});
