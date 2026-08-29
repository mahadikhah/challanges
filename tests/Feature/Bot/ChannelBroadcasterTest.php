<?php

use App\Enums\ChallengeVisibility;
use App\Enums\MessagingPlatform;
use App\Enums\PeriodType;
use App\Enums\ProofType;
use App\Enums\SettingKey;
use App\Exceptions\ChannelGateException;
use App\Jobs\Challenges\AnnounceChallenge;
use App\Models\Challenge;
use App\Services\Settings;
use App\Services\Telegram\ChannelBroadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Exceptions\TelegramSDKException;

uses(RefreshDatabase::class);

/*
 * The announcement-channel post, and the one thing about it that cannot be got
 * wrong: **a duplicate announcement cannot be recalled.**
 *
 * So the order is the reverse of the intuitive one — claim `announced_at` first in
 * a single conditional UPDATE, then post. Posting first and stamping after would
 * double-post on any failure between the two. A missed post, by contrast, is
 * visible in `Challenge::awaitsAnnouncement()` and can be re-dispatched, which is
 * why a failed post *releases* the claim and rethrows.
 */

beforeEach(function () {
    Http::preventStrayRequests();

    config(['services.telegram.bot_token' => '123456:TEST-TOKEN']);

    $this->settings = app(Settings::class);
    $this->settings->set(SettingKey::RequiredChannel, '@challenges');

    $this->broadcaster = app(ChannelBroadcaster::class);
});

/**
 * Telegram taking the post.
 */
function channelAcceptsPosts(): void
{
    Http::fake(['*sendMessage*' => Http::response(['ok' => true, 'result' => ['message_id' => 42]])]);
}

/**
 * Telegram refusing it — the bot demoted from the channel, most likely.
 */
function channelRefusesPosts(): void
{
    Http::fake(['*sendMessage*' => Http::response(
        ['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot is not a member of the channel chat'],
        403,
    )]);
}

/**
 * Everything posted to the channel, as decoded parameter arrays.
 *
 * @return list<array<string, string>>
 */
function channelPosts(): array
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
 * The buttons on the last channel post, decoded out of its `reply_markup`.
 *
 * @return list<array<string, string>>
 */
function channelPostButtons(int $post = 0): array
{
    $markup = channelPosts()[$post]['reply_markup'] ?? '';

    /** @var array{inline_keyboard: list<list<array<string, string>>>}|null $decoded */
    $decoded = json_decode((string) $markup, true);

    return array_merge(...array_map('array_values', $decoded['inline_keyboard'] ?? [[]]));
}

/**
 * A public challenge that has not been announced yet.
 */
function announceable(): Challenge
{
    return Challenge::factory()->publiclyVisible()->create([
        'title' => 'Read every day',
        'description' => 'Twenty pages, no excuses.',
        'total_periods' => 30,
    ]);
}

