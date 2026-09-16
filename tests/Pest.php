<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
|--------------------------------------------------------------------------
| Reading the bot's replies
|--------------------------------------------------------------------------
|
| Shared by every test that drives an update through the bot. They live here
| rather than in whichever bot test file needed them first, because a helper
| defined in one test file only exists when that file happens to be loaded —
| so running a single file would fail on an undefined function.
|
*/

/**
 * Every `sendMessage` the bot made, as decoded parameter arrays.
 *
 * @return list<array<string, string>>
 */
function botMessages(): array
{
    return Http::recorded(
        fn (Request $request): bool => str_contains($request->url(), 'sendMessage'),
    )->map(function (array $call): array {
        $sent = [];
        parse_str($call[0]->body(), $sent);

        /** @var array<string, string> $sent */
        return $sent;
    })->values()->all();
}

/**
 * The one message the bot sent — asserting that it *was* one.
 *
 * Telegram allows roughly a message a second per chat, so "one reply per update" is
 * a rule rather than a tidiness preference, and assertions go through here so a
 * second message anywhere fails loudly.
 *
 * @return array<string, string>
 */
function soleBotMessage(): array
{
    $messages = botMessages();

    expect($messages)->toHaveCount(1);

    return $messages[0];
}

/**
 * The bot's most recent message, for a test that put several updates through.
 *
 * Still asserts one message per update, so the rate-limit rule `soleBotMessage()`
 * enforces for a single arrival holds here too.
 *
 * @return array<string, string>
 */
function latestBotMessage(int $ofTotal): array
{
    $messages = botMessages();

    expect($messages)->toHaveCount($ofTotal);

    return $messages[$ofTotal - 1];
}

/**
 * The bot's most recent reply, without asserting how many came before it.
 *
 * For a multi-step flow, where the number of replies is a property of the walk
 * rather than of the thing under test. Where "exactly one reply" is the assertion,
 * use `soleBotMessage()` or `latestBotMessage()` instead.
 *
 * @return array<string, string>
 */
function lastBotReply(): array
{
    $messages = botMessages();

    expect($messages)->not->toBeEmpty();

    return $messages[count($messages) - 1];
}

/**
 * Every media send the bot made: the endpoint it went to and its fields.
 *
 * `botMessages()` reads form-encoded bodies, and a media send is multipart —
 * `parse_str` would see one boundary blob and read nothing — so these come out
 * of the raw body instead.
 *
 * `endpoint` is the SDK method's own suffix, lower-cased: `photo`, `voice` or
 * `video`, matching `CheckIn::proofKind()`'s vocabulary.
 *
 * @return list<array{endpoint: string, fields: array<string, string>}>
 */
function botMediaMessages(): array
{
    return Http::recorded(
        fn (Request $request): bool => preg_match('#/send(Photo|Voice|Video)$#', $request->url()) === 1,
    )->map(function (array $call): array {
        /** @var Request $request */
        $request = $call[0];

        $matched = preg_match('#/send(Photo|Voice|Video)$#', $request->url(), $matches);

        return [
            'endpoint' => $matched === 1 ? strtolower($matches[1]) : '',
            'fields' => multipartFields($request),
        ];
    })->values()->all();
}

/**
 * Every part of a multipart body, as the wire carried it — field name to value.
 *
 * The part's own headers are skipped rather than named, because a file part
 * carries `Content-Type` and a scalar part does not. Values are read literally
 * rather than through Laravel's parsed view of them: for a media send the thing
 * under test is the bytes that left the process.
 *
 * @return array<string, string>
 */
function multipartFields(Request $request): array
{
    preg_match_all(
        '/name="([^"]+)"[^\r\n]*\r\n(?:[^\r\n]*\r\n)*?\r\n(.*?)\r\n--/s',
        $request->body(),
        $matches,
        PREG_SET_ORDER,
    );

    $fields = [];

    foreach ($matches as $match) {
        $fields[$match[1]] = $match[2];
    }

    return $fields;
}

/**
 * One part of a multipart body, or null when the send carried no such part.
 */
function multipartField(Request $request, string $name): ?string
{
    return multipartFields($request)[$name] ?? null;
}

/**
 * A line of bot copy, so assertions compare against the translation files rather
 * than English pasted into a test and left behind by the next copy change.
 *
 * @param  array<string, string|int|float>  $replace
 */
function botCopy(string $key, array $replace = [], string $locale = 'en'): string
{
    $line = Lang::get($key, $replace, $locale);

    return is_string($line) ? $line : $key;
}

/**
 * The inline keyboard on the bot's one reply, decoded from the JSON it sent.
 *
 * @return list<list<array<string, string>>>
 */
function botKeyboard(): array
{
    return keyboardOn(soleBotMessage());
}

/**
 * The inline keyboard on the bot's most recent reply.
 *
 * @return list<list<array<string, string>>>
 */
function lastBotKeyboard(): array
{
    return keyboardOn(lastBotReply());
}

/**
 * @param  array<string, string>  $message
 * @return list<list<array<string, string>>>
 */
function keyboardOn(array $message): array
{
    $markup = $message['reply_markup'] ?? null;

    if (! is_string($markup)) {
        return [];
    }

    $decoded = json_decode($markup, true, 512, JSON_THROW_ON_ERROR);

    /** @var list<list<array<string, string>>> $keyboard */
    $keyboard = is_array($decoded) ? ($decoded['inline_keyboard'] ?? []) : [];

    return $keyboard;
}
