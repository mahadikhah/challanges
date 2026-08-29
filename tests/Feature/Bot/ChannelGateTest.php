<?php

use App\Actions\Telegram\VerifyChannelMembership;
use App\Enums\SettingKey;
use App\Exceptions\ChannelGateException;
use App\Models\User;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Telegram\Bot\Exceptions\TelegramSDKException;

uses(RefreshDatabase::class);

/*
 * The access gate. Every user must be in the announcement channel before using the
 * bot, so the interesting cases here are all the ways "not a member" arrives:
 * `left`, `kicked`, a restriction that has been removed from the chat, a status
 * Telegram has not invented yet, an unconfigured channel, and a Telegram error.
 * Every one of them has to read as "no".
 *
 * `Http::preventStrayRequests()` rather than a catch-all fake, because
 * `Http::fake()` *appends*: a catch-all registered in `beforeEach` would win every
 * match and silently shadow the per-test stubs below.
 */

beforeEach(function () {
    Http::preventStrayRequests();

    config(['services.telegram.bot_token' => '123456:TEST-TOKEN']);

    $this->settings = app(Settings::class);
    $this->settings->set(SettingKey::RequiredChannel, '@challenges');

    $this->gate = app(VerifyChannelMembership::class);
    $this->user = User::factory()->telegram(777_000_2)->create();
});

/**
 * Stub Telegram's answer about a membership.
 *
 * The URL pattern has to tolerate a query string: `getChatMember` goes out as a
 * GET, so `$request->url()` ends `…/getChatMember?chat_id=…`, and a pattern
 * anchored on the method name alone would never match.
 *
 * @param  array<string, mixed>  $extra  merged over the ChatMember object
 */
function membershipStatus(string $status, array $extra = []): void
{
    Http::fake([
        '*getChatMember*' => Http::response([
            'ok' => true,
            'result' => array_replace([
                'status' => $status,
                'user' => ['id' => 777_000_2, 'is_bot' => false, 'first_name' => 'Sara'],
            ], $extra),
        ]),
    ]);
}

/**
 * What we asked Telegram, decoded from the GET query string.
 *
 * @return array<string, string>
 */
function membershipQuestion(Request $request): array
{
    $query = [];
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    /** @var array<string, string> $query */
    return $query;
}

describe('the verdict', function () {
    it('reads Telegram’s status', function (string $status, bool $expected) {
        membershipStatus($status);

        expect($this->gate->handle($this->user))->toBe($expected);
    })->with([
        'creator' => ['creator', true],
        'administrator' => ['administrator', true],
        'member' => ['member', true],
        'left' => ['left', false],
        'kicked' => ['kicked', false],
    ]);

    it('treats a status we do not recognise as a no', function () {
        // Telegram is free to add a status. Fail closed on one we have never seen
        // rather than letting an unknown string fall through as access.
        membershipStatus('something_telegram_added_later');

        expect($this->gate->handle($this->user))->toBeFalse();
    });

    it('counts a restricted member only while they are still in the chat', function (?bool $isMember, bool $expected) {
        // `restricted` covers both a muted member and somebody restricted *and*
        // removed; `is_member` is the field that tells them apart, and it is absent
        // rather than false when it does not apply.
        membershipStatus('restricted', $isMember === null ? [] : ['is_member' => $isMember]);

        expect($this->gate->handle($this->user))->toBe($expected);
    })->with([
        'still in the chat' => [true, true],
        'restricted and gone' => [false, false],
        'field absent' => [null, false],
    ]);
});

describe('what it records', function () {
    it('stamps the membership so privileged actions can reuse it', function () {
        membershipStatus('member');

        $this->gate->handle($this->user);

        expect($this->user->channel_verified_at)->not->toBeNull()
            ->and($this->user->hasVerifiedChannel())->toBeTrue()
            // Persisted, not just held in memory: the next update is a different
            // process and reads the row.
            ->and($this->user->fresh()?->channel_verified_at)->not->toBeNull();
    });

    it('clears the stamp when the answer is no, so a cached yes cannot outlive it', function () {
        $this->user->forceFill(['channel_verified_at' => now()->subMinute()])->save();

        membershipStatus('left');

        expect($this->gate->handle($this->user))->toBeFalse()
            ->and($this->user->fresh()?->channel_verified_at)->toBeNull();
    });

    it('asks about the configured channel and the user’s own Telegram id', function () {
        membershipStatus('member');

        $this->gate->handle($this->user);

        Http::assertSent(function (Request $request): bool {
            $query = membershipQuestion($request);

            expect($query['chat_id'])->toBe('@challenges')
                ->and((int) $query['user_id'])->toBe(777_000_2);

            return true;
        });
    });

    it('follows an admin moving the announcement channel', function () {
        $this->settings->set(SettingKey::RequiredChannel, '@somewhere_else');
        membershipStatus('member');

        $this->gate->handle($this->user);

        Http::assertSent(fn (Request $request): bool => membershipQuestion($request)['chat_id'] === '@somewhere_else');
    });
});

