<?php

use App\Jobs\Telegram\ProcessTelegramUpdate;
use App\Models\TelegramUpdate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
 * `/language` and its buttons. The choice is only a column on the user's row —
 * every line the bot sends is resolved per recipient anyway — so what is under
 * test here is the shape of the offer, that a tap lands on the allowlist and
 * not on the payload, and that the confirmation arrives in the language that
 * was just chosen.
 *
 * Driven whole through `ProcessTelegramUpdate`, the real routers and the real
 * handler, for the same reason as every other bot test: the interesting
 * failures are wiring failures, and no unit test of the handler sees them.
 */

beforeEach(function () {
    Http::preventStrayRequests();

    config(['services.telegram.bot_token' => '123456:TEST-TOKEN']);

    // A tap is acknowledged as well as answered, so both Bot API calls are stubbed
    // together — `Http::fake()` appends and the first matching pattern wins.
    Http::fake([
        '*answerCallbackQuery*' => Http::response(['ok' => true, 'result' => true]),
        '*sendMessage*' => Http::response(['ok' => true, 'result' => ['message_id' => 11]]),
    ]);
});

/**
 * Put one message through the whole inbound path.
 *
 * @param  array<string, mixed>  $from  merged over Telegram's `from` object
 */
function asksForLanguages(array $from = []): void
{
    $update = TelegramUpdate::factory()
        ->messageFrom(array_replace(['id' => 777_000_3, 'first_name' => 'Sara'], $from), '/language')
        ->create();

    dispatch_sync(new ProcessTelegramUpdate($update));
}

/**
 * Put one language-button tap through the whole inbound path.
 */
function tapsLanguageButton(string $data): void
{
    $update = TelegramUpdate::factory()
        ->callbackQueryFrom(['id' => 777_000_3, 'first_name' => 'Sara'], $data)
        ->create();

    dispatch_sync(new ProcessTelegramUpdate($update));
}

it('offers every supported locale under its own name, in one row of buttons', function () {
    asksForLanguages();

    expect(soleBotMessage()['text'])->toBe(botCopy('bot.language.prompt'))
        ->and(botKeyboard())->toBe([[
            ['text' => 'English', 'callback_data' => 'lg:en'],
            ['text' => 'فارسی', 'callback_data' => 'lg:fa'],
        ]]);
});

it('sets the locale from a tap and confirms it in the language just chosen', function () {
    asksForLanguages(['language_code' => 'en']);

    tapsLanguageButton('lg:fa');

    $user = User::query()->where('telegram_id', 777_000_3)->sole();

    expect($user->locale)->toBe('fa')
        ->and(latestBotMessage(2)['text'])->toBe(botCopy('bot.language.set', ['language' => 'فارسی'], 'fa'));
});

it('switches back the other way', function () {
    asksForLanguages(['language_code' => 'fa']);
    tapsLanguageButton('lg:en');

    $user = User::query()->where('telegram_id', 777_000_3)->sole();

    expect($user->locale)->toBe('en')
        ->and(latestBotMessage(2)['text'])->toBe(botCopy('bot.language.set', ['language' => 'English']));
});

it('refuses a crafted callback naming a locale the platform cannot serve', function () {
    asksForLanguages(['language_code' => 'en']);

    tapsLanguageButton('lg:fr');

    $user = User::query()->where('telegram_id', 777_000_3)->sole();

    // The refusal is phrased as a stale button, exactly like every other payload
    // that names something we do not have.
    expect($user->locale)->toBe('en')
        ->and(latestBotMessage(2)['text'])->toBe(botCopy('bot.fallback.stale_button'));
});

it('refuses a button with no locale on it at all', function () {
    asksForLanguages(['language_code' => 'en']);

    tapsLanguageButton('lg:');

    $user = User::query()->where('telegram_id', 777_000_3)->sole();

    expect($user->locale)->toBe('en')
        ->and(latestBotMessage(2)['text'])->toBe(botCopy('bot.fallback.stale_button'));
});

it('is open to a user still blocked at the channel gate', function () {
    $user = User::factory()->telegram(777_000_3)->preferring('en')->create();

    expect($user->hasVerifiedChannel())->toBeFalse();

    asksForLanguages(['language_code' => 'en']);

    // One `sendMessage` and no `getChatMember`: a blocked user still deserves to
    // read the blocking message in a language they understand, and the offer
    // costs nothing at the gate's Bot API budget.
    expect(soleBotMessage()['text'])->toBe(botCopy('bot.language.prompt'));
    Http::assertSentCount(1);
});
