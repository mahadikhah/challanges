<?php

use App\Enums\SettingKey;
use App\Models\SchedulerHeartbeat;
use App\Services\Settings;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
 * Task 5: critical alerts through the existing bot. Every trigger routes
 * through SendCriticalAlert, which owns all three gates — kill switch,
 * configured destination, debounce — so the triggers themselves can be
 * tested as "did the send action get a chance, and did the message name the
 * right thing".
 */

function enableAlerts(): void
{
    $settings = app(Settings::class);

    $settings->set(SettingKey::AlertsEnabled, true);
    $settings->set(SettingKey::AlertOpsPlatform, 'telegram');
    $settings->set(SettingKey::AlertOpsChatId, 4242);
}

function fireJobFailed(): void
{
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\Telegram\\SendReminder');

    event(new JobFailed(
        'database',
        $job,
        new RuntimeException("Maximum retries exceeded.\n\n#0 somewhere"),
    ));
}

function sendCount(): int
{
    return count(Http::recorded());
}

it('alerts the ops chat when a job exhausts its retries', function (): void {
    enableAlerts();

    Http::fake(['*sendMessage*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

    fireJobFailed();

    Http::assertSent(function ($request): bool {
        $chatId = $request['chat_id'] ?? null;
        $text = (string) ($request['text'] ?? '');

        return $chatId === 4242
            && str_contains($text, 'App\Jobs\Telegram\SendReminder')
            && str_contains($text, 'Maximum retries exceeded.')
            && str_contains($text, route('admin.system-health.index'));
    });
});

it('debounces a repeated exception class inside the cooldown and re-alerts after it', function (): void {
    enableAlerts();

    Http::fake(['*sendMessage*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

    report(new RuntimeException('boom'));
    report(new RuntimeException('boom again'));

    expect(sendCount())->toBe(1);

    // The cooldown is a Setting (default 15 minutes); past it, the same
    // class is new information again.
    $this->travel(16)->minutes();

    report(new RuntimeException('still broken'));

    expect(sendCount())->toBe(2);
});

it('sends one alert for a burst of the same failed job class', function (): void {
    enableAlerts();

    Http::fake(['*sendMessage*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

    fireJobFailed();
    fireJobFailed();
    fireJobFailed();

    expect(sendCount())->toBe(1);
});

it('alerts when the scheduler heartbeat goes stale, and not before', function (): void {
    enableAlerts();

    Http::fake(['*sendMessage*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

    // Fresh stamp: the check stays quiet.
    SchedulerHeartbeat::factory()->ranMinutesAgo(2)->create();

    Artisan::call('observability:alert-stale-heartbeat');
    expect(sendCount())->toBe(0);

    // Past the staleness bar (default 5 minutes): the ops chat hears.
    SchedulerHeartbeat::query()->delete();
    SchedulerHeartbeat::factory()->ranMinutesAgo(10)->create();

    Artisan::call('observability:alert-stale-heartbeat');

    Http::assertSent(fn ($request): bool => $request['chat_id'] === 4242
        && str_contains((string) ($request['text'] ?? ''), route('admin.system-health.index')));
});

it('never alerts under any trigger while alerting is disabled or unconfigured', function (string $trigger): void {
    $settings = app(Settings::class);

    // Each row below is one reason an alert should die at the gates, with
    // everything else fully configured — the kill switch, an unknown
    // platform string, and a chat id still at its zero default.
    $states = [
        'kill switch off' => [SettingKey::AlertsEnabled, false],
        'unknown platform' => [SettingKey::AlertOpsPlatform, 'signal'],
        'chat id unset' => [SettingKey::AlertOpsChatId, 0],
    ];

    Http::preventStrayRequests();

    foreach ($states as [$key, $value]) {
        $settings->set(SettingKey::AlertsEnabled, true);
        $settings->set(SettingKey::AlertOpsPlatform, 'telegram');
        $settings->set(SettingKey::AlertOpsChatId, 4242);
        $settings->set($key, $value);

        if ($trigger === 'job-failed') {
            fireJobFailed();
        } elseif ($trigger === 'exception') {
            report(new RuntimeException('nobody hears this'));
        } else {
            Artisan::call('observability:alert-stale-heartbeat');
        }
    }

    expect(Http::recorded())->toBeEmpty();
})->with([
    'a failed job' => ['job-failed'],
    'a reported exception' => ['exception'],
    'a stale heartbeat' => ['stale-heartbeat'],
]);

it('keeps reporting an exception when the messenger refuses the alert', function (): void {
    enableAlerts();

    // The SDK throws on ok:false + 5xx; the alert path must swallow that
    // and leave the caller — here, the exception reporter — intact.
    Http::fake(['*sendMessage*' => Http::response(['ok' => false, 'error_code' => 500, 'description' => 'Internal'], 500)]);

    report(new RuntimeException('the platform error is still reported'));

    expect(true)->toBeTrue();
});
