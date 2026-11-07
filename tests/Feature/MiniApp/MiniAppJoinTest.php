<?php

use App\Actions\Entitlements\ConsumeEntitlement;
use App\Enums\ChallengeStatus;
use App\Enums\CoinTransactionReason;
use App\Enums\EntitlementType;
use App\Enums\SettingKey;
use App\Models\Challenge;
use App\Models\ChallengeParticipant;
use App\Models\ChallengePeriod;
use App\Models\Entitlement;
use App\Models\User;
use App\Services\CoinLedger;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
 * The Mini App's join button.
 *
 * The endpoint carries no join rule of its own — it calls the same
 * `JoinChallenge` action the bot's callback does — so what is under test here
 * is the wiring: auth, the 404 uniformity, the re-verified channel gate, and
 * the refusal mapping. The economy rules themselves belong to
 * `tests/Feature/Domain/JoinChallengeTest.php`.
 *
 * Two of these are worth more than the rest because they are the properties a
 * later "fix" at the controller layer would quietly break: **a double-posted
 * join spends one slot**, and **the price quoted on a refusal is read at the
 * tap**, not from anything the caller supplied earlier.
 */

/**
 * A user with a working Mini App bearer token. Channel-verified by default so
 * the gate passes without a Telegram round-trip; the gate tests unset it.
 *
 * Named apart from `MiniAppCheckInTest`'s `aMiniAppActor` on purpose: these are
 * global functions, and two files declaring the same one is a fatal redeclare
 * rather than a test failure.
 */
function aMiniAppJoiner(int $telegramId = 777_003_0, bool $channelVerified = true): User
{
    $user = User::factory()
        ->telegram($telegramId)
        ->when($channelVerified, fn ($factory) => $factory->channelVerified())
        ->preferring('en')
        ->create();

    test()->withToken($user->createToken('miniapp', ['miniapp'])->plainTextToken);

    return $user;
}

/**
 * Hand the user unconsumed join-slots without buying them. `grantJoinSlots`,
 * not `givenJoinSlots` — that name is taken by the bot's join-flow test.
 */
function grantJoinSlots(User $user, int $count = 1): void
{
    Entitlement::factory()->count($count)->joinSlot()->create(['user_id' => $user->getKey()]);
}

/**
 * An active challenge the viewer **owns**, with period 1 open right now.
 *
 * Owned rather than merely visible because ownership is the only way to reach
 * this endpoint without already being a participant — the Mini App has no
 * public listing, so a stranger's challenge is a 404 by design. That also makes
 * every join below the reported case: the creator joining the thing they just
 * made.
 */
function anOpenChallengeToJoin(User $viewer, int $totalPeriods = 3, int $defaultFreezes = 2): Challenge
{
    $challenge = Challenge::factory()->active()->create([
        'creator_id' => $viewer->getKey(),
        'total_periods' => $totalPeriods,
        'default_freezes' => $defaultFreezes,
    ]);

    ChallengePeriod::factory()->for($challenge)->create([
        'index' => 1,
        'starts_at' => now()->startOfDay(),
        'ends_at' => now()->addDay()->startOfDay(),
    ]);

    return $challenge;
}

/**
 * An active challenge the viewer owns whose only period closed yesterday —
 * `acceptsJoins()` passes, but there is no period left to join at.
 */
function aSpentChallenge(User $viewer): Challenge
{
    $challenge = Challenge::factory()->active()->create([
        'creator_id' => $viewer->getKey(),
        'total_periods' => 3,
    ]);

    ChallengePeriod::factory()->for($challenge)->create([
        'index' => 0,
        'starts_at' => now()->subDays(2)->startOfDay(),
        'ends_at' => now()->subDay()->startOfDay(),
    ]);

    return $challenge;
}

function joinPath(Challenge $challenge): string
{
    return '/api/v1/miniapp/challenges/'.$challenge->getKey().'/join';
}

/**
 * Telegram's `getChatMember` answer for the required channel.
 */
function chatMemberSays(string $status, int $telegramId = 777_003_0): void
{
    Http::fake([
        '*getChatMember*' => Http::response([
            'ok' => true,
            'result' => [
                'status' => $status,
                'user' => ['id' => $telegramId, 'is_bot' => false, 'first_name' => 'Sara'],
            ],
        ]),
    ]);
}

beforeEach(function () {
    config(['services.telegram.bot_token' => '123456:TEST-TOKEN']);
    app(Settings::class)->set(SettingKey::RequiredChannel, '@challenges');
});

it('refuses a join with no token', function () {
    test()->postJson('/api/v1/miniapp/challenges/1/join')->assertStatus(401);
});

