<?php

use App\Enums\MessagingPlatform;
use App\Messaging\PlatformRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
 * The suite's own environment, pinned.
 *
 * Every Telegram and Bale call a test makes is `Http::fake()`d, so no test ever
 * needs a real bot token — but the token has to *exist* before the SDK will
 * build an `Api` at all. So "stubbed" and "missing" do not look alike: a missing
 * one is a hard failure inside whichever test happens to resolve a platform
 * first, which is what `TelegramServiceProvider` and `BaleMessengerPlatform::api()`
 * are both written to do.
 *
 * That is not hypothetical. CI copies `.env.example`, where both tokens are
 * empty, while a developer's `.env` carries live ones — so five observability
 * tests passed on the machine they were written on and failed in CI, and the
 * machine that said "green" was the one holding real credentials. `phpunit.xml`
 * now declares inert tokens alongside the other doubles it already configures
 * (array mail, array cache, sync queue, null broadcast).
 *
 * These assertions are what keeps that true. They are also the only way to see
 * it: on a developer's machine the real token would make a broken suite pass, so
 * "the tests are green" is not evidence that the declarations work. Reading the
 * resolved value is.
 */

it('runs against its own inert bot tokens, never an ambient credential', function (): void {
    // Pinned to the exact literals `phpunit.xml` declares. A real token here —
    // from `.env` or an exported variable — means the suite's outcome depends on
    // who is running it, which is the bug rather than a detail of it.
    expect(config('services.telegram.bot_token'))->toBe('123456:TEST-TOKEN')
        ->and(config('services.bale.bot_token'))->toBe('654321:TEST-TOKEN');
});

it('builds a usable platform for every messenger without a real token', function (): void {
    // The line that threw in CI. Resolving the platform is where the token is
    // demanded, so this is the failure itself, for both messengers rather than
    // for whichever one a test happened to reach first.
    Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

    $platforms = app(PlatformRegistry::class);

    $platforms->for(MessagingPlatform::Telegram)->sendMessage(4242, 'hello');
    $platforms->for(MessagingPlatform::Bale)->sendMessage(4242, 'hello');

    expect(Http::recorded())->toHaveCount(2);
});
