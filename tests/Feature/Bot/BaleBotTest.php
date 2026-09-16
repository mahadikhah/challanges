<?php

use App\Actions\Challenges\MaterialiseChallengePeriods;
use App\Actions\Invites\IssueInviteCode;
use App\Enums\ChallengeStatus;
use App\Enums\ConversationState;
use App\Enums\EntitlementType;
use App\Enums\MessagingPlatform;
use App\Enums\ReminderKind;
use App\Enums\SettingKey;
use App\Jobs\Telegram\ProcessTelegramUpdate;
use App\Jobs\Telegram\SendReminder;
use App\Models\BotConversation;
use App\Models\Challenge;
use App\Models\ChallengeParticipant;
use App\Models\Entitlement;
use App\Models\ReminderDispatch;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\CoinLedger;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
 * The Bale mirror of the Telegram bot tests. Phase 11 Task 2's rule was "no
 * Bale-specific branches inside any Action" — which also means the *behaviour*
 * must be identical, so what this file asserts is not new behaviour but the
 * same behaviour arriving through Bale's webhook, resolving Bale's platform
 * for every send, and gating on Bale's own channel setting.
 *
 * Every request is faked. Nothing here may reach tapi.bale.ai or
 * api.telegram.org for real, on any path.
 */

beforeEach(function () {
    Http::preventStrayRequests();

    config([
        'services.telegram.bot_token' => '123456:TEST-TOKEN',
        'services.telegram.bot_username' => 'ChallengesBot',
        'services.telegram.required_channel' => '@tgchannel',
        'services.bale.bot_token' => '620001:BALE-TEST-TOKEN',
        'services.bale.bot_username' => 'ChallengesBaleBot',
        'services.bale.webhook_secret' => 'bale-path-secret',
        'services.bale.required_channel' => '@balechannel',
    ]);

    $this->settings = app(Settings::class);
    $this->settings->set(SettingKey::RequiredChannelBale, '@balechannel');

    $this->issue = app(IssueInviteCode::class);
    $this->ledger = app(CoinLedger::class);
});

/**
 * Bale's answers for an arrival: a membership status, and an accepted send.
 * The patterns are method-name wildcards so they match either host — what the
 * test then asserts is *which* host was asked.
 */
function baleAnswers(string $status): void
{
    Http::fake([
        '*getChatMember*' => Http::response(['ok' => true, 'result' => ['status' => $status]]),
        '*sendMessage*' => Http::response(['ok' => true, 'result' => ['message_id' => 31]]),
    ]);
}

/**
 * POST an update the way Bale would: the URL's secret path segment is the
 * whole authenticity mechanism, and there is no header to send.
 *
 * @param  array<string, mixed>  $payload
 */
function baleDeliver(array $payload, string $token = 'bale-path-secret')
{
    return test()->postJson("/bale/webhook/{$token}", $payload);
}

/**
 * A minimal Bale `message` update — Telegram-shaped, because Bale's API is.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function baleMessageUpdate(int $updateId = 700_001, array $overrides = []): array
{
    return array_replace_recursive([
        'update_id' => $updateId,
        'message' => [
            'message_id' => 12,
            'date' => 1_760_000_000,
            'chat' => ['id' => 888, 'type' => 'private'],
            'from' => ['id' => 888, 'is_bot' => false, 'first_name' => 'Parisa'],
            'text' => '/start',
        ],
    ], $overrides);
}

/**
 * Put one message through the whole inbound path, on Bale.
 *
 * @param  array<string, mixed>  $from  merged over Bale's `from` object
 */
function arrivesAtBale(string $text = '/start', array $from = []): TelegramUpdate
{
    $update = TelegramUpdate::factory()
        ->bale()
        ->messageFrom(array_replace(['id' => 888, 'first_name' => 'Parisa'], $from), $text)
        ->create();

    dispatch_sync(new ProcessTelegramUpdate($update));

    return $update;
}

