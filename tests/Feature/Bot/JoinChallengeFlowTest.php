<?php

use App\Actions\Challenges\MaterialiseChallengePeriods;
use App\Enums\ChallengeStatus;
use App\Enums\EntitlementType;
use App\Enums\ParticipantStatus;
use App\Enums\SettingKey;
use App\Jobs\Telegram\ProcessTelegramUpdate;
use App\Models\Challenge;
use App\Models\ChallengeParticipant;
use App\Models\Entitlement;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\Settings;
use App\Services\Telegram\BotCallback;
use App\Services\Telegram\Callbacks\JoinCallback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
 * The join conversation end to end: a deep link arrives through `/start`, a
 * preview goes out, a tap comes back through the callback router, and what comes
 * out the far side is a `challenge_participants` row and one join-slot spent.
 *
 * Two entry points exercise this — the announcement channel's button and an
 * invite-only link — and both are `?start=j_<token>`, so the file tests the tap
 * directly: `CallbackQueryHandlerTest` already owns how a tap reaches a handler,
 * and this file owns what the join handler does once it has one.
 */

beforeEach(function () {
    Http::preventStrayRequests();

    config([
        'services.telegram.bot_token' => '123456:TEST-TOKEN',
        'services.telegram.bot_username' => 'ChallengesBot',
    ]);

    $this->settings = app(Settings::class);
    $this->settings->set(SettingKey::RequiredChannel, '@challenges');
});

/**
 * Telegram's answers for a member: membership confirmed, taps acknowledged,
 * messages accepted. One `fake()` call so pattern order cannot matter.
 */
function telegramServesAMember(): void
{
    Http::fake([
        '*getChatMember*' => Http::response(['ok' => true, 'result' => ['status' => 'member']]),
        '*answerCallbackQuery*' => Http::response(['ok' => true, 'result' => true]),
        '*sendMessage*' => Http::response(['ok' => true, 'result' => ['message_id' => 11]]),
    ]);
}

/**
 * Telegram's answers for an outsider: the gate will block.
 */
function telegramServesAnOutsider(): void
{
    Http::fake([
        '*getChatMember*' => Http::response(['ok' => true, 'result' => ['status' => 'left']]),
        '*answerCallbackQuery*' => Http::response(['ok' => true, 'result' => true]),
        '*sendMessage*' => Http::response(['ok' => true, 'result' => ['message_id' => 11]]),
    ]);
}

/**
 * A challenge whose timeline exists, pinned to a known token.
 */
function joinableChallenge(string $token = 'abcdefghjkmn'): Challenge
{
    $challenge = Challenge::factory()->active()
        ->create(['join_token' => $token, 'title' => 'Read every day', 'total_periods' => 30]);

    app(MaterialiseChallengePeriods::class)->handle($challenge);

    return $challenge;
}

/**
 * Give a user join-slots to spend, without buying them.
 */
function givenJoinSlots(User $user, int $count = 1): void
{
    Entitlement::factory()->count($count)->joinSlot()->create(['user_id' => $user->getKey()]);
}

/**
 * `/start` with a join payload, through the whole inbound path.
 */
function arrivesViaJoinLink(string $token): void
{
    $update = TelegramUpdate::factory()
        ->messageFrom(['id' => 777_300_1, 'first_name' => 'Sara'], '/start j_'.$token)
        ->create();

    dispatch_sync(new ProcessTelegramUpdate($update));
}

/**
 * A tap on a join button, through the whole inbound path.
 *
 * @param  array<string, mixed>  $from  merged over Telegram's `from` object
 */
function tapsJoin(string $token, array $from = []): void
{
    $update = TelegramUpdate::factory()
        ->callbackQueryFrom(array_replace(['id' => 777_300_1, 'first_name' => 'Sara'], $from), BotCallback::encode(JoinCallback::ACTION, $token))
        ->create();

    dispatch_sync(new ProcessTelegramUpdate($update));
}

/**
 * The user `/start` and the taps arrive from.
 */
function theJoiner(): User
{
    return User::query()->where('platform_user_id', 777_300_1)->sole();
}

