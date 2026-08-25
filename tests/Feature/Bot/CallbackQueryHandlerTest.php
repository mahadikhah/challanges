<?php

use App\Jobs\Telegram\ProcessTelegramUpdate;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\Telegram\BotCallback;
use App\Services\Telegram\CallbackRouter;
use App\Services\Telegram\HandlesCallback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

/*
 * Everything a user taps arrives here. Two properties are worth a test each and
 * neither is visible from the wizard tests that exercise this class in passing.
 *
 * **The query is acknowledged before the work.** Telegram spins a loading state on
 * the tapped button until `answerCallbackQuery` arrives, and the query id expires.
 * If the work throws, the update is retried — and by then the id is stale, so an
 * acknowledgement afterwards would fail and leave the spinner running forever.
 *
 * **The acknowledgement is best-effort.** It is cosmetic, so it must never be the
 * reason an update is retried. That swallowing is deliberate but it is also exactly
 * the shape of a mistake, so it is pinned down here rather than left to a comment.
 */

beforeEach(function () {
    Http::preventStrayRequests();

    config(['services.telegram.bot_token' => '123456:TEST-TOKEN']);

    RecordingCallbackHandler::$taken = [];
});

/**
 * A handler that records the call rather than doing anything.
 */
class RecordingCallbackHandler implements HandlesCallback
{
    /**
     * @var list<array{user: int, action: string, arguments: list<string>}>
     */
    public static array $taken = [];

    public function handle(User $user, BotCallback $callback): void
    {
        self::$taken[] = [
            'user' => (int) $user->getKey(),
            'action' => $callback->action,
            'arguments' => $callback->arguments,
        ];
    }
}

/**
 * A handler that fails the way a real one would — after the acknowledgement.
 */
class FailingCallbackHandler implements HandlesCallback
{
    public function handle(User $user, BotCallback $callback): void
    {
        throw new RuntimeException('The handler fell over.');
    }
}

/**
 * Register a callback action for the duration of one test.
 *
 * @param  array<string, class-string<HandlesCallback>>  $handlers
 */
function callbacksRoutedTo(array $handlers): void
{
    app()->bind(CallbackRouter::class, fn ($app): CallbackRouter => new CallbackRouter($app, $handlers));
}

/**
 * Telegram answering normally: the acknowledgement lands and replies go out.
 *
 * Installed per test rather than in `beforeEach` because `Http::fake()` *appends*
 * and the first matching pattern wins — a catch-all registered up front would
 * silently shadow the refusal `telegramRefusesAcknowledgement()` needs, and the
 * tests below would pass without ever exercising the failure path.
 */
function telegramTakesTaps(): void
{
    Http::fake([
        '*answerCallbackQuery*' => Http::response(['ok' => true, 'result' => true]),
        '*sendMessage*' => Http::response(['ok' => true, 'result' => ['message_id' => 11]]),
    ]);
}

/**
 * Telegram refusing the acknowledgement, the way it does on a retry: by the time we
 * answer, the query id has expired.
 */
function telegramRefusesAcknowledgement(): void
{
    Http::fake([
        '*answerCallbackQuery*' => Http::response(
            ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: query is too old'],
            400,
        ),
        '*sendMessage*' => Http::response(['ok' => true, 'result' => ['message_id' => 11]]),
    ]);
}

/**
 * Put one tap through the whole inbound path.
 *
 * @param  array<string, mixed>  $from  merged over Telegram's `from` object
 */
function tapArrives(string $data, array $from = [], ?string $queryId = null): TelegramUpdate
{
    $update = TelegramUpdate::factory()
        ->callbackQueryFrom(array_replace(['id' => 777_200_1, 'first_name' => 'Sara'], $from), $data)
        ->create();

    if ($queryId !== null) {
        $payload = $update->payload;
        $payload['callback_query']['id'] = $queryId;
        $update->forceFill(['payload' => $payload])->save();
    }

    dispatch_sync(new ProcessTelegramUpdate($update));

    return $update;
}

/**
 * Every `answerCallbackQuery` the bot made, as decoded parameter arrays.
 *
 * @return list<array<string, string>>
 */
function acknowledgements(): array
{
    return Http::recorded(
        fn (Request $request): bool => str_contains($request->url(), 'answerCallbackQuery'),
    )->map(function (array $call): array {
        $sent = [];
        parse_str($call[0]->body(), $sent);

        /** @var array<string, string> $sent */
        return $sent;
    })->values()->all();
}

