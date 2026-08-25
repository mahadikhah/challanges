<?php

use App\Actions\Telegram\IngestTelegramUpdate;
use App\Jobs\Telegram\ProcessTelegramUpdate;
use App\Models\TelegramUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    // The Bot API is never reached in tests, on any path.
    Http::fake();

    config([
        'services.telegram.webhook_secret' => 'path-secret',
        'services.telegram.webhook_header_secret' => 'header-secret',
    ]);
});

/**
 * POST an update the way Telegram would: correct path secret, correct header.
 *
 * @param  array<string, mixed>  $payload
 */
function deliver(array $payload, string $pathSecret = 'path-secret', ?string $headerSecret = 'header-secret')
{
    return test()->postJson(
        "/telegram/webhook/{$pathSecret}",
        $payload,
        $headerSecret === null ? [] : ['X-Telegram-Bot-Api-Secret-Token' => $headerSecret],
    );
}

/**
 * A minimal but realistic `message` update.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function messageUpdate(int $updateId = 500_001, array $overrides = []): array
{
    return array_replace_recursive([
        'update_id' => $updateId,
        'message' => [
            'message_id' => 12,
            'date' => 1_760_000_000,
            'chat' => ['id' => 99, 'type' => 'private'],
            'from' => ['id' => 99, 'is_bot' => false, 'first_name' => 'Sara'],
            'text' => '/start',
        ],
    ], $overrides);
}

describe('answering Telegram', function () {
    it('records the update, queues the work and returns 200 immediately', function () {
        Queue::fake();

        deliver(messageUpdate())->assertOk()->assertExactJson(['ok' => true]);

        $update = TelegramUpdate::query()->sole();

        expect($update->update_id)->toBe(500_001)
            ->and($update->value('message.text'))->toBe('/start')
            // Nothing is processed inline: the row is left for the queue, which is
            // what keeps the response fast enough for Telegram.
            ->and($update->isProcessed())->toBeFalse();

        Queue::assertPushed(
            ProcessTelegramUpdate::class,
            fn (ProcessTelegramUpdate $job): bool => $job->update->is($update),
        );
    });

    it('stores the payload verbatim, including keys we do not read', function () {
        Queue::fake();

        deliver(messageUpdate(overrides: ['message' => ['some_future_field' => ['a' => 1]]]));

        expect(TelegramUpdate::query()->sole()->value('message.some_future_field'))->toBe(['a' => 1]);
    });

    it('lets a recording failure surface rather than claiming the update arrived', function () {
        // A 200 on a failed insert would tell Telegram the update was delivered,
        // and Telegram never redelivers what it believes arrived — the update
        // would be lost for good instead of retried.
        Queue::fake();

        app()->bind(IngestTelegramUpdate::class, fn () => new class extends IngestTelegramUpdate
        {
            public function handle(int $updateId, array $payload): TelegramUpdate
            {
                throw new RuntimeException('the database is down');
            }
        });

        deliver(messageUpdate())->assertStatus(500);

        Queue::assertNothingPushed();
    });
});

describe('rejecting anything that is not Telegram', function () {
    it('answers 404 and records nothing', function (?string $path, ?string $header) {
        Queue::fake();

        deliver(messageUpdate(), $path ?? 'path-secret', $header)->assertNotFound();

        expect(TelegramUpdate::query()->count())->toBe(0);
        Queue::assertNothingPushed();
    })->with([
        // 404 rather than 403 throughout: a probe should not be able to tell a
        // real webhook path with a wrong secret from one that was never routed.
        'wrong path secret' => ['not-the-secret', 'header-secret'],
        'no path secret at all' => ['', 'header-secret'],
        'wrong header secret' => [null, 'not-the-secret'],
        'missing header' => [null, null],
        'empty header' => [null, ''],
        'secrets swapped' => ['header-secret', 'path-secret'],
    ]);

    it('refuses everything when a secret is not configured', function (string $unset) {
        // Fail closed. The alternative is an open write endpoint that looks like
        // it is working.
        Queue::fake();

        config(["services.telegram.{$unset}" => null]);

        deliver(messageUpdate())->assertNotFound();

        expect(TelegramUpdate::query()->count())->toBe(0);
    })->with([
        'no path secret' => 'webhook_secret',
        'no header secret' => 'webhook_header_secret',
    ]);

    it('logs which check failed so a silent bot can be diagnosed', function (string $expected, string $path, string $header) {
        Log::spy();

        deliver(messageUpdate(), $path, $header);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $context['check'] === $expected);
    })->with([
        'path' => ['path', 'not-the-secret', 'header-secret'],
        'header' => ['header', 'path-secret', 'not-the-secret'],
    ]);

    it('refuses a body with no update_id', function () {
        Queue::fake();

        deliver(['message' => ['text' => 'hello']])->assertStatus(422);

        expect(TelegramUpdate::query()->count())->toBe(0);
        Queue::assertNothingPushed();
    });

    it('refuses an update_id that is not an integer', function () {
        Queue::fake();

        deliver(['update_id' => 'not-a-number'])->assertStatus(422);

        Queue::assertNothingPushed();
    });
});

describe('idempotency on update_id', function () {
    it('records one row and queues one job however many times an update arrives', function () {
        Queue::fake();

        deliver(messageUpdate(700))->assertOk();
        deliver(messageUpdate(700))->assertOk();
        deliver(messageUpdate(700))->assertOk();

        expect(TelegramUpdate::query()->count())->toBe(1);
        Queue::assertPushed(ProcessTelegramUpdate::class, 1);
    });

    it('keeps the first payload when a redelivery differs', function () {
        Queue::fake();

        deliver(messageUpdate(701, ['message' => ['text' => 'original']]));
        deliver(messageUpdate(701, ['message' => ['text' => 'tampered']]));

        expect(TelegramUpdate::query()->sole()->value('message.text'))->toBe('original');
    });

    it('does not requeue an update that has already been processed', function () {
        Queue::fake();

        TelegramUpdate::factory()->withUpdateId(702)->processed()->create();

        deliver(messageUpdate(702))->assertOk();

        expect(TelegramUpdate::query()->count())->toBe(1);
        Queue::assertNothingPushed();
    });

    it('treats distinct update ids as distinct work', function () {
        Queue::fake();

        deliver(messageUpdate(801));
        deliver(messageUpdate(802));

        expect(TelegramUpdate::query()->count())->toBe(2);
        Queue::assertPushed(ProcessTelegramUpdate::class, 2);
    });
});

describe('processing the queued update', function () {
    it('stamps the row as processed', function () {
        $update = TelegramUpdate::factory()->create();

        (new ProcessTelegramUpdate($update))->handle();

        expect($update->refresh()->isProcessed())->toBeTrue();
    });

    it('runs end to end from the request', function () {
        deliver(messageUpdate(901))->assertOk();

        expect(TelegramUpdate::query()->sole()->isProcessed())->toBeTrue();
    });

    it('is safe to run twice and keeps the first timestamp', function () {
        $update = TelegramUpdate::factory()->create(['processed_at' => now()->subHour()]);
        $first = $update->processed_at;

        (new ProcessTelegramUpdate($update))->handle();

        expect($update->refresh()->processed_at->equalTo($first))->toBeTrue();
    });

    it('reads the row again rather than trusting the serialised copy', function () {
        $update = TelegramUpdate::factory()->create();

        // Another attempt settled it while this job sat in the queue. The
        // in-memory instance still says unprocessed.
        TelegramUpdate::query()->whereKey($update->getKey())->update(['processed_at' => now()->subMinute()]);
        $settledAt = TelegramUpdate::query()->sole()->processed_at;

        expect($update->isProcessed())->toBeFalse();

        (new ProcessTelegramUpdate($update))->handle();

        expect(TelegramUpdate::query()->sole()->processed_at->equalTo($settledAt))->toBeTrue();
    });

    it('stamps and logs an update of a kind we do not handle', function () {
        Log::spy();

        $update = TelegramUpdate::factory()->withUpdateId(904)->unhandled()->create();

        (new ProcessTelegramUpdate($update))->handle();

        // Stamped, so it neither blocks the queue nor lingers as work to triage.
        expect($update->refresh()->isProcessed())->toBeTrue()
            ->and($update->kind())->toBeNull();

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context): bool => $context['update_id'] === 904);
    });

    it('retries a few times before leaving the row for inspection', function () {
        $job = new ProcessTelegramUpdate(TelegramUpdate::factory()->create());

        expect($job->tries)->toBe(3)
            ->and($job->backoff)->not->toBeEmpty();
    });
});