describe('the Bale webhook', function () {
    it('records the update on the Bale platform, queues the work and answers 200', function () {
        Queue::fake();

        baleDeliver(baleMessageUpdate())->assertOk()->assertExactJson(['ok' => true]);

        $update = TelegramUpdate::query()->sole();

        expect($update->platform)->toBe(MessagingPlatform::Bale)
            ->and($update->isProcessed())->toBeFalse();

        Queue::assertPushed(
            ProcessTelegramUpdate::class,
            fn (ProcessTelegramUpdate $job): bool => $job->update->is($update),
        );
    });

    it('answers 404 and records nothing for anything but the exact path secret', function (string $token) {
        Queue::fake();

        baleDeliver(baleMessageUpdate(), $token)->assertNotFound();

        expect(TelegramUpdate::query()->count())->toBe(0);
        Queue::assertNothingPushed();
    })->with([
        'wrong secret' => ['not-the-secret'],
        'no secret at all' => [''],
    ]);

    it('refuses everything when no secret is configured, rather than waving everything through', function () {
        config(['services.bale.webhook_secret' => '']);
        Queue::fake();

        // With no secret to compare against, the only safe answer is to
        // refuse: the path segment is the *whole* gate on Bale.
        baleDeliver(baleMessageUpdate())->assertNotFound();

        expect(TelegramUpdate::query()->count())->toBe(0);
        Queue::assertNothingPushed();
    });

    it('treats the same update_id on two platforms as two updates, not a replay', function () {
        // Chat and update ids are platform-issued and collide numerically; the
        // uniqueness is the pair, and this is the collision itself.
        Queue::fake();

        config([
            'services.telegram.webhook_secret' => 'path-secret',
            'services.telegram.webhook_header_secret' => 'header-secret',
        ]);
        Http::fake(['*' => Http::response(['ok' => true, 'result' => []])]);

        baleDeliver(baleMessageUpdate(700_500))->assertOk();

        test()->postJson('/telegram/webhook/path-secret', array_replace_recursive(
            baleMessageUpdate(700_500),
            ['message' => ['chat' => ['id' => 999], 'from' => ['id' => 999, 'first_name' => 'Sara']]],
        ), ['X-Telegram-Bot-Api-Secret-Token' => 'header-secret'])->assertOk();

        $rows = TelegramUpdate::query()->get();

        expect($rows)->toHaveCount(2)
            ->and($rows->pluck('platform')->map(fn (MessagingPlatform $p) => $p->value)->unique()->sort()->values()->all())
            ->toBe([MessagingPlatform::Bale->value, MessagingPlatform::Telegram->value]);
    });
});

describe('a first arrival on Bale', function () {
    it('registers the user on Bale and gates them on Bale\'s own channel', function () {
        baleAnswers('member');

        arrivesAtBale('/start');

        $user = User::query()->where('platform_user_id', 888)->sole();

        // The membership question went to Bale about Bale's channel — never to
        // Telegram, and not about Telegram's channel.
        $membershipUrls = collect(Http::recorded())
            ->map(fn (array $call): string => $call[0]->url())
            ->filter(fn (string $url): bool => str_contains($url, 'getChatMember'));

        expect($user->platform)->toBe(MessagingPlatform::Bale)
            ->and($user->hasVerifiedChannel())->toBeTrue()
            ->and($membershipUrls)->toHaveCount(1)
            ->and($membershipUrls->first())->toStartWith('https://tapi.bale.ai/')
            ->and($membershipUrls->first())->toContain(urlencode('@balechannel'));
    });

    it('blocks a non-member with a ble.ir join button, not a t.me one', function () {
        baleAnswers('left');

        arrivesAtBale('/start');

        $message = soleBotMessage();

        expect($message['text'])->toContain(botCopy('bot.gate.blocked', ['channel' => '@balechannel']))
            ->and(stripslashes($message['reply_markup'] ?? ''))->toContain('https://ble.ir/balechannel');
    });

    it('pays a Telegram inviter for a brand-new Bale arrival', function () {
        $inviter = User::factory()->telegram(555_000_9)->create();
        $invite = $this->issue->handle($inviter);
        baleAnswers('member');

        arrivesAtBale("/start {$invite->code}");

        $invitee = User::query()->where('platform_user_id', 888)->sole();

        // The reward is for bringing a person to the bot; which messenger they
        // chose afterwards is not the inviter's problem.
        expect($invite->refresh()->wasPaid())->toBeTrue()
            ->and($invitee->referred_by_user_id)->toBe($inviter->getKey())
            ->and($this->ledger->balanceFor($inviter))
            ->toBe($this->settings->integer(SettingKey::InviteCoinReward));
    });
});