describe('a tap', function () {
    it('reaches the handler registered for its action', function () {
        telegramTakesTaps();
        callbacksRoutedTo(['demo' => RecordingCallbackHandler::class]);

        tapArrives(BotCallback::encode('demo', 'first', 'second'));

        expect(RecordingCallbackHandler::$taken)->toHaveCount(1)
            ->and(RecordingCallbackHandler::$taken[0]['action'])->toBe('demo')
            ->and(RecordingCallbackHandler::$taken[0]['arguments'])->toBe(['first', 'second']);
    });

    it('registers the person who tapped, exactly as a message would', function () {
        telegramTakesTaps();
        callbacksRoutedTo(['demo' => RecordingCallbackHandler::class]);

        tapArrives(BotCallback::encode('demo'), ['username' => 'sara', 'language_code' => 'fa']);

        $user = User::query()->where('telegram_id', 777_200_1)->sole();

        // A tap can be somebody's very first interaction — a button on a channel
        // post — so the handler resolves a user rather than assuming one exists.
        expect($user->telegram_username)->toBe('sara')
            ->and($user->language_code)->toBe('fa')
            ->and(RecordingCallbackHandler::$taken[0]['user'])->toBe((int) $user->getKey());
    });

    it('resolves the actor from the sender, never from the button', function () {
        telegramTakesTaps();
        callbacksRoutedTo(['demo' => RecordingCallbackHandler::class]);

        $victim = User::factory()->telegram(777_200_9)->create();

        // `callback_data` names an intent, never an authorization. A crafted payload
        // claiming somebody else's id has to be ignored, and the only way to see
        // that is to put one through.
        tapArrives(BotCallback::encode('demo', (string) $victim->getKey()));

        expect(RecordingCallbackHandler::$taken[0]['user'])
            ->toBe((int) User::query()->where('telegram_id', 777_200_1)->sole()->getKey());
    });

    it('is acknowledged before the handler runs', function () {
        telegramTakesTaps();
        callbacksRoutedTo(['demo' => FailingCallbackHandler::class]);

        expect(fn () => tapArrives(BotCallback::encode('demo'), queryId: 'query-1'))
            ->toThrow(RuntimeException::class);

        // The ordering assertion this file exists for: the handler threw, so the
        // update will be retried — and the acknowledgement still went out, because
        // it went out first. Answering afterwards would leave a button spinning
        // until the client gave up.
        expect(acknowledgements())->toHaveCount(1)
            ->and(acknowledgements()[0]['callback_query_id'])->toBe('query-1');
    });

    it('acknowledges once per tap and no more', function () {
        telegramTakesTaps();
        callbacksRoutedTo(['demo' => RecordingCallbackHandler::class]);

        tapArrives(BotCallback::encode('demo'));

        // Telegram allows ~30 API calls a second globally, and a keyboard-heavy
        // flow is the biggest consumer of them. One tap is one acknowledgement.
        expect(acknowledgements())->toHaveCount(1);
    });
});

describe('what it will not act on', function () {
    it('ignores another bot tapping a button', function () {
        telegramTakesTaps();
        callbacksRoutedTo(['demo' => RecordingCallbackHandler::class]);

        tapArrives(BotCallback::encode('demo'), ['id' => 999_200_1, 'is_bot' => true]);

        expect(User::query()->count())->toBe(0)
            ->and(RecordingCallbackHandler::$taken)->toBeEmpty();
        Http::assertNothingSent();
    });

    it('ignores a callback query with no sender at all', function () {
        telegramTakesTaps();
        callbacksRoutedTo(['demo' => RecordingCallbackHandler::class]);

        $update = TelegramUpdate::factory()->create(['payload' => [
            'callback_query' => ['id' => '1', 'data' => 'demo'],
        ]]);

        dispatch_sync(new ProcessTelegramUpdate($update));

        expect(User::query()->count())->toBe(0);
        Http::assertNothingSent();
    });

    it('still marks an ignored tap processed, so it is not retried forever', function () {
        telegramTakesTaps();
        callbacksRoutedTo(['demo' => RecordingCallbackHandler::class]);

        $update = tapArrives(BotCallback::encode('demo'), ['id' => 999_200_2, 'is_bot' => true]);

        expect($update->refresh()->processed_at)->not->toBeNull();
    });

    it('takes a tap from a group, unlike a message', function () {
        telegramTakesTaps();
        callbacksRoutedTo(['demo' => RecordingCallbackHandler::class]);

        $update = TelegramUpdate::factory()->create(['payload' => [
            'callback_query' => [
                'id' => '1',
                'from' => ['id' => 777_200_1, 'is_bot' => false, 'first_name' => 'Sara'],
                'message' => ['message_id' => 5, 'chat' => ['id' => -100_123, 'type' => 'channel']],
                'data' => 'demo',
            ],
        ]]);

        dispatch_sync(new ProcessTelegramUpdate($update));

        // A button on a channel post is how a public challenge gets joined. There is
        // nothing to leak by allowing it: the actor comes from `from`, and the reply
        // goes to that user's own private chat rather than to the channel.
        expect(RecordingCallbackHandler::$taken)->toHaveCount(1);
    });
});

