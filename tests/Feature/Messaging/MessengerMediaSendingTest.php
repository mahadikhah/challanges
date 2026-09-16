<?php

use App\Messaging\Bale\BaleMessengerPlatform;
use App\Messaging\Contracts\MessengerException;
use App\Messaging\Contracts\MessengerPlatform;
use App\Messaging\Telegram\TelegramMessengerPlatform;
use Illuminate\Http\Client\Request;

/*
 * The media half of the `MessengerPlatform` contract: a photo, a voice message
 * and a video, each uploadable from bytes and each able to carry an inline
 * keyboard.
 *
 * Every assertion runs against **both** implementations. The contract exists so
 * shared code can send a proof to a creator without knowing which platform the
 * creator is on, and that promise is only worth anything if the two behave
 * alike — so a future third platform has this file as its template.
 *
 * The keyboard riding on the media message is not decoration: a caller that
 * sent the sentence first and the media second would spend two of the roughly
 * one message a second the platform allows per chat, and the second send is the
 * one that gets refused.
 */

beforeEach(function () {
    // Both Api clients refuse to exist without a token — nothing here reads one,
    // but the platforms still must resolve.
    config([
        'services.telegram.bot_token' => '123456:TEST-TOKEN',
        'services.bale.bot_token' => '123456:BALE-TEST-TOKEN',
        'services.bale.bot_username' => '@challengesbot',
    ]);

    // No catch-all fake: `Http::fake()` appends and the first matching pattern
    // wins, so a catch-all would shadow every per-test stub.
    Http::preventStrayRequests();
});

dataset('messenger platforms', [
    'telegram' => ['telegram'],
    'bale' => ['bale'],
]);

/**
 * The platform for a dataset key.
 */
function mediaPlatform(string $key): MessengerPlatform
{
    return $key === 'bale'
        ? app(BaleMessengerPlatform::class)
        : app(TelegramMessengerPlatform::class);
}

/**
 * Answer one media endpoint with a message id, and everything else with a
 * refusal — so a send this test did not ask for fails loudly rather than
 * quietly succeeding against a broad stub.
 */
function mediaEndpointAnswers(string $endpoint): void
{
    Http::fake([
        '*'.$endpoint.'*' => Http::response(['ok' => true, 'result' => ['message_id' => 31]]),
    ]);
}

/**
 * The one request the platform made to an endpoint, asserting it *was* one.
 *
 * The count matters as much as the contents: a caller that followed the media
 * with a second message would blow the per-chat rate limit, so "exactly one
 * send" is the rule being checked here, not a tidiness preference.
 */
function soleMediaRequest(string $endpoint): Request
{
    $recorded = Http::recorded(
        fn (Request $request): bool => str_contains($request->url(), $endpoint),
    );

    expect($recorded)->toHaveCount(1);

    return $recorded->first()[0];
}

/**
 * One field of a multipart body, exactly as the wire carried it.
 *
 * Read out of the raw body rather than through Laravel's parsed view of it: the
 * SDK uploads with `attach()`, and the thing under test is the bytes that left
 * the process, not a summary of them. The part's own headers are skipped rather
 * than named, because a file part carries `Content-Type` and a scalar part does
 * not.
 */
function multipartField(Request $request, string $name): ?string
{
    $matched = preg_match(
        '/name="'.preg_quote($name, '/').'"[^\r\n]*\r\n(?:[^\r\n]*\r\n)*?\r\n(.*?)\r\n--/s',
        $request->body(),
        $matches,
    );

    return $matched === 1 ? $matches[1] : null;
}

/**
 * The decoded inline keyboard a multipart send carried, or an empty list.
 *
 * @return list<list<array<string, string>>>
 */
function keyboardInMultipart(Request $request): array
{
    $markup = multipartField($request, 'reply_markup');

    if ($markup === null) {
        return [];
    }

    $decoded = json_decode($markup, true, 512, JSON_THROW_ON_ERROR);

    /** @var list<list<array<string, string>>> $keyboard */
    $keyboard = is_array($decoded) ? ($decoded['inline_keyboard'] ?? []) : [];

    return $keyboard;
}

/**
 * The verdict keyboard Tasks 2 and 3 will attach, so the shape under test is
 * the real one rather than a two-row stand-in.
 *
 * @return list<list<array<string, string>>>
 */
function verdictKeyboard(): array
{
    return [
        [
            ['text' => 'Approve', 'callback_data' => 'rv:7:a'],
            ['text' => 'Reject', 'callback_data' => 'rv:7:r'],
        ],
    ];
}