describe('the Bale bot surface', function () {
    it('lists the Rial-priced packages on Bale, and only those', function () {
        baleAnswers('member');
        $this->settings->set(SettingKey::StarsPackages, [
            ['stars' => 25, 'coins' => 250, 'rial' => 250_000],
            ['stars' => 50, 'coins' => 500],
        ]);

        arrivesAtBale('/shop');

        $message = soleBotMessage();

        // The second row has no Rial price, so it is not on Bale's shelves —
        // the button indexes still name the shared table's rows. The two slot
        // rows below them are, because a slot costs coins this user may
        // already hold and no rail has anything to do with it.
        expect($message['text'])->toContain(botCopy('bot.shop.package_rial', ['rial' => 250000, 'coins' => 250]))
            ->and($message['text'])->not->toContain(botCopy('bot.shop.package', ['stars' => 50, 'coins' => 500]))
            ->and(keyboardOn($message))->toBe([
                [[
                    'text' => botCopy('bot.shop.button', ['coins' => 250]),
                    'callback_data' => 'sp:0',
                ]],
                [slotButton('en', EntitlementType::CreateSlot)],
                [slotButton('en', EntitlementType::JoinSlot)],
            ]);
    });

    it('still sells slots when no package is priced in Rial', function () {
        baleAnswers('member');
        $this->settings->set(SettingKey::StarsPackages, [['stars' => 25, 'coins' => 250]]);

        arrivesAtBale('/shop');

        $message = soleBotMessage();

        // The whole point of the rework: an empty Rial shelf used to be the
        // entire reply, which left a Bale user holding coins with no way to
        // spend them on the one thing the menu promises.
        expect($message['text'])->toContain(botCopy('bot.shop.no_packages'))
            ->and(keyboardOn($message))->toBe([
                [slotButton('en', EntitlementType::CreateSlot)],
                [slotButton('en', EntitlementType::JoinSlot)],
            ]);
    });

    it('opens the create-challenge wizard on Bale like on Telegram', function () {
        baleAnswers('member');

        // A free create-slot: the wizard refuses politely without one, and
        // that refusal is Telegram's test to cover, not this mirror's.
        Entitlement::factory()->createSlot()->create([
            'user_id' => User::factory()->telegram(888)->bale()->create()->getKey(),
        ]);

        arrivesAtBale('/create');

        $conversation = BotConversation::query()->sole();

        expect($conversation->user->platform)->toBe(MessagingPlatform::Bale)
            ->and($conversation->state)->toBe(ConversationState::AwaitingChallengeTitle)
            // The opening line, then the first question — both on Bale.
            ->and(soleBotMessage()['text'])->toStartWith(botCopy('bot.wizard.opening'));
    });
});

describe('reminders and announcements pick the recipient\'s platform', function () {
    it('sends a reminder through Bale for a Bale participant and Telegram for a Telegram one', function () {
        baleAnswers('member');

        $challenge = Challenge::factory()->create([
            'status' => ChallengeStatus::Active,
            'title' => 'Morning run',
        ]);

        // Materialise rather than hand-build: the reminder job reads the
        // period through the challenge's own timeline.
        app(MaterialiseChallengePeriods::class)->handle($challenge);

        $onBale = ChallengeParticipant::factory()->for(
            User::factory()->telegram(888)->bale()->create(),
        )->for($challenge)->create();

        $onTelegram = ChallengeParticipant::factory()->for(
            User::factory()->telegram(777_999_1)->create(),
        )->for($challenge)->create();

        $period = $challenge->periods()->firstOrFail();

        foreach ([$onBale, $onTelegram] as $participant) {
            ReminderDispatch::query()->create([
                'challenge_participant_id' => $participant->getKey(),
                'challenge_period_id' => $period->getKey(),
                'kind' => ReminderKind::PeriodEnding,
                'scheduled_for' => now(),
            ]);
        }

        ReminderDispatch::query()->get()->each(
            fn (ReminderDispatch $reminder) => dispatch_sync(new SendReminder($reminder->getKey())),
        );

        $hosts = Http::recorded(fn ($request): bool => str_contains($request->url(), 'sendMessage'))
            ->map(fn (array $call): string => (string) parse_url($call[0]->url(), PHP_URL_HOST))
            ->unique()
            ->values();

        // One challenge, two participants, two messengers: each send went out
        // through the platform the recipient actually stands on.
        expect($hosts->sort()->values()->all())->toBe(['api.telegram.org', 'tapi.bale.ai'])
            ->and(ReminderDispatch::query()->get()->every(fn (ReminderDispatch $r) => $r->sent_at !== null))->toBeTrue();
    });
});