describe('a button nothing claims', function () {
    it('says so rather than doing nothing visible', function () {
        telegramTakesTaps();
        callbacksRoutedTo(['demo' => RecordingCallbackHandler::class]);

        // An inline keyboard sent last week is still tappable today, so a deploy
        // that renames an action leaves live buttons whose data nothing routes.
        tapArrives(BotCallback::encode('renamed-last-deploy'));

        expect(soleBotMessage()['text'])->toBe(botCopy('bot.fallback.stale_button'));
    });

    it('says so for a tap carrying no data at all', function () {
        telegramTakesTaps();
        callbacksRoutedTo(['demo' => RecordingCallbackHandler::class]);

        $update = TelegramUpdate::factory()->create(['payload' => [
            'callback_query' => [
                'id' => '1',
                'from' => ['id' => 777_200_1, 'is_bot' => false, 'first_name' => 'Sara'],
                'message' => ['message_id' => 5, 'chat' => ['id' => 777_200_1, 'type' => 'private']],
            ],
        ]]);

        dispatch_sync(new ProcessTelegramUpdate($update));

        expect(soleBotMessage()['text'])->toBe(botCopy('bot.fallback.stale_button'));
    });

    it('acknowledges it anyway', function () {
        telegramTakesTaps();
        callbacksRoutedTo(['demo' => RecordingCallbackHandler::class]);

        tapArrives(BotCallback::encode('renamed-last-deploy'), queryId: 'query-2');

        // Unanswered is what makes a client spin forever, and "we do not know this
        // button" is still an answer.
        expect(acknowledgements())->toHaveCount(1);
    });
});

describe('when Telegram refuses the acknowledgement', function () {
    it('carries on and handles the tap', function () {
        telegramRefusesAcknowledgement();
        callbacksRoutedTo(['demo' => RecordingCallbackHandler::class]);
        Log::spy();

        tapArrives(BotCallback::encode('demo'));

        // Nothing user-facing rides on the acknowledgement, and failing the update
        // over a cosmetic call would have Telegram redeliver it until it gave up.
        expect(RecordingCallbackHandler::$taken)->toHaveCount(1)
            ->and(acknowledgements())->toHaveCount(1);
    });

    it('marks the update processed rather than leaving it to be retried', function () {
        telegramRefusesAcknowledgement();
        callbacksRoutedTo(['demo' => RecordingCallbackHandler::class]);
        Log::spy();

        $update = tapArrives(BotCallback::encode('demo'));

        expect($update->refresh()->processed_at)->not->toBeNull();
    });

    it('still replies to the tap', function () {
        telegramRefusesAcknowledgement();
        callbacksRoutedTo(['demo' => RecordingCallbackHandler::class]);
        Log::spy();

        // The reply is an ordinary message, on a different call entirely. A dead
        // acknowledgement must not swallow it.
        tapArrives(BotCallback::encode('renamed-last-deploy'));

        expect(soleBotMessage()['text'])->toBe(botCopy('bot.fallback.stale_button'));
    });

    it('says why in the log rather than silently', function () {
        telegramRefusesAcknowledgement();
        callbacksRoutedTo(['demo' => RecordingCallbackHandler::class]);
        Log::spy();

        tapArrives(BotCallback::encode('demo'));

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message): bool => str_contains($message, 'acknowledge'))
            ->once();
    });

    it('does not try to acknowledge a query with no id', function () {
        telegramTakesTaps();
        callbacksRoutedTo(['demo' => RecordingCallbackHandler::class]);

        $update = TelegramUpdate::factory()->create(['payload' => [
            'callback_query' => [
                'from' => ['id' => 777_200_1, 'is_bot' => false, 'first_name' => 'Sara'],
                'message' => ['message_id' => 5, 'chat' => ['id' => 777_200_1, 'type' => 'private']],
                'data' => 'demo',
            ],
        ]]);

        dispatch_sync(new ProcessTelegramUpdate($update));

        expect(acknowledgements())->toBeEmpty()
            ->and(RecordingCallbackHandler::$taken)->toHaveCount(1);
    });
});

describe('a replayed tap', function () {
    it('runs the handler once per delivery, because the handler owns idempotency', function () {
        telegramTakesTaps();
        callbacksRoutedTo(['demo' => RecordingCallbackHandler::class]);

        $update = tapArrives(BotCallback::encode('demo'));
        dispatch_sync(new ProcessTelegramUpdate($update->refresh()));

        // `ProcessTelegramUpdate` skips an update it has already processed, so a
        // Telegram retry of the same `update_id` does not reach the handler twice.
        // That is the guarantee every callback handler is written against.
        expect(RecordingCallbackHandler::$taken)->toHaveCount(1)
            ->and(acknowledgements())->toHaveCount(1);
    });
});