describe('the gate’s refusals', function () {
    it('refuses to run at all without a configured channel', function () {
        $this->settings->set(SettingKey::RequiredChannel, '');

        expect(fn (): bool => $this->gate->handle($this->user))
            ->toThrow(ChannelGateException::class);

        // An unset channel is an unfinished deployment, not a gate somebody turned
        // off — so it must not degrade into "everybody is a member".
        Http::assertNothingSent();
        expect($this->user->fresh()?->channel_verified_at)->toBeNull();
    });

    it('trims a channel that was pasted with whitespace', function () {
        $this->settings->set(SettingKey::RequiredChannel, "  @challenges\n");

        expect($this->gate->channel($this->user))->toBe('@challenges');
    });

    it('refuses a user with no Telegram identity', function () {
        // An email-and-password Fortify admin. There is nobody to look up, and
        // guessing would mean asking about somebody else's id.
        $admin = User::factory()->admin()->create();

        expect(fn (): bool => $this->gate->handle($admin))
            ->toThrow(ChannelGateException::class);

        Http::assertNothingSent();
    });

    it('lets a Telegram error propagate rather than reading it as a no', function () {
        Http::fake([
            '*getChatMember*' => Http::response([
                'ok' => false,
                'error_code' => 400,
                'description' => 'Bad Request: chat not found',
            ], 400),
        ]);

        // Chat not found, or the bot demoted out of the channel. From the webhook
        // this leaves the update unprocessed and retried, which is the point: a
        // verdict we could not obtain is not a verdict of no *or* yes.
        expect(fn (): bool => $this->gate->handle($this->user))
            ->toThrow(TelegramSDKException::class);
    });

    it('leaves an existing stamp alone when Telegram could not be asked', function () {
        $verifiedAt = now()->subMinute();
        $this->user->forceFill(['channel_verified_at' => $verifiedAt])->save();

        Http::fake(['*getChatMember*' => Http::response(['ok' => false, 'error_code' => 500], 500)]);

        try {
            $this->gate->handle($this->user);
        } catch (TelegramSDKException) {
            // Expected; the assertion is about what did *not* change.
        }

        expect($this->user->fresh()?->channel_verified_at?->timestamp)->toBe($verifiedAt->timestamp);
    });
});

describe('the join link', function () {
    it('links to a public channel', function () {
        expect($this->gate->joinUrl($this->user))->toBe('https://t.me/challenges');
    });

    it('offers no link for a private channel id, because there is none', function () {
        // A `-100…` id is a private chat. Sending `https://t.me/-100…` would be a
        // dead link, so the caller has to say "ask an admin" instead.
        $this->settings->set(SettingKey::RequiredChannel, '-1001234567890');

        expect($this->gate->joinUrl($this->user))->toBeNull();
    });

    it('will not invent a link when nothing is configured', function () {
        $this->settings->set(SettingKey::RequiredChannel, '');

        expect(fn (): ?string => $this->gate->joinUrl($this->user))->toThrow(ChannelGateException::class);
    });
});

describe('ensure(), the cached reading privileged actions use', function () {
    it('reuses a recent yes without spending a Bot API call', function () {
        $this->user->forceFill(['channel_verified_at' => now()->subMinutes(2)])->save();

        expect($this->gate->ensure($this->user))->toBeTrue();

        // The whole reason `ensure()` exists: ~30 Bot API calls a second is a budget
        // reminder fan-out already competes for.
        Http::assertNothingSent();
    });

    it('asks again once the stamp has gone stale', function () {
        $this->settings->set(SettingKey::ChannelVerificationTtlMinutes, 10);
        $this->user->forceFill(['channel_verified_at' => now()->subMinutes(11)])->save();

        membershipStatus('member');

        expect($this->gate->ensure($this->user))->toBeTrue();
        Http::assertSentCount(1);
    });

    it('asks when there is no stamp at all', function () {
        membershipStatus('member');

        expect($this->gate->ensure($this->user))->toBeTrue();
        Http::assertSentCount(1);
    });

    it('asks every time when an admin sets the TTL to zero', function () {
        $this->settings->set(SettingKey::ChannelVerificationTtlMinutes, 0);
        $this->user->forceFill(['channel_verified_at' => now()])->save();

        membershipStatus('member');

        // Zero turns the cache off, making this identical to `handle()` — the escape
        // hatch for an admin who wants the gate strict.
        expect($this->gate->ensure($this->user))->toBeTrue();
        Http::assertSentCount(1);
    });

    it('withdraws access when the re-check comes back no', function () {
        $this->settings->set(SettingKey::ChannelVerificationTtlMinutes, 5);
        $this->user->forceFill(['channel_verified_at' => now()->subMinutes(6)])->save();

        membershipStatus('left');

        expect($this->gate->ensure($this->user))->toBeFalse()
            ->and($this->user->fresh()?->channel_verified_at)->toBeNull();
    });

    it('trusts a stamp that is still fresh even if they have since left', function () {
        $this->settings->set(SettingKey::ChannelVerificationTtlMinutes, 10);
        $this->user->forceFill(['channel_verified_at' => now()->subMinute()])->save();

        // Recording the cost of the cache rather than pretending it away: somebody
        // who leaves keeps access for up to the TTL. That is the trade the setting
        // exists to let an admin retune.
        expect($this->gate->ensure($this->user))->toBeTrue();
        Http::assertNothingSent();
    });
});