describe('posting a public challenge', function () {
    it('posts it to the configured channel and says it did', function () {
        channelAcceptsPosts();
        $challenge = announceable();

        expect($this->broadcaster->announce($challenge))->toBeTrue()
            ->and(channelPosts())->toHaveCount(1)
            ->and(channelPosts()[0]['chat_id'])->toBe('@challenges');
    });

    it('stamps the challenge announced', function () {
        channelAcceptsPosts();
        $challenge = announceable();

        $this->broadcaster->announce($challenge);

        expect($challenge->refresh()->announced_at)->not->toBeNull()
            ->and($challenge->awaitsAnnouncement())->toBeFalse();
    });

    it('reflects the stamp on the instance it was handed, not only in the database', function () {
        channelAcceptsPosts();
        $challenge = announceable();

        $this->broadcaster->announce($challenge);

        // The claim is a query-builder UPDATE, which the model instance knows
        // nothing about. A caller holding a stale `announced_at` would report the
        // challenge as still awaiting a post it just made.
        expect($challenge->announced_at)->not->toBeNull()
            ->and($challenge->isDirty())->toBeFalse();
    });

    it('says what the challenge is', function () {
        channelAcceptsPosts();
        $challenge = Challenge::factory()->publiclyVisible()->every(PeriodType::Weekly)
            ->provenBy(ProofType::ImageApproval)
            ->create(['title' => 'Read every day', 'description' => 'Twenty pages.', 'total_periods' => 12]);

        $this->broadcaster->announce($challenge);

        expect(channelPosts()[0]['text'])
            ->toContain(botCopy('bot.announce.headline', ['title' => 'Read every day']))
            ->toContain('Twenty pages.')
            ->toContain(botCopy('bot.announce.details', [
                'period' => botCopy(PeriodType::Weekly->translationKey()),
                'periods' => 12,
                'proof' => botCopy(ProofType::ImageApproval->translationKey()),
            ]));
    });

    it('carries a join button that deep-links the bot onto the challenge', function () {
        channelAcceptsPosts();
        $challenge = announceable();

        $this->broadcaster->announce($challenge);

        // A `url` button, not `callback_data`: a bot cannot write to somebody who
        // has never opened a private chat with it, and a channel post addresses
        // exactly that audience. The link has to carry them into the bot first.
        $button = channelPostButtons()[0];

        expect($button['text'])->toBe(botCopy('bot.announce.join_button'))
            ->and($button['url'])->toBe($challenge->refresh()->joinLink(MessagingPlatform::Telegram));
    });

    it('leaves no blank paragraph where a description was skipped', function () {
        channelAcceptsPosts();
        $challenge = Challenge::factory()->publiclyVisible()->create(['description' => null]);

        $this->broadcaster->announce($challenge);

        expect(channelPosts()[0]['text'])->not->toContain("\n\n\n");
    });

    it('posts in the platform’s fallback locale, not whoever happened to run the job', function () {
        channelAcceptsPosts();
        $challenge = announceable();

        // A channel has a mixed-language audience and no locale of its own. The
        // queue worker's ambient locale is whoever it served last, so a post that
        // resolved against `app()->getLocale()` would come out in a language chosen
        // at random.
        app()->setLocale('fa');

        $this->broadcaster->announce($challenge);

        expect(channelPosts()[0]['text'])
            ->toContain(botCopy('bot.announce.headline', ['title' => 'Read every day'], 'en'))
            ->and(channelPostButtons()[0]['text'])->toBe(botCopy('bot.announce.join_button', [], 'en'))
            ->not()->toBe(botCopy('bot.announce.join_button', [], 'fa'));
    });
});

describe('posting it twice', function () {
    it('posts once however many times it is asked', function () {
        channelAcceptsPosts();
        $challenge = announceable();

        $first = $this->broadcaster->announce($challenge);
        $second = $this->broadcaster->announce($challenge);

        // The retry that matters: `AnnounceChallenge` has `$tries = 2`, and a job
        // that timed out after a successful post comes back for another go.
        expect($first)->toBeTrue()
            ->and($second)->toBeFalse()
            ->and(channelPosts())->toHaveCount(1);
    });

    it('does not move the timestamp on the second attempt', function () {
        channelAcceptsPosts();
        $challenge = announceable();

        $this->broadcaster->announce($challenge);
        $announcedAt = $challenge->refresh()->announced_at;

        $this->broadcaster->announce($challenge);

        expect($challenge->refresh()->announced_at?->equalTo($announcedAt))->toBeTrue();
    });

    it('refuses a challenge another worker has already claimed', function () {
        channelAcceptsPosts();
        $challenge = announceable();

        // Two workers racing the same row. The claim is `whereNull('announced_at')`
        // in the UPDATE itself, so the loser sees zero affected rows — it never
        // reads-then-writes, which is where the double post would come from.
        Challenge::query()->whereKey($challenge->getKey())->update(['announced_at' => now()]);

        expect($this->broadcaster->announce($challenge))->toBeFalse();
        Http::assertNothingSent();
    });

    it('runs the job twice to one post', function () {
        channelAcceptsPosts();
        $challenge = announceable();

        (new AnnounceChallenge($challenge))->handle($this->broadcaster);
        (new AnnounceChallenge($challenge))->handle($this->broadcaster);

        expect(channelPosts())->toHaveCount(1);
    });
});