it('uploads a voice message as multipart, carrying its caption and keyboard', function (string $key) {
    mediaEndpointAnswers('sendVoice');

    $sent = mediaPlatform($key)->sendVoice(
        chatId: 42,
        bytes: 'not-really-an-ogg',
        filename: 'proof.ogg',
        captionLines: ['Sahar checked in.', 'Day 3 of 10.'],
        inlineKeyboard: verdictKeyboard(),
    );

    expect($sent->messageId)->toBe(31);

    $request = soleMediaRequest('sendVoice');

    expect($request->isMultipart())->toBeTrue()
        ->and($request->body())->toContain('not-really-an-ogg')
        ->and($request->body())->toContain('proof.ogg')
        ->and(multipartField($request, 'chat_id'))->toBe('42')
        ->and(multipartField($request, 'caption'))->toBe("Sahar checked in.\n\nDay 3 of 10.")
        ->and(keyboardInMultipart($request))->toBe(verdictKeyboard());
})->with('messenger platforms');

it('uploads a video message as multipart, carrying its caption and keyboard', function (string $key) {
    mediaEndpointAnswers('sendVideo');

    mediaPlatform($key)->sendVideo(
        chatId: 42,
        bytes: 'not-really-an-mp4',
        filename: 'proof.mp4',
        captionLines: ['Sahar checked in.'],
        inlineKeyboard: verdictKeyboard(),
    );

    $request = soleMediaRequest('sendVideo');

    expect($request->isMultipart())->toBeTrue()
        ->and($request->body())->toContain('not-really-an-mp4')
        ->and($request->body())->toContain('proof.mp4')
        ->and(multipartField($request, 'caption'))->toBe('Sahar checked in.')
        ->and(keyboardInMultipart($request))->toBe(verdictKeyboard());
})->with('messenger platforms');

it('carries a keyboard on a photo', function (string $key) {
    mediaEndpointAnswers('sendPhoto');

    mediaPlatform($key)->sendPhoto(
        chatId: 42,
        bytes: 'not-really-a-jpeg',
        filename: 'proof.jpg',
        captionLines: ['Sahar checked in.'],
        inlineKeyboard: verdictKeyboard(),
    );

    $request = soleMediaRequest('sendPhoto');

    expect($request->isMultipart())->toBeTrue()
        ->and($request->body())->toContain('not-really-a-jpeg')
        ->and(keyboardInMultipart($request))->toBe(verdictKeyboard());
})->with('messenger platforms');

it('sends a photo with no keyboard at all when none is given', function (string $key) {
    // The regression bar for the one caller that existed before keyboards were
    // optional: `ChatBroadcaster::sendPhoto`.
    mediaEndpointAnswers('sendPhoto');

    mediaPlatform($key)->sendPhoto(
        chatId: 42,
        bytes: 'not-really-a-jpeg',
        filename: 'proof.jpg',
        captionLines: ['A new check-in was announced.'],
    );

    $request = soleMediaRequest('sendPhoto');

    expect(multipartField($request, 'reply_markup'))->toBeNull()
        ->and(keyboardInMultipart($request))->toBe([])
        ->and($request->body())->not->toContain('inline_keyboard');
})->with('messenger platforms');

it('drops null and blank caption lines, joining the survivors with a blank line', function (string $key) {
    mediaEndpointAnswers('sendVoice');

    mediaPlatform($key)->sendVoice(
        chatId: 42,
        bytes: 'not-really-an-ogg',
        filename: 'proof.ogg',
        captionLines: [null, 'First.', '', '   ', 'Second.'],
    );

    expect(multipartField(soleMediaRequest('sendVoice'), 'caption'))->toBe("First.\n\nSecond.");
})->with('messenger platforms');

it('sends an empty caption rather than dropping the field', function (string $key) {
    // A caller with nothing to say still sends a media message; the omission
    // would change the wire shape for no gain, and the caption part is what the
    // platform reads to decide whether a caption exists at all.
    mediaEndpointAnswers('sendVideo');

    mediaPlatform($key)->sendVideo(
        chatId: 42,
        bytes: 'not-really-an-mp4',
        filename: 'proof.mp4',
        captionLines: [null, ''],
    );

    expect(multipartField(soleMediaRequest('sendVideo'), 'caption'))->toBe('');
})->with('messenger platforms');

it('raises one exception family when the platform refuses a media send', function (string $key) {
    Http::fake([
        '*sendVoice*' => Http::response([
            'ok' => false,
            'error_code' => 400,
            'description' => 'Bad Request: VOICE_PART_INVALID',
        ], 400),
    ]);

    expect(fn () => mediaPlatform($key)->sendVoice(
        chatId: 42,
        bytes: 'not-really-an-ogg',
        filename: 'proof.ogg',
        captionLines: ['Sahar checked in.'],
    ))->toThrow(MessengerException::class);
})->with('messenger platforms');

it('raises one exception family when a media upload cannot reach the platform', function (string $key) {
    Http::fake(['*sendVideo*' => Http::failedConnection('cURL error 28: Operation timed out')]);

    expect(fn () => mediaPlatform($key)->sendVideo(
        chatId: 42,
        bytes: 'not-really-an-mp4',
        filename: 'proof.mp4',
        captionLines: ['Sahar checked in.'],
    ))->toThrow(MessengerException::class);
})->with('messenger platforms');
