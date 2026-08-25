<?php

use App\Services\Telegram\LaravelHttpClient;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Telegram\Bot\Api;
use Telegram\Bot\BotsManager;
use Telegram\Bot\Exceptions\TelegramResponseException;
use Telegram\Bot\Exceptions\TelegramSDKException;
use Telegram\Bot\FileUpload\InputFile;
use Telegram\Bot\Laravel\Facades\Telegram;
use Telegram\Bot\Laravel\TelegramServiceProvider as SdkServiceProvider;

/*
 * The outbound half of the bot: the SDK's requests must travel over Laravel's HTTP
 * client, because CLAUDE.md forbids reaching real Telegram endpoints from tests and
 * `Http::fake()` cannot see the Guzzle client the SDK would otherwise build itself.
 * These tests are the guard on that: if the transport is ever unwired, the fakes
 * here stop matching instead of the suite quietly making live API calls.
 */

beforeEach(function () {
    config(['services.telegram.bot_token' => '123456:TEST-TOKEN']);
});

function botApi(): Api
{
    return app(Api::class);
}

describe('the transport', function () {
    it('sends through Laravel’s HTTP client, so Http::fake() intercepts it', function () {
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 7]])]);

        $message = botApi()->sendMessage(['chat_id' => 42, 'text' => 'hello']);

        // The SDK's own response parsing still applies — we replaced the transport,
        // not the SDK.
        expect($message->message_id)->toBe(7);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.telegram.org/bot123456:TEST-TOKEN/sendMessage');
    });

    it('is the handler the SDK actually holds', function () {
        // Belt and braces for the test above: assert the wiring directly, so a
        // regression names itself instead of showing up as a mysterious fake miss.
        expect(botApi()->getClient()->getHttpClientHandler())->toBeInstanceOf(LaravelHttpClient::class);
    });

    it('form-encodes parameters the way Telegram expects', function () {
        Http::fake(['*' => Http::response(['ok' => true, 'result' => true])]);

        botApi()->sendChatAction(['chat_id' => 42, 'action' => 'typing']);

        Http::assertSent(function (Request $request): bool {
            // Assert the wire format, not Laravel's decoded view of it: Telegram
            // reads `application/x-www-form-urlencoded`, and the SDK's `getResult`
            // parsing assumes we sent it that way.
            expect($request->hasHeader('Content-Type', 'application/x-www-form-urlencoded'))->toBeTrue()
                ->and($request->body())->toBe('chat_id=42&action=typing');

            return true;
        });
    });

    it('sends an uploaded file as multipart', function () {
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 8]])]);

        botApi()->sendPhoto([
            'chat_id' => 42,
            'photo' => InputFile::createFromContents('not-really-a-jpeg', 'proof.jpg'),
        ]);

        Http::assertSent(function (Request $request): bool {
            expect($request->isMultipart())->toBeTrue()
                ->and($request->body())->toContain('proof.jpg')
                ->and($request->body())->toContain('not-really-a-jpeg');

            return true;
        });
    });

    it('sends a GET as query parameters rather than a body', function () {
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['url' => 'https://example.test/hook']])]);

        botApi()->getWebhookInfo();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && $request->body() === '');
    });

    it('carries the SDK’s own headers', function () {
        Http::fake(['*' => Http::response(['ok' => true, 'result' => true])]);

        botApi()->sendChatAction(['chat_id' => 42, 'action' => 'typing']);

        Http::assertSent(fn (Request $request): bool => str_contains($request->header('User-Agent')[0] ?? '', 'Telegram Bot PHP SDK'));
    });
});

describe('failures', function () {
    it('raises Telegram’s own description when Telegram refuses', function () {
        // Laravel's client does not throw on 4xx, which is what lets the `ok: false`
        // body reach the SDK and become a useful message instead of "HTTP 400".
        Http::fake(['*' => Http::response([
            'ok' => false,
            'error_code' => 400,
            'description' => 'Bad Request: chat not found',
        ], 400)]);

        expect(fn () => botApi()->sendMessage(['chat_id' => 42, 'text' => 'hello']))
            ->toThrow(TelegramResponseException::class, 'Bad Request: chat not found');
    });

    it('raises a TelegramSDKException when the connection fails', function () {
        // One exception type whichever transport is installed: the SDK's Guzzle
        // client converts transport failures the same way, so call sites written
        // against the SDK keep working.
        Http::fake(['*' => Http::failedConnection('cURL error 28: Operation timed out')]);

        expect(fn () => botApi()->sendMessage(['chat_id' => 42, 'text' => 'hello']))
            ->toThrow(TelegramSDKException::class);
    });

    it('refuses asynchronous requests instead of dropping them', function () {
        // The SDK's async mode parks promises and unwraps them in a destructor.
        // Silently sending synchronously would be a lie; silently not sending would
        // be worse.
        expect(fn () => (new LaravelHttpClient)->send(
            url: 'https://api.telegram.org/bot123456:TEST-TOKEN/sendMessage',
            method: 'POST',
            isAsyncRequest: true,
        ))->toThrow(TelegramSDKException::class, 'Asynchronous Bot API requests are not supported');

        Http::assertNothingSent();
    });

    it('refuses to build a bot with no token, naming the real problem', function () {
        config(['services.telegram.bot_token' => null]);

        expect(fn () => botApi())->toThrow(TelegramSDKException::class, 'TELEGRAM_BOT_TOKEN is not set');
    });
});

describe('one way in', function () {
    it('does not register the SDK’s Laravel integration', function () {
        // `Telegram::sendMessage()` would resolve through BotsManager, which builds
        // its own bot and never consults the container — an unfakeable second path
        // to the real API. It stays unwired on purpose.
        expect(app()->providerIsLoaded(SdkServiceProvider::class))->toBeFalse()
            ->and(app()->bound(BotsManager::class))->toBeFalse();
    });

    it('fails loudly if anyone reaches for the facade', function () {
        // BotsManager cannot even be constructed without its config array, so the
        // facade dies at resolution rather than quietly building a Guzzle-backed bot.
        expect(fn () => Telegram::getFacadeRoot())->toThrow(BindingResolutionException::class);
    });
});

describe('the timeout contract', function () {
    it('round-trips the timeouts the SDK sets per request', function () {
        $client = new LaravelHttpClient;

        expect($client->setTimeOut(11))->toBe($client)
            ->and($client->getTimeOut())->toBe(11)
            ->and($client->setConnectTimeOut(3))->toBe($client)
            ->and($client->getConnectTimeOut())->toBe(3);
    });
});
