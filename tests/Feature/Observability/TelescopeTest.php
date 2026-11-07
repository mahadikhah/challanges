<?php

use App\Enums\SettingKey;
use App\Models\Setting;
use App\Models\User;
use App\Services\Settings;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(DatabaseTruncation::class);

/*
 * Telescope in production-safe mode: the filter in TelescopeServiceProvider
 * drops everything except exceptions, 4xx/5xx requests, failed jobs and
 * slow queries; the UI sits behind the same auth + admin gate as the panel;
 * and secrets never reach a stored entry.
 *
 * The test environment is not `local`, so the non-local filter is exactly
 * what these exercises run under. Telescope stays off for every other test
 * (phpunit.xml sets TELESCOPE_ENABLED=false); here it must be on *at app
 * boot*, because the vendor provider registers routes, watchers and the
 * recording state only when enabled then — a `config()` in beforeEach comes
 * too late. So each test boots a fresh application with the env flipped,
 * and afterEach restores it.
 *
 * DatabaseTruncation rather than RefreshDatabase: Telescope writes land in
 * the same database, and a mid-test `refreshApplication()` escapes the
 * transaction RefreshDatabase would roll back, which would otherwise bleed
 * one test's entries into the next.
 */

beforeEach(function (): void {
    $_ENV['TELESCOPE_ENABLED'] = 'true';
    $this->refreshApplication();

    // Routes that produce the responses the filter is supposed to care
    // about. 404s already exist everywhere; a 500 and an oversized body
    // need one of our own.
    Route::get('_telescope/boom', fn () => throw new RuntimeException('telescope-test-boom'));
    Route::get('_telescope/huge', fn () => response()->make(
        str_repeat('a', 10_000),
        422,
        ['Content-Type' => 'text/plain'],
    ));
});

afterEach(function (): void {
    $_ENV['TELESCOPE_ENABLED'] = 'false';
});

/**
 * @return list<object>
 */
function telescopeEntries(string $type): array
{
    return DB::table('telescope_entries')->where('type', $type)->get()->all();
}

function telescopeRequestContent(): ?object
{
    $row = DB::table('telescope_entries')->where('type', 'request')->first();

    return $row === null ? null : json_decode($row->content);
}

it('redirects the telescope UI\'s guest to login and refuses a non-admin', function (): void {
    $this->get('/telescope')->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())->get('/telescope')->assertForbidden();
});

it('admits an admin to the telescope UI', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/telescope')
        ->assertOk();
});

it('records nothing for a successful request outside local', function (): void {
    $this->get('/up')->assertOk();

    expect(telescopeEntries('request'))->toBe([]);
});

it('records a 4xx request', function (): void {
    $this->get('/definitely-not-a-route')->assertNotFound();

    $content = telescopeRequestContent();

    expect($content)->not->toBeNull()
        ->and($content->response_status)->toBe(404);
});

it('records a 5xx response and its exception', function (): void {
    $this->get('/_telescope/boom')->assertStatus(500);

    $content = telescopeRequestContent();

    expect($content->response_status)->toBe(500)
        ->and(telescopeEntries('exception'))->not->toBeEmpty();
});

it('records a job\'s final status — failed when it fails, processed when it does not', function (): void {
    config(['queue.default' => 'database']);

    dispatch(fn () => throw new RuntimeException('telescope-test-job'));
    dispatch(fn () => true);

    // --memory: the worker shares this PHP process with the whole suite, so
    // by now its usage is far above the worker's default 128M limit — without
    // the raise the worker stops after the first job and the second is never
    // processed.
    $this->artisan('queue:work', ['--stop-when-empty' => true, '--max-time' => 30, '--memory' => 1024]);

    $statuses = array_map(
        fn (object $entry): string => (string) (json_decode($entry->content)->status ?? ''),
        telescopeEntries('job'),
    );

    // Both statuses must land: the pending entry is stored at dispatch and
    // the outcome arrives as an update against it, so a missing 'failed'
    // means the update chain is broken (and failed jobs record nothing).
    expect($statuses)->toContain('failed')
        ->and($statuses)->toContain('processed')
        ->and(count($statuses))->toBe(2);
});

it('prunes telescope entries past the retention setting and keeps fresher ones', function (): void {
    Setting::factory()->override(SettingKey::TelescopePruneHours, 1)->create();

    $stale = Str::uuid()->toString();
    $fresh = Str::uuid()->toString();

    foreach ([$stale => now()->subDays(2), $fresh => now()] as $uuid => $createdAt) {
        DB::table('telescope_entries')->insert([
            'uuid' => $uuid,
            'batch_id' => (string) Str::uuid(),
            'type' => 'request',
            'content' => json_encode(['response_status' => 404]),
            'created_at' => $createdAt,
        ]);
    }

    $this->artisan('observability:prune-telescope')->assertExitCode(0);

    expect(DB::table('telescope_entries')->where('uuid', $stale)->exists())->toBeFalse()
        ->and(DB::table('telescope_entries')->where('uuid', $fresh)->exists())->toBeTrue();
});

it('keeps a slow query but drops a fast one', function (): void {
    $threshold = app(Settings::class)->integer(SettingKey::TelescopeSlowQueryMs);

    // A request first, so the recording state is on for this application;
    // then SLEEP past the threshold: one deliberate slow query, where every
    // other query this test issues is fast by comparison. A second request
    // afterwards triggers the terminating hook that stores the batch.
    $this->get('/up')->assertOk();

    DB::statement('SELECT SLEEP('.ceil(($threshold + 500) / 1000).')');

    $this->get('/up')->assertOk();

    $kept = array_filter(
        telescopeEntries('query'),
        fn (object $entry): bool => (float) (json_decode($entry->content)->time ?? 0) >= $threshold,
    );

    expect($kept)->not->toBeEmpty()
        ->and(count(telescopeEntries('query')))->toBe(count($kept));
});

it('redacts the webhook secret header from a stored request', function (): void {
    config(['services.telegram.webhook_secret' => 'path-secret-value']);

    $this->post('/telegram/webhook/wrong-token', [], [
        'X-Telegram-Bot-Api-Secret-Token' => 'header-secret-value',
    ])->assertNotFound();

    $content = telescopeRequestContent();

    expect($content->headers->{'x-telegram-bot-api-secret-token'})->toBe('********');
});

it('purges an oversized response body instead of storing it whole', function (): void {
    $this->get('/_telescope/huge')->assertStatus(422);

    $content = telescopeRequestContent();

    expect($content->response)->toBe('Purged By Telescope');
});
