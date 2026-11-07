<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Log Viewer — the plain log files' UI — mounted at /admin/logs behind the
 * same session auth + is_admin check the Inertia panel and Telescope sit
 * behind, and nothing of its own: the package's standalone route and auth
 * never register a second door.
 */
it('redirects the log viewer\'s guest to login and refuses a non-admin', function (): void {
    $this->get('/admin/logs')->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())->get('/admin/logs')->assertForbidden();
});

it('admits an admin to the log viewer', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin/logs')
        ->assertOk();
});
