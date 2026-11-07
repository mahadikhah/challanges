<?php

use App\Enums\SettingKey;
use App\Jobs\Telegram\ProcessTelegramUpdate;
use App\Models\BotConversation;
use App\Models\Entitlement;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\Settings;
use App\Services\Telegram\BotButtons;
use App\Services\Telegram\BotCallback;
use App\Services\Telegram\BotCommand;
use App\Services\Telegram\BotCommandMenu;
use App\Services\Telegram\Callbacks\CommandCallback;
use App\Services\Telegram\CommandRouter;
use App\Services\Telegram\HandlesBotCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
 * The command buttons, tested as buttons rather than as copy.
 *
 * Every other bot test reads the *text* of a reply. This one exists because the
 * thing this task added is the keyboard under it, and a keyboard has properties
 * text cannot express: it must say the same words as the command menu, it must
 * arrive at the same handler a typed command reaches, and it must not become a
 * second, weaker way into the command map.
 *
 * Both directions go through the real inbound path — `ProcessTelegramUpdate`, the
 * real routers, the real container — because the claim under test is about wiring,
 * and wiring is exactly what a unit test of `BotButtons` cannot see.
 */

const BUTTON_TELEGRAM_ID = 777_300_1;

beforeEach(function () {
    Http::preventStrayRequests();

    config(['services.telegram.bot_token' => '123456:TEST-TOKEN']);

    $settings = app(Settings::class);
    $settings->set(SettingKey::RequiredChannel, '@challenges');

    RecordingCommandHandler::$taken = [];
});

/**
 * Telegram answering normally: the tap is acknowledged, and replies go out.
 */
function buttonTelegramAnswers(): void
{
    Http::fake([
        '*getChatMember*' => Http::response(['ok' => true, 'result' => ['status' => 'member']]),
        '*answerCallbackQuery*' => Http::response(['ok' => true, 'result' => true]),
        '*sendMessage*' => Http::response(['ok' => true, 'result' => ['message_id' => 11]]),
    ]);
}

/**
 * One message typed at the bot, through the whole inbound path.
 */
function buttonTyped(int $chatId, string $text): void
{
    $update = TelegramUpdate::factory()
        ->messageFrom(['id' => $chatId, 'first_name' => 'Sara', 'language_code' => 'en'], $text)
        ->create();

    dispatch_sync(new ProcessTelegramUpdate($update));
}

/**
 * One tap, through the whole inbound path.
 *
 * `callback_data` is written verbatim rather than built from a builder, so a test
 * can hand the bot a payload no button of ours would ever have produced.
 */
function buttonTapped(int $chatId, string $data): void
{
    $update = TelegramUpdate::factory()
        ->callbackQueryFrom(['id' => $chatId, 'first_name' => 'Sara', 'language_code' => 'en'], $data)
        ->create();

    dispatch_sync(new ProcessTelegramUpdate($update));
}

/**
 * A command router that records the commands handed to it instead of running them.
 *
 * Bound for one test so that "the tap and the typed command land in the same
 * handler" can be asserted directly. Reading the real handlers' side effects would
 * prove something weaker — that two paths happen to agree today — where this shows
 * they are the same path.
 *
 * @param  list<string>  $words
 */
function buttonCommandsRoutedTo(array $words): void
{
    app()->bind(CommandRouter::class, fn ($app): CommandRouter => new CommandRouter(
        $app,
        array_fill_keys($words, RecordingCommandHandler::class),
    ));
}

/**
 * A command handler that records the call rather than doing anything.
 */
class RecordingCommandHandler implements HandlesBotCommand
{
    /**
     * @var list<array{user: int, name: string, argument: string|null}>
     */
    public static array $taken = [];

    public function handle(User $user, BotCommand $command): void
    {
        self::$taken[] = [
            'user' => (int) $user->getKey(),
            'name' => $command->name,
            'argument' => $command->argument,
        ];
    }
}

describe('a command button and the command menu', function () {
    it('says exactly what the menu lists the command under', function (string $locale) {
        $user = User::factory()->telegram(BUTTON_TELEGRAM_ID)->preferring($locale)->create();

        $listed = app(BotCommandMenu::class)->forLocale($locale);

        $row = app(BotButtons::class)->row($user, ...array_column($listed, 'command'));

        // Both sides read the same `bot.commands.*` lines, so this pins the
        // *reading* rather than the words: whichever copy change lands next, the
        // label under a message and the label in the menu move together. That is
        // the whole reason a command button has no label of its own.
        expect($row)->toBe(array_map(
            static fn (array $entry): array => commandButton($locale, $entry['command']),
            $listed,
        ));
    })->with(['English' => ['en'], 'Farsi' => ['fa']]);
});