describe('joining', function () {
    it('lets the creator join their own challenge, spending exactly one slot', function () {
        $user = aMiniAppJoiner();
        grantJoinSlots($user, 1);
        $challenge = anOpenChallengeToJoin($user);

        test()->postJson(joinPath($challenge))
            ->assertStatus(201)
            ->assertJsonPath('data.id', $challenge->getKey())
            // Both flags at once, which is the reported bug stated as an
            // assertion: running a challenge and being in it are different
            // questions, and after this tap the answer to both is yes.
            ->assertJsonPath('data.is_creator', true)
            ->assertJsonPath('data.has_joined', true)
            ->assertJsonPath('data.me.status.value', 'active')
            ->assertJsonPath('data.me.joined_period_index', 1);

        $spent = Entitlement::query()->whereNotNull('consumed_at')->get();

        expect(ChallengeParticipant::query()->count())->toBe(1)
            ->and($spent)->toHaveCount(1)
            ->and($spent->first()?->type)->toBe(EntitlementType::JoinSlot)
            ->and($spent->first()?->challenge_id)->toBe($challenge->getKey())
            ->and(app(ConsumeEntitlement::class)->available($user, EntitlementType::JoinSlot))->toBe(0);
    });

    it('spends one slot when the same join is posted twice', function () {
        $user = aMiniAppJoiner();
        grantJoinSlots($user, 2);
        $challenge = anOpenChallengeToJoin($user);

        $first = test()->postJson(joinPath($challenge))->assertStatus(201);
        $second = test()->postJson(joinPath($challenge))->assertStatus(200);

        // The same participation, reported twice. A double-tapped button and a
        // Telegram retry both land here, and neither may cost a second slot —
        // which is what the slot still in hand proves, not the row count.
        $second->assertJsonPath('data.id', $first->json('data.id'))
            ->assertJsonPath('data.me.joined_period_index', $first->json('data.me.joined_period_index'));

        expect(ChallengeParticipant::query()->count())->toBe(1)
            ->and(Entitlement::query()->whereNotNull('consumed_at')->count())->toBe(1)
            ->and(app(ConsumeEntitlement::class)->available($user, EntitlementType::JoinSlot))->toBe(1);
    });

    it('answers with the joined view rather than a patch to merge', function () {
        $user = aMiniAppJoiner();
        grantJoinSlots($user);
        $challenge = anOpenChallengeToJoin($user, totalPeriods: 3, defaultFreezes: 2);

        test()->postJson(joinPath($challenge))
            ->assertStatus(201)
            // Freezes are snapshotted from the challenge at join, and the
            // period the participant landed on is owed to them with nothing
            // recorded against it yet — which is what turns the SPA's check-in
            // affordance on. History starts empty because they joined now.
            ->assertJsonPath('data.me.freezes.total', 2)
            ->assertJsonPath('data.me.freezes.used', 0)
            ->assertJsonPath('data.me.freezes.remaining', 2)
            ->assertJsonPath('data.me.current_streak', 0)
            ->assertJsonPath('data.current_period.index', 1)
            ->assertJsonPath('data.current_period.owes_check_in', true)
            ->assertJsonPath('data.current_period.check_in', null)
            ->assertJsonPath('data.history', [])
            ->assertJsonPath('data.scoring', null);
    });
});

describe('when the user has no join slot', function () {
    it('refuses with the price and the real balance, and nothing else', function () {
        $user = aMiniAppJoiner();
        $challenge = anOpenChallengeToJoin($user);

        app(Settings::class)->set(SettingKey::JoinSlotCoinPrice, 25);
        app(CoinLedger::class)->credit($user, 7, CoinTransactionReason::AdminCredit, "float:{$user->getKey()}");

        $response = test()->postJson(joinPath($challenge))->assertStatus(402);

        // The exact body, not just its fields: this refusal is a quote, and a
        // field that creeps in here is a field the SPA starts depending on.
        expect($response->json())->toBe([
            'reason' => 'no_join_slot',
            'price' => 25,
            'balance' => 7,
        ]);

        expect(ChallengeParticipant::query()->count())->toBe(0);
    });

    it('quotes the price configured at the tap, not a number in the code', function () {
        $user = aMiniAppJoiner();
        $challenge = anOpenChallengeToJoin($user);

        app(CoinLedger::class)->credit($user, 7, CoinTransactionReason::AdminCredit, "float:{$user->getKey()}");

        app(Settings::class)->set(SettingKey::JoinSlotCoinPrice, 25);
        $before = test()->postJson(joinPath($challenge))->assertStatus(402);

        // The admin raises the price between the two taps. A quoted figure that
        // does not move is a hardcoded one.
        app(Settings::class)->set(SettingKey::JoinSlotCoinPrice, 40);
        $after = test()->postJson(joinPath($challenge))->assertStatus(402);

        expect($before->json('price'))->toBe(25)
            ->and($after->json('price'))->toBe(40)
            ->and($after->json('balance'))->toBe($before->json('balance'));
    });
});

