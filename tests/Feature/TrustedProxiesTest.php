<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

/*
| Every deployment terminates TLS at a reverse proxy — Caddy in the Docker
| stack (deploy/edge/), Nginx on the VPS — and forwards cleartext to the app.
| These cover what silently breaks when the app does not trust it: https:// URL
| generation, the real client IP, and the original host.
|
| Each test issues a real request so the TrustProxies middleware actually runs.
| Constructing a Request and inspecting it directly would prove nothing: the
| middleware is what marks the proxy as trusted, and Symfony keeps that in
| static state, so a direct assertion would either fail or pass only because an
| earlier test had leaked into it.
*/

/**
 * A request exactly as it arrives from the edge.
 *
 * The URL is deliberately cleartext http:// against the container alias, because
 * that is the real hop: Caddy terminates TLS and proxies to `web:80` inside the
 * Docker network. Requesting the app's own APP_URL instead would hand the test a
 * request that is already https with the public host, and every assertion below
 * would pass whether or not the proxy were trusted.
 */
function fromEdge(array $headers = []): TestResponse
{
    return test()
        ->withServerVariables(['REMOTE_ADDR' => '172.18.0.5'])
        ->withHeaders($headers + [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'challenges.test',
        ])
        ->get('http://production-web/_test/proxy-probe');
}

beforeEach(function () {
    Route::get('/_test/proxy-probe', fn () => [
        'secure' => request()->isSecure(),
        'ip' => request()->ip(),
        'host' => request()->getHost(),
        'url' => url('/miniapp'),
    ]);
});

it('treats a forwarded request as secure', function () {
    // Untrusted, this is false and every generated link, redirect and Mini App
    // asset URL comes out as http:// — which Telegram webviews refuse to load.
    expect(fromEdge()->json('secure'))->toBeTrue();
});

it('resolves the real client IP rather than the proxy address', function () {
    // Rate limiters key on this. Untrusted, every user shares the proxy's one
    // address and a single noisy client throttles the whole platform.
    expect(fromEdge(['X-Forwarded-For' => '203.0.113.7'])->json('ip'))
        ->toBe('203.0.113.7');
});

it('honours the forwarded host', function () {
    // Inside the Docker network the Host header is the container alias
    // (`production-web`), not the public domain.
    expect(fromEdge()->json('host'))->toBe('challenges.test');
});

it('generates https urls for a forwarded request', function () {
    // The end-to-end symptom the three above add up to: a URL built during a
    // proxied request must carry the public scheme and host.
    expect(fromEdge()->json('url'))->toBe('https://challenges.test/miniapp');
});