describe('a command button and a typed command', function () {
    it('takes a tap into the same handler a typed command reaches', function () {
        buttonCommandsRoutedTo(['demo']);
        buttonTelegramAnswers();

        buttonTyped(BUTTON_TELEGRAM_ID, '/demo');
        $typed = RecordingCommandHandler::$taken;

        expect($typed)->toHaveCount(1);

        RecordingCommandHandler::$taken = [];

        buttonTapped(BUTTON_TELEGRAM_ID, BotCallback::encode(CommandCallback::ACTION, 'demo'));

        // Same handler, same user, same command word, same absent argument. The
        // two are not parallel paths that agree; the tap enters the only path
        // there is, one message earlier.
        expect(RecordingCommandHandler::$taken)->toBe($typed);
    });

    it('refuses a command word the router does not know', function (string $data) {
        User::factory()->telegram(BUTTON_TELEGRAM_ID)->preferring('en')->create();
        buttonTelegramAnswers();

        buttonTapped(BUTTON_TELEGRAM_ID, $data);

        // `callback_data` is a string a client sends us, so `cm:dropTables` is a
        // thing that can arrive. The router's own map is the allowlist, and a word
        // outside it gets the ordinary dead end rather than a way in.
        expect(soleBotMessage()['text'])->toBe(botCopy('bot.fallback.stale_button'))
            ->and(botKeyboard())->toBe([[commandButton('en', 'create')]]);
    })->with([
        'a command the bot has never answered to' => ['cm:dropTables'],
        'a command a later deploy dropped' => ['cm:startall'],
        'a button carrying no word at all' => ['cm'],
        'a button carrying an empty word' => ['cm:'],
    ]);

    it('does not swallow a tap while a wizard is open', function () {
        $creator = User::factory()->telegram(BUTTON_TELEGRAM_ID)->channelVerified()->preferring('en')->create();
        Entitlement::factory()->createSlot()->create(['user_id' => $creator->getKey()]);

        buttonTelegramAnswers();

        buttonTyped(BUTTON_TELEGRAM_ID, '/create');

        expect(BotConversation::query()->exists())->toBeTrue();

        buttonTapped(BUTTON_TELEGRAM_ID, BotCallback::encode(CommandCallback::ACTION, 'cancel'));

        // The routing-order regression this task could have introduced. A tap
        // never enters `ConversationRouter` — it arrives as a callback query, and
        // a live wizard answers nothing but text — so if the two paths were ever
        // joined, `cm:cancel` would be read as a challenge title and the draft
        // would still be open afterwards.
        expect(BotConversation::query()->exists())->toBeFalse()
            ->and(lastBotReply()['text'])->toBe(botCopy('bot.wizard.cancelled'));
    });
});

describe('a converted message in either language', function () {
    it('answers a dead end in the recipient’s own language', function (string $locale) {
        User::factory()->telegram(BUTTON_TELEGRAM_ID)->preferring($locale)->create();
        buttonTelegramAnswers();

        // An action word no deploy has ever had. The most-reached dead end there
        // is, since a keyboard sent last week is still tappable today.
        buttonTapped(BUTTON_TELEGRAM_ID, 'zz:1');

        expect(soleBotMessage()['text'])->toBe(botCopy('bot.fallback.stale_button', [], $locale))
            ->and(botKeyboard())->toBe([[commandButton($locale, 'create')]]);
    })->with(['English' => ['en'], 'Farsi' => ['fa']]);

    it('offers the way back into a wizard in the recipient’s own language', function (string $locale) {
        User::factory()->telegram(BUTTON_TELEGRAM_ID)->preferring($locale)->create();
        buttonTelegramAnswers();

        // Nothing to cancel: the line is a dead end, and the button under it is
        // the way out of one.
        buttonTapped(BUTTON_TELEGRAM_ID, BotCallback::encode(CommandCallback::ACTION, 'cancel'));

        expect(soleBotMessage()['text'])->toBe(botCopy('bot.cancel.nothing_open', [], $locale))
            ->and(botKeyboard())->toBe([[commandButton($locale, 'create')]]);
    })->with(['English' => ['en'], 'Farsi' => ['fa']]);
});
