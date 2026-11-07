<?php

namespace App\Services\Telegram;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use Telegram\Bot\Exceptions\TelegramSDKException;
use Telegram\Bot\HttpClients\HttpClientInterface;

/**
 * Sends the bot SDK's requests through Laravel's HTTP client.
 *
 * The SDK ships a Guzzle-backed transport that news up its own `GuzzleHttp\Client`.
 * `Http::fake()` cannot see that client, so on the default transport every test
 * that touched an outbound call would reach api.telegram.org for real — which
 * CLAUDE.md forbids outright: "Always `Http::fake()` the Bot API. Never hit real
 * Telegram endpoints in tests." Swapping the transport is the SDK's own extension
 * point, so we keep its whole method surface (and `Keyboard`, and the `Objects`
 * parsing) while getting fakeable, loggable, retryable requests for nothing.
 *
 * The mapping is very nearly a pass-through, because Laravel's client is itself a
 * Guzzle wrapper that takes the same option names the SDK speaks — `form_params`,
 * `multipart`, `query`, `sink`. `Http::asForm()->post($url, $data)` is literally
 * `send('POST', $url, ['form_params' => $data])` underneath. The only translation
 * needed is telling Laravel which of the options is meant to be the body.
 */
class LaravelHttpClient implements HttpClientInterface
{
    private int $timeOut = 60;

    private int $connectTimeOut = 10;

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $options
     *
     * @throws TelegramSDKException
     */
    public function send(
        string $url,
        string $method,
        array $headers = [],
        array $options = [],
        bool $isAsyncRequest = false
    ): ResponseInterface|PromiseInterface|null {
        if ($isAsyncRequest) {
            // The SDK's async mode collects promises and unwraps them in a
            // destructor. Nothing here needs it — reminder fan-out is spaced with
            // queued jobs and `delay()` precisely because Telegram rate-limits per
            // chat, so firing concurrently would be the wrong shape anyway. Refuse
            // loudly rather than quietly downgrade to a blocking send.
            throw new TelegramSDKException(
                'Asynchronous Bot API requests are not supported. Leave TELEGRAM_ASYNC_REQUESTS unset and stagger sends with queued jobs instead.'
            );
        }

        try {
            return $this->pendingRequest($headers, $options)
                ->send($method, $url, $options)
                ->toPsrResponse();
        } catch (ConnectionException $e) {
            // A Telegram *error* is not an exception here: Laravel's client does
            // not throw on 4xx, so the `ok: false` body reaches TelegramResponse
            // and the SDK raises TelegramResponseException with Telegram's own
            // description. Only a transport failure lands in this catch, and the
            // SDK's own Guzzle client converts those to TelegramSDKException too —
            // so call sites catch one type whichever transport is installed.
            throw new TelegramSDKException($e->getMessage(), $e->getCode(), $e);
        }
    }

    public function getTimeOut(): int
    {
        return $this->timeOut;
    }

    public function setTimeOut(int $timeOut): static
    {
        $this->timeOut = $timeOut;

        return $this;
    }

    public function getConnectTimeOut(): int
    {
        return $this->connectTimeOut;
    }

    public function setConnectTimeOut(int $connectTimeOut): static
    {
        $this->connectTimeOut = $connectTimeOut;

        return $this;
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $options
     */
    private function pendingRequest(array $headers, array $options): PendingRequest
    {
        $request = Http::withHeaders($headers)
            ->timeout($this->timeOut)
            ->connectTimeout($this->connectTimeOut);

        // Laravel encodes the body according to its own body format, which
        // defaults to JSON. Telegram wants form-encoded params, and multipart when
        // a file is attached, so name whichever one the SDK handed us. Anything
        // else (a GET's `query`, a download's `sink`) is not a body and passes
        // through untouched.
        return match (true) {
            isset($options['multipart']) => $request->asMultipart(),
            isset($options['form_params']) => $request->asForm(),
            default => $request,
        };
    }
}