describe('arriving through a join link', function () {
    it('shows the challenge with a join button instead of the welcome', function () {
        telegramServesAMember();
        $challenge = joinableChallenge();

        arrivesViaJoinLink($challenge->join_token);

        expect(soleBotMessage()['text'])
            ->toContain(botCopy('bot.join.preview_headline', ['title' => 'Read every day']))
            ->toContain(botCopy('bot.join.preview_details', [
                'period' => botCopy($challenge->period_type->translationKey()),
                'periods' => 30,
                'proof' => botCopy($challenge->proof_type->translationKey()),
            ]))
            ->toContain(botCopy('bot.join.preview_freezes', ['freezes' => 1]))
            ->not->toContain(botCopy('bot.start.welcome_back', ['name' => 'Sara']));
    });

    it('puts the token on the button, not a challenge id', function () {
        telegramServesAMember();
        $challenge = joinableChallenge();

        arrivesViaJoinLink($challenge->join_token);

        // The button names an intent and carries the token that resolves it; the
        // actor is re-resolved from `callback_query.from` when it is tapped.
        expect(botKeyboard())->toBe([[[
            'text' => botCopy('bot.join.join_button'),
            'callback_data' => 'jn:'.$challenge->join_token,
        ]]]);
    });

    it('spends nothing on arrival', function () {
        telegramServesAMember();
        $challenge = joinableChallenge();

        arrivesViaJoinLink($challenge->join_token);

        expect(ChallengeParticipant::query()->count())->toBe(0)
            ->and(Entitlement::query()->whereNotNull('consumed_at')->count())->toBe(0);
    });

    it('says so when the link names no challenge', function () {
        telegramServesAMember();

        arrivesViaJoinLink('nothere123');

        expect(soleBotMessage()['text'])->toBe(botCopy('bot.join.not_found'));
    });

    it('does not treat a join payload as an invite code', function () {
        telegramServesAMember();

        arrivesViaJoinLink('nothere123');

        // A dead join link must be answered as a dead join link. Falling through
        // to invite attribution would say "that invite link is no longer valid" —
        // a message about the wrong thing entirely.
        expect(soleBotMessage()['text'])->not->toContain(botCopy('bot.invite.refused.not_found'))
            ->and(theJoiner()->referred_by_user_id)->toBeNull();
    });

    it('registers and provisions the arrival exactly as an ordinary /start', function () {
        telegramServesAMember();

        arrivesViaJoinLink(joinableChallenge()->join_token);

        $user = theJoiner();

        // The free baseline is granted before the preview is considered, so a
        // brand-new arrival can go straight from link to join button to joined.
        expect($user)->not->toBeNull()
            ->and($user->hasVerifiedChannel())->toBeTrue()
            ->and(Entitlement::query()->available(EntitlementType::JoinSlot)->where('user_id', $user->getKey())->count())->toBeGreaterThanOrEqual(1);
    });

    it('blocks at the gate like anything else', function () {
        telegramServesAnOutsider();
        joinableChallenge();

        arrivesViaJoinLink('abcdefghjkmn');

        expect(soleBotMessage()['text'])->toContain(botCopy('bot.gate.blocked', ['channel' => '@challenges']));
    });
});

describe('tapping join', function () {
    it('joins them and says so', function () {
        telegramServesAMember();
        $challenge = joinableChallenge();
        arrivesViaJoinLink($challenge->join_token);
        givenJoinSlots(theJoiner());

        tapsJoin($challenge->join_token);

        $participant = ChallengeParticipant::query()->where('challenge_id', $challenge->getKey())->sole();

        expect($participant->user_id)->toBe(theJoiner()->getKey())
            ->and($participant->status)->toBe(ParticipantStatus::Active)
            ->and(lastBotReply()['text'])->toBe(botCopy('bot.join.joined', ['title' => 'Read every day']));
    });

    it('spends exactly one join-slot', function () {
        telegramServesAMember();
        $challenge = joinableChallenge();
        arrivesViaJoinLink($challenge->join_token);
        givenJoinSlots(theJoiner(), 2);

        tapsJoin($challenge->join_token);

        expect(Entitlement::query()->whereNotNull('consumed_at')->count())->toBe(1);
    });

    it('joins the tapper, never whoever built the button', function () {
        telegramServesAMember();
        $challenge = joinableChallenge();

        // No /start first: the tap is this person's first-ever interaction, the
        // way a button forwarded out of the channel reaches a stranger.
        $stranger = User::factory()->telegram(777_400_9)->create();
        givenJoinSlots($stranger);

        tapsJoin($challenge->join_token, ['id' => 777_400_9]);

        expect(User::query()->where('platform_user_id', 777_300_1)->exists())->toBeFalse()
            ->and(ChallengeParticipant::query()->where('user_id', $stranger->getKey())->exists())->toBeTrue();
    });

    it('told the price when there was no slot to spend', function () {
        telegramServesAMember();
        $challenge = joinableChallenge();
        $this->settings->set(SettingKey::JoinSlotCoinPrice, 25);

        // Arrived, but not through `/start`, so no free baseline was granted and
        // nothing is on the card.
        $user = User::factory()->telegram(777_300_1)->create(['channel_verified_at' => null]);

        tapsJoin($challenge->join_token);

        expect(lastBotReply()['text'])
            ->toContain(botCopy('bot.join.no_slot'))
            ->toContain(botCopy('bot.join.slot_price', ['coins' => 25]))
            ->and(ChallengeParticipant::query()->where('user_id', $user->getKey())->count())->toBe(0);
    });

    it('charges nothing when the gate has lapsed', function () {
        // A sequence rather than a second `fake()`: `Http::fake()` appends and the
        // first matching pattern wins, so an outsider stub installed after the
        // member one would be shadowed and the lapse would be invisible.
        Http::fake([
            '*getChatMember*' => Http::sequence([
                Http::response(['ok' => true, 'result' => ['status' => 'member']]),
                Http::response(['ok' => true, 'result' => ['status' => 'left']]),
            ]),
            '*answerCallbackQuery*' => Http::response(['ok' => true, 'result' => true]),
            '*sendMessage*' => Http::response(['ok' => true, 'result' => ['message_id' => 11]]),
        ]);

        $challenge = joinableChallenge();
        arrivesViaJoinLink($challenge->join_token);
        givenJoinSlots(theJoiner());

        // Membership verified at /start, gone by the tap. The cached "yes" is
        // only borrowed for `ensure()`'s TTL, so an hour's travel makes the tap
        // re-ask Telegram rather than trust the answer the link arrival got.
        $this->travel(1)->hours();

        tapsJoin($challenge->join_token);

        expect(lastBotReply()['text'])->toContain(botCopy('bot.gate.blocked', ['channel' => '@challenges']))
            ->and(ChallengeParticipant::query()->count())->toBe(0)
            ->and(Entitlement::query()->whereNotNull('consumed_at')->count())->toBe(0);
    });
});

