<?php

use App\Providers\TelegramServiceProvider;
use App\Services\Telegram\BotCommandMenu;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

/*
 * The command menu is the only place a command is discoverable. Telegram shows
 * nothing until `setMyCommands` has been called, so the failure this file guards
 * against is not a wrong menu — it is no menu, which is what the bot had before:
 * `/checkin` and `/shop` reachable only by guessing a word, and `/chatlink`
 * reachable only by a creator who already knew it existed.
 *
 * The command under test is `telegram:set-webhook`, because that is the one every
 * deploy already runs (see `docs/setup-*.md`). The menu rides along with it rather
 * than living in a third command nobody knows to run.
 */

beforeEach(function () {
    config([
        'services.telegram.bot_token' => '123456:TEST-TOKEN',
        'services.telegram.webhook_secret' => 'path-secret',
        'services.telegram.webhook_header_secret' => 'header-secret',
    ]);

    // In console the URL generator takes its root and scheme from the request
    // Laravel synthesises at boot, so force both rather than changing app.url.
    URL::forceRootUrl('https://challenges.test');
    URL::forceScheme('https');

    // Telegram is never reached on any path: an unstubbed call throws rather than
    // leaving the suite free to dial out.
    Http::preventStrayRequests();
});

/**
 * Telegram's answer to everything the command asks: both registrations accepted.
 */
function menuAccepts(): void
{
    Http::fake(['*' => Http::response(['ok' => true, 'result' => true])]);
}

/**
 * Every menu Telegram was handed, in the order it was sent.
 *
 * `Http::recorded()` rather than `assertSent()` because what matters here is the
 * *sequence*: one unscoped set, then one per language, each carrying the words a
 * speaker of that language reads.
 *
 * @return list<array{language_code: string|null, commands: list<array{command: string, description: string}>}>
 */
function menuCalls(): array
{
    return collect(Http::recorded())
        ->map(static fn (array $pair): Request => $pair[0])
        ->filter(static fn (Request $request): bool => str_ends_with($request->url(), '/setMyCommands'))
        ->map(function (Request $request): array {
            parse_str($request->body(), $sent);

            /** @var list<array{command: string, description: string}> $commands */
            $commands = json_decode((string) ($sent['commands'] ?? '[]'), true);

            return [
                // Absent, not blank, on the default set — see the model.
                'language_code' => $sent['language_code'] ?? null,
                'commands' => $commands,
            ];
        })
        ->values()
        ->all();
}

it('registers one set per language, plus an unscoped default', function () {
    menuAccepts();

    $this->artisan('telegram:set-webhook')->assertSuccessful();

    // The unscoped call is not redundant with the `en` one. Telegram keeps it as
    // the set a user reads when their client's language has no dedicated set, so
    // without it a German client opens the bot to no menu at all.
    expect(array_column(menuCalls(), 'language_code'))->toBe([null, 'en', 'fa']);
});

it('sends each language its own words', function () {
    menuAccepts();

    $this->artisan('telegram:set-webhook')->assertSuccessful();

    $sets = collect(menuCalls())->keyBy(static fn (array $set): string => (string) $set['language_code']);

    expect($sets['en']['commands'][0]['description'])->toContain('Begin')
        ->and($sets['fa']['commands'][0]['description'])->toContain('شروع')
        // The default set is the fallback locale's, so it carries the same words.
        ->and($sets['']['commands'])->toBe($sets['en']['commands']);
});

it('leads with start, trails with cancel, and sorts what is left', function () {
    menuAccepts();

    $this->artisan('telegram:set-webhook')->assertSuccessful();

    foreach (menuCalls() as $set) {
        $words = array_column($set['commands'], 'command');
        $middle = array_slice($words, 1, -1);
        $sorted = $middle;
        sort($sorted);

        // The stated rule, verified rather than assumed: the first and last lines
        // are the affordance (`start` is the way back, `cancel` the way out) and
        // the middle is alphabetical, so adding a command never reopens the
        // question of where it goes. Telegram renders the list in this order.
        expect($words)->toHaveCount(8)
            ->and($words[0])->toBe('start')
            ->and(end($words))->toBe('cancel')
            ->and($middle)->toBe($sorted);
    }
});