describe('when the post fails', function () {
    it('releases the claim so it can be tried again', function () {
        channelRefusesPosts();
        Log::spy();
        $challenge = announceable();

        try {
            $this->broadcaster->announce($challenge);
        } catch (Throwable) {
            // Asserted below.
        }

        // A challenge left marked announced that nobody ever saw is the failure
        // mode with no recovery: nothing would ever look at it again.
        expect($challenge->refresh()->announced_at)->toBeNull()
            ->and($challenge->awaitsAnnouncement())->toBeTrue();
    });

    it('rethrows, so the queue knows to retry', function () {
        channelRefusesPosts();
        Log::spy();

        expect(fn () => $this->broadcaster->announce(announceable()))
            ->toThrow(TelegramSDKException::class);
    });

    it('says why in the log', function () {
        channelRefusesPosts();
        Log::spy();

        try {
            $this->broadcaster->announce(announceable());
        } catch (Throwable) {
            // Asserted below.
        }

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => str_contains($message, 'could not be announced'))
            ->once();
    });

    it('does not release a claim that now belongs to somebody else', function () {
        Log::spy();
        $challenge = announceable();

        // The nastiest interleaving: our post fails, but by the time we go to
        // release, another worker has claimed and posted. Releasing unconditionally
        // would invite the duplicate the whole design exists to prevent — so the
        // release is guarded on `announced_at` still holding *our* timestamp.
        // `startOfSecond` because the column is a DATETIME with no fractional part:
        // comparing a microsecond-precision `now()` against what came back would
        // fail on the truncation rather than on the behaviour.
        $theirs = now()->addMinutes(5)->startOfSecond();

        Http::fake(['*sendMessage*' => function () use ($challenge, $theirs) {
            Challenge::query()->whereKey($challenge->getKey())->update(['announced_at' => $theirs]);

            return Http::response(['ok' => false, 'error_code' => 500, 'description' => 'Internal'], 500);
        }]);

        try {
            $this->broadcaster->announce($challenge);
        } catch (Throwable) {
            // Asserted below.
        }

        expect($challenge->refresh()->announced_at?->equalTo($theirs))->toBeTrue();
    });
});

describe('what it will not announce', function () {
    it('says there was nothing to announce for an invite-only challenge', function () {
        channelAcceptsPosts();

        // Reachable when a challenge changed visibility between dispatch and
        // execution. Not a failure — there is genuinely nothing to post.
        $challenge = Challenge::factory()->create(['visibility' => ChallengeVisibility::InviteOnly]);

        expect($this->broadcaster->announce($challenge))->toBeFalse()
            ->and($challenge->refresh()->announced_at)->toBeNull();
        Http::assertNothingSent();
    });

    it('refuses to post when no channel is configured', function () {
        channelAcceptsPosts();
        $this->settings->set(SettingKey::RequiredChannel, '');

        // The gate and the broadcaster read the same setting, so an empty one is a
        // misconfiguration that has to be loud in both places rather than a post
        // sent to nowhere.
        expect(fn () => $this->broadcaster->announce(announceable()))
            ->toThrow(ChannelGateException::class);
    });

    it('leaves the challenge announceable when there was no channel to announce to', function () {
        channelAcceptsPosts();
        $this->settings->set(SettingKey::RequiredChannel, '');
        $challenge = announceable();

        try {
            $this->broadcaster->announce($challenge);
        } catch (ChannelGateException) {
            // Asserted below.
        }

        expect($challenge->refresh()->awaitsAnnouncement())->toBeTrue();
    });
});