describe('tapping join twice', function () {
    it('spends one slot and says they are already in', function () {
        telegramServesAMember();
        $challenge = joinableChallenge();
        arrivesViaJoinLink($challenge->join_token);
        givenJoinSlots(theJoiner(), 2);

        tapsJoin($challenge->join_token);
        tapsJoin($challenge->join_token);

        expect(ChallengeParticipant::query()->count())->toBe(1)
            ->and(Entitlement::query()->whereNotNull('consumed_at')->count())->toBe(1)
            ->and(lastBotReply()['text'])->toBe(botCopy('bot.join.already_in', ['title' => 'Read every day']));
    });
});

describe('what the tap refuses', function () {
    it('says a closed challenge is closed', function () {
        telegramServesAMember();
        $challenge = joinableChallenge();
        arrivesViaJoinLink($challenge->join_token);
        givenJoinSlots(theJoiner());

        $challenge->forceFill(['status' => ChallengeStatus::Completed])->save();

        tapsJoin($challenge->join_token);

        expect(lastBotReply()['text'])
            ->toBe(botCopy('bot.join.refused.challenge_closed', ['title' => 'Read every day']))
            ->and(ChallengeParticipant::query()->count())->toBe(0);
    });

    it('says a finished participation is not theirs to reopen', function () {
        telegramServesAMember();
        $challenge = joinableChallenge();
        arrivesViaJoinLink($challenge->join_token);
        givenJoinSlots(theJoiner(), 2);

        ChallengeParticipant::factory()->for($challenge)->for(theJoiner())->left()->create();

        tapsJoin($challenge->join_token);

        expect(lastBotReply()['text'])
            ->toBe(botCopy('bot.join.refused.participation_ended'))
            ->and(Entitlement::query()->whereNotNull('consumed_at')->count())->toBe(0);
    });

    it('answers a token that names nothing rather than failing silently', function () {
        telegramServesAMember();

        tapsJoin('nothere123');

        expect(lastBotReply()['text'])->toBe(botCopy('bot.join.not_found'));
    });

    it('answers a button with no token on it as a stale button', function () {
        telegramServesAMember();

        $update = TelegramUpdate::factory()
            ->callbackQueryFrom(['id' => 777_300_1, 'first_name' => 'Sara'], 'jn')
            ->create();

        dispatch_sync(new ProcessTelegramUpdate($update));

        expect(lastBotReply()['text'])->toBe(botCopy('bot.fallback.stale_button'));
    });
});

describe('a preview that already knows the answer', function () {
    it('tells somebody already in that they are in', function () {
        telegramServesAMember();
        $challenge = joinableChallenge();
        givenJoinSlots(User::factory()->telegram(777_300_1)->create());
        ChallengeParticipant::factory()->for($challenge)->for(theJoiner())->create();

        arrivesViaJoinLink($challenge->join_token);

        expect(soleBotMessage()['text'])->toBe(botCopy('bot.join.already_in', ['title' => 'Read every day']))
            ->and(botKeyboard())->toBe([]);
    });

    it('tells somebody arriving at a closed challenge that it is closed', function () {
        telegramServesAMember();
        $challenge = joinableChallenge();
        $challenge->forceFill(['status' => ChallengeStatus::Completed])->save();

        arrivesViaJoinLink($challenge->join_token);

        expect(soleBotMessage()['text'])
            ->toBe(botCopy('bot.join.refused.challenge_closed', ['title' => 'Read every day']));
    });
});
