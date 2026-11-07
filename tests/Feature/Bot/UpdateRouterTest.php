<?php

use App\Jobs\Telegram\ProcessTelegramUpdate;
use App\Models\TelegramUpdate;
use App\Providers\TelegramServiceProvider;
use App\Services\Telegram\HandlesUpdate;
use App\Services\Telegram\UpdateRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

uses(RefreshDatabase::class);

/*
 * The router is the seam between "an update arrived" and "the bot did something",
 * and the reason it is worth its own tests is the failure ordering around it: the
 * queued job stamps `processed_at` only once routing has returned, so a handler
 * that throws has to leave the update unprocessed and retryable. Get that backwards
 * and a transient failure silently eats a user's check-in.
 */

beforeEach(function () {
    config(['services.telegram.bot_token' => '123456:TEST-TOKEN']);

    SpyUpdateHandler::$handled = [];
    DependentUpdateHandler::$received = null;
});

/**
 * A handler that only remembers what it was given.
 */
class SpyUpdateHandler implements HandlesUpdate
{
    /** @var list<int> */
    public static array $handled = [];

    public function handle(TelegramUpdate $update): void
    {
        self::$handled[] = $update->update_id;
    }
}

/**
 * A handler that fails the way a Bot API timeout would.
 */
class ExplodingUpdateHandler implements HandlesUpdate
{
    public function handle(TelegramUpdate $update): void
    {
        throw new RuntimeException('Telegram did not answer');
    }
}

/**
 * A handler with a dependency, proving handlers are container-built.
 */
class DependentUpdateHandler implements HandlesUpdate
{
    public static ?Api $received = null;

    public function __construct(private readonly Api $telegram) {}

    public function handle(TelegramUpdate $update): void
    {
        self::$received = $this->telegram;
    }
}

/**
 * A router carrying just the handlers the test under way cares about.
 *
 * @param  array<string, class-string<HandlesUpdate>>  $handlers
 */
function routerWith(array $handlers): UpdateRouter
{
    return new UpdateRouter(app(), $handlers);
}

describe('routing by kind', function () {
    it('hands the update to the handler registered for its kind', function () {
        $update = TelegramUpdate::factory()->withUpdateId(1_001)->create();

        expect($update->kind())->toBe('message')
            ->and(routerWith(['message' => SpyUpdateHandler::class])->route($update))->toBeTrue()
            ->and(SpyUpdateHandler::$handled)->toBe([1_001]);
    });

    it('picks the handler for the kind at hand and leaves the others alone', function () {
        $update = TelegramUpdate::factory()->withUpdateId(1_002)->create();

        // The exploding handler is registered for a kind this update is not. If the
        // router matched on anything looser than the kind, this would throw.
        expect(routerWith([
            'callback_query' => ExplodingUpdateHandler::class,
            'message' => SpyUpdateHandler::class,
        ])->route($update))->toBeTrue()
            ->and(SpyUpdateHandler::$handled)->toBe([1_002]);
    });

    it('builds the handler through the container, so handlers may have dependencies', function () {
        $update = TelegramUpdate::factory()->create();

        routerWith(['message' => DependentUpdateHandler::class])->route($update);

        expect(DependentUpdateHandler::$received)->toBe(app(Api::class));
    });

    it('reports an unclaimed kind rather than failing', function () {
        // A kind we ask Telegram for but have not wired yet. Recorded, logged, and
        // not an error — adopting a kind and reacting to it are separate deploys.
        Log::spy();

        $update = TelegramUpdate::factory()->withUpdateId(1_003)->create();

        expect(routerWith([])->route($update))->toBeFalse();

        Log::shouldHaveReceived('info')->withArgs(
            fn (string $message, array $context): bool => $context['update_id'] === 1_003 && $context['kind'] === 'message',
        );
    });

    it('reports a kind Telegram has invented since this deploy', function () {
        Log::spy();

        $update = TelegramUpdate::factory()->withUpdateId(1_004)->unhandled()->create();

        expect($update->kind())->toBeNull()
            ->and(routerWith(['message' => SpyUpdateHandler::class])->route($update))->toBeFalse();

        Log::shouldHaveReceived('info')->withArgs(
            fn (string $message, array $context): bool => $context['update_id'] === 1_004 && $context['kind'] === null,
        );
    });
});

describe('the registry', function () {
    it('is resolved as configured by the provider', function () {
        // Guards the wiring rather than the contents: the queued job asks the
        // container for the router, so an unbound or hand-built one would route
        // with an empty map and quietly stop the bot reacting to anything.
        expect(app(UpdateRouter::class))->toBeInstanceOf(UpdateRouter::class)
            ->and(app(UpdateRouter::class))->toBe(app(UpdateRouter::class));
    });

    it('only maps kinds the webhook actually asks Telegram for', function () {
        // A handler keyed on a kind absent from `HANDLED_KINDS` is dead code that
        // looks alive: `telegram:set-webhook` restricts what Telegram sends, and
        // `kind()` only recognises that same list, so the handler would never run.
        // Asserted as a difference rather than per-key so it also holds — and still
        // counts as an assertion — while the map is empty.
        expect(array_diff(array_keys(TelegramServiceProvider::UPDATE_HANDLERS), TelegramUpdate::HANDLED_KINDS))
            ->toBe([]);
    });
});

describe('routing from the queued job', function () {
    it('routes the update and then stamps it', function () {
        app()->instance(UpdateRouter::class, routerWith(['message' => SpyUpdateHandler::class]));

        $update = TelegramUpdate::factory()->withUpdateId(2_001)->create();

        dispatch_sync(new ProcessTelegramUpdate($update));

        expect(SpyUpdateHandler::$handled)->toBe([2_001])
            ->and($update->refresh()->isProcessed())->toBeTrue();
    });

    it('leaves the update unprocessed when its handler throws', function () {
        // The whole point of the ordering. A stamped row is a decision already
        // made, so a failed handler must not stamp: the queue retries instead.
        app()->instance(UpdateRouter::class, routerWith(['message' => ExplodingUpdateHandler::class]));

        $update = TelegramUpdate::factory()->create();

        expect(fn () => dispatch_sync(new ProcessTelegramUpdate($update)))
            ->toThrow(RuntimeException::class, 'Telegram did not answer')
            ->and($update->refresh()->isProcessed())->toBeFalse();
    });

    it('does not run the handler twice when the same update is processed again', function () {
        app()->instance(UpdateRouter::class, routerWith(['message' => SpyUpdateHandler::class]));

        $update = TelegramUpdate::factory()->withUpdateId(2_002)->create();

        dispatch_sync(new ProcessTelegramUpdate($update));
        dispatch_sync(new ProcessTelegramUpdate($update));

        // Replies, coin credits and check-ins all hang off handlers, so a second
        // run is not a wasted cycle — it is a duplicate side effect.
        expect(SpyUpdateHandler::$handled)->toBe([2_002]);
    });

    it('stamps an update no handler claimed, so it does not linger as work to triage', function () {
        app()->instance(UpdateRouter::class, routerWith([]));

        $update = TelegramUpdate::factory()->create();

        dispatch_sync(new ProcessTelegramUpdate($update));

        expect($update->refresh()->isProcessed())->toBeTrue()
            ->and(TelegramUpdate::query()->unprocessed()->count())->toBe(0);
    });
});
