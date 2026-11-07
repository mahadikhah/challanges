<?php

use App\Actions\Observability\QueueHealth;
use App\Actions\Observability\RecordSchedulerHeartbeat;
use App\Models\SchedulerHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * The scheduler's pulse (§2.10): one row stamped every minute proves cron is
 * alive, because only cron runs the command. The external ping is the one
 * mechanism that can detect cron dying outright — an outside monitor noticing
 * the pings stopped — so it must be a strict no-op wherever it is unset.
 */
it('advances the heartbeat stamp on each run', function (): void {
    app(RecordSchedulerHeartbeat::class)->handle();

    // The column stores whole seconds; compare at the precision that exists.
    $first = SchedulerHeartbeat::query()->where('key', 'scheduler')->sole()->last_ran_at;
    expect($first->format('Y-m-d H:i:s'))->toEqual(now()->format('Y-m-d H:i:s'));

    $this->travel(2)->minutes();

    app(RecordSchedulerHeartbeat::class)->handle();

    // One row, not one per run — a table that grows every minute is a table
    // nobody pruned.
    expect(SchedulerHeartbeat::query()->count())->toBe(1)
        ->and(SchedulerHeartbeat::query()->sole()->last_ran_at->format('Y-m-d H:i:s'))->toEqual(now()->format('Y-m-d H:i:s'))
        ->and(SchedulerHeartbeat::query()->sole()->last_ran_at->greaterThan($first))->toBeTrue();
});

it('makes no outbound call when the dead-man\'s-switch ping is unset', function (): void {
    Http::preventStrayRequests();

    app(RecordSchedulerHeartbeat::class)->handle();

    Http::assertNothingSent();

    // The default staleness bar comes from the Setting registry, not a hardcode.
    expect(app(RecordSchedulerHeartbeat::class)->stalenessThreshold()->totalMinutes)->toBe(5.0);
});

it('pings the external monitor after stamping, and survives its failure', function (): void {
    config(['services.healthcheck.ping_url' => 'https://hc.example/ping/abc123']);

    Http::fake([
        'hc.example/*' => Http::response(status: 500),
    ]);

    Log::spy();

    $this->artisan('observability:heartbeat')->assertSuccessful();

    Http::assertSent(fn ($request) => $request->url() === 'https://hc.example/ping/abc123');

    // The stamp happened before the ping, so a refused monitor cannot cost us
    // the record — the warning names the outage, per §2.10's conventions.
    expect(SchedulerHeartbeat::query()->sole()->last_ran_at->format('Y-m-d H:i:s'))->toEqual(now()->format('Y-m-d H:i:s'));

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'The heartbeat\'s external dead-man\'s-switch ping failed.'
            && $context['ping_url'] === 'https://hc.example/ping/abc123')
        ->once();
});

it('reports queue depth, oldest pending age, and failed count from Laravel\'s own tables', function (): void {
    $seedJob = function (int $availableAt): void {
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\Test']),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $availableAt,
            'created_at' => $availableAt,
        ]);
    };

    $seedJob(now()->subMinutes(30)->getTimestamp());
    $seedJob(now()->subMinutes(2)->getTimestamp());

    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\Test']),
        'exception' => 'RuntimeException: boom',
        'failed_at' => now()->toDateTimeString(),
    ]);

    $snapshot = app(QueueHealth::class)->snapshot();

    expect($snapshot['pending_count'])->toBe(2)
        ->and((int) floor($snapshot['oldest_pending_age']->totalMinutes))->toBe(30)
        ->and($snapshot['failed_count'])->toBe(1);
});

it('reads an empty queue and a never-run scheduler without inventing numbers', function (): void {
    $health = app(QueueHealth::class);

    expect($health->snapshot())->toBe([
        'pending_count' => 0,
        'oldest_pending_age' => null,
        'failed_count' => 0,
    ])->and($health->schedulerLastRanAt())->toBeNull();
});

it('exposes the scheduler\'s last stamp for the readers that judge staleness', function (): void {
    $this->travel(-7)->minutes();
    app(RecordSchedulerHeartbeat::class)->handle();
    $this->travelBack();

    $lastRanAt = app(QueueHealth::class)->schedulerLastRanAt();

    expect($lastRanAt)->not->toBeNull()
        ->and((int) floor($lastRanAt->diffInMinutes(now())))->toBe(7);
});