describe('the channel gate', function () {
    it('blocks the join behind the gate, with the join link, and writes nothing', function () {
        $user = aMiniAppJoiner(channelVerified: false);
        grantJoinSlots($user, 1);
        $challenge = anOpenChallengeToJoin($user);

        chatMemberSays('left');

        test()->postJson(joinPath($challenge))
            ->assertStatus(403)
            ->assertJsonPath('reason', 'channel_gate')
            ->assertJsonPath('join_url', 'https://t.me/challenges');

        // A refusal that still spent a slot is a slot stolen.
        expect(ChallengeParticipant::query()->count())->toBe(0)
            ->and(Entitlement::query()->whereNotNull('consumed_at')->count())->toBe(0);
    });

    it('re-verifies a stale gate against Telegram and lets a member through', function () {
        // Verified an hour ago — long past the freshness window — so this only
        // passes if the gate actually asked Telegram again rather than trusting
        // a stamp the bearer token was minted alongside.
        $user = aMiniAppJoiner();
        $user->forceFill(['channel_verified_at' => now()->subHour()])->save();
        grantJoinSlots($user, 1);
        $challenge = anOpenChallengeToJoin($user);

        chatMemberSays('member');

        test()->postJson(joinPath($challenge))
            ->assertStatus(201)
            ->assertJsonPath('data.has_joined', true);

        expect($user->refresh()->channel_verified_at)->toBeGreaterThan(now()->subMinute());
    });

    it('does not treat a valid token as a pass', function () {
        $user = aMiniAppJoiner(channelVerified: false);
        grantJoinSlots($user, 1);
        $challenge = anOpenChallengeToJoin($user);

        chatMemberSays('left');

        // The token is good and the challenge is theirs; the gate is the only
        // thing standing in the way, and it is asked at the tap.
        test()->postJson(joinPath($challenge))->assertStatus(403);

        expect(ChallengeParticipant::query()->count())->toBe(0);
    });
});

describe('when the action refuses', function () {
    it('maps a closed challenge to its own reason rather than a 500', function () {
        $user = aMiniAppJoiner();
        grantJoinSlots($user, 1);
        $challenge = anOpenChallengeToJoin($user);

        $challenge->forceFill(['status' => ChallengeStatus::Cancelled])->save();

        $response = test()->postJson(joinPath($challenge))->assertStatus(422);

        expect($response->json())->toBe(['reason' => 'challenge_closed'])
            ->and(ChallengeParticipant::query()->count())->toBe(0);
    });

    it('maps an exhausted timeline to its own reason', function () {
        $user = aMiniAppJoiner();
        grantJoinSlots($user, 1);
        $challenge = aSpentChallenge($user);

        test()->postJson(joinPath($challenge))
            ->assertStatus(422)
            ->assertJsonPath('reason', 'timeline_exhausted');

        expect(ChallengeParticipant::query()->count())->toBe(0);
    });

    it('maps an ended participation to its own reason, spending nothing', function () {
        $user = aMiniAppJoiner();
        grantJoinSlots($user, 1);
        $challenge = anOpenChallengeToJoin(User::factory()->telegram()->create());

        ChallengeParticipant::factory()->for($challenge)->for($user)->left()->create();

        // Having left is what makes the challenge visible here; rejoining is
        // not the participant's decision to make, and the slot stays in hand.
        test()->postJson(joinPath($challenge))
            ->assertStatus(422)
            ->assertJsonPath('reason', 'participation_ended');

        expect(Entitlement::query()->whereNotNull('consumed_at')->count())->toBe(0);
    });
});

describe('a challenge that is not the token user’s', function () {
    it('reads as one that does not exist, and joins nothing', function () {
        $user = aMiniAppJoiner();
        grantJoinSlots($user, 1);
        $stranger = anOpenChallengeToJoin(User::factory()->telegram()->create());

        test()->postJson(joinPath($stranger))->assertStatus(404);
        test()->postJson('/api/v1/miniapp/challenges/99999/join')->assertStatus(404);
        test()->postJson('/api/v1/miniapp/challenges/not-an-id/join')->assertStatus(404);

        expect(ChallengeParticipant::query()->count())->toBe(0)
            ->and(Entitlement::query()->whereNotNull('consumed_at')->count())->toBe(0);
    });
});