it('carries a line for every command the router serves', function () {
    // The tripwire, in the same shape as the settings panel's "every registry
    // tunable exactly once": add a handler to `BOT_COMMANDS` and this fails until
    // the new word is either given a line here or deliberately left out and said
    // so. A command the bot answers to and the menu omits is a command nobody
    // finds — which is the defect this whole task exists to fix.
    expect(app(BotCommandMenu::class)->order())
        ->toEqualCanonicalizing(array_keys(TelegramServiceProvider::BOT_COMMANDS));
});

it('describes every command in both locales', function () {
    $menu = app(BotCommandMenu::class);

    foreach ($menu->locales() as $locale) {
        foreach ($menu->forLocale($locale) as $entry) {
            expect($entry['description'])
                // A missing key resolves to the key itself, so this is exactly
                // what an unwritten menu line looks like from here.
                ->not->toBe("bot.commands.{$entry['command']}")
                ->not->toBeEmpty();
        }
    }

    $en = array_column($menu->forLocale('en'), 'description');
    $fa = array_column($menu->forLocale('fa'), 'description');

    expect($fa)->toHaveCount(count($en));

    foreach ($en as $index => $line) {
        // Line by line rather than array against array: `Lang::get()` degrades
        // silently to the fallback locale, so a Farsi line that was never written
        // comes back as the English one and would pass the check above.
        expect($fa[$index])->not->toBe($line);
    }
});

it('sends only words and lines Telegram accepts', function () {
    $menu = app(BotCommandMenu::class);

    foreach ($menu->order() as $command) {
        // Telegram's rule for a command name: lowercase a–z, digits and
        // underscores, 1–32 characters. A word outside it fails the entire
        // registration, not just that one line.
        expect($command)->toMatch('/^[a-z0-9_]{1,32}$/');
    }

    foreach ($menu->locales() as $locale) {
        foreach ($menu->forLocale($locale) as $entry) {
            expect(mb_strlen($entry['description']))->toBeLessThanOrEqual(256)
                // The copy rules, as a tripwire rather than a convention: terse,
                // imperative, no leading slash (Telegram draws its own) and no
                // trailing period.
                ->and($entry['description'])->not->toStartWith('/')
                ->and($entry['description'])->not->toEndWith('.');
        }
    }
});

it('is safe to run twice', function () {
    menuAccepts();

    $this->artisan('telegram:set-webhook')->assertSuccessful();
    $first = menuCalls();

    $this->artisan('telegram:set-webhook')->assertSuccessful();

    // Registration is the whole of it — there is no local state to corrupt, the
    // payload is identical, and Telegram overwrites a set rather than appending
    // to it. This is why the menu can share a command with the webhook, which is
    // itself re-run whenever a secret or `HANDLED_KINDS` changes.
    expect(array_slice(menuCalls(), count($first)))->toBe($first);
});

it('names the language Telegram refused', function () {
    // The first two sets accepted, the Farsi one refused: a registration that is
    // half done, and the operator needs to know which half is missing.
    Http::fake([
        '*setMyCommands*' => Http::sequence()
            ->push(['ok' => true, 'result' => true])
            ->push(['ok' => true, 'result' => true])
            ->push(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: command list too long'], 400),
        '*' => Http::response(['ok' => true, 'result' => true]),
    ]);

    $this->artisan('telegram:set-webhook')
        // One expected substring, not two: both facts are on the same line, and
        // `expectsOutputToContain()` matches per write, so a second expectation
        // against the same line is never reached and never fails.
        ->expectsOutputToContain('command menu for fa: Bad Request: command list too long')
        ->assertFailed();
});

it('registers no menu when the webhook itself cannot be registered', function () {
    // A menu on a bot that receives nothing is not a partial win: it would
    // advertise commands whose every answer depends on the webhook landing.
    menuAccepts();
    config(['services.telegram.webhook_secret' => null]);

    $this->artisan('telegram:set-webhook')->assertFailed();

    Http::assertNothingSent();
});

it('says what it registered, and never the token it registered with', function () {
    menuAccepts();

    $this->artisan('telegram:set-webhook')
        ->expectsOutputToContain('Command menu registered.')
        // The order on screen is the order in the app, so an operator comparing
        // the two is comparing the right things.
        ->expectsOutputToContain('start, challenges, chatlink, checkin, create, language, shop, cancel')
        ->doesntExpectOutputToContain('TEST-TOKEN')
        ->assertSuccessful();
});
