<?php

use App\Enums\CheckInStatus;
use App\Enums\ProofType;
use App\Enums\SettingKey;
use App\Models\Challenge;
use App\Models\ChallengeParticipant;
use App\Models\ChallengePeriod;
use App\Models\CheckIn;
use App\Models\User;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
 * The Mini App's one-tap check-in. The endpoint is deliberately thin: resolve
 * the participant from the token, re-verify the channel gate, hand the rest
 * to the same SubmitCheckIn action the bot calls — so what is under test is
 * the wiring (auth, 404 uniformity, the gate, the refusal mapping), not the
 * check-in rules, which have their own suites.
 */

/**
 * A user with a working Mini App bearer token. Channel-verified by default so
 * the gate passes without a Telegram round-trip; the gate tests unset it.
 */
function aMiniAppActor(int $telegramId = 777_002_0, bool $channelVerified = true): User
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
 * An active, button-proof daily challenge whose period 0 is open now.
 */
function aTappableChallenge(User $actor): array
{
    $challenge = Challenge::factory()->active()->create([
        'creator_id' => User::factory()->telegram()->create()->getKey(),
        'starts_at' => now()->startOfDay(),
        'total_periods' => 3,
    ]);

    $period = ChallengePeriod::factory()->for($challenge)->create([
        'index' => 0,
        'starts_at' => now()->startOfDay(),
        'ends_at' => now()->addDay()->startOfDay(),
    ]);

    $participant = ChallengeParticipant::factory()
        ->for($challenge)
        ->for($actor)
        ->create(['joined_period_index' => 0]);

    return [$challenge, $period, $participant];
}

beforeEach(function () {
    config(['services.telegram.bot_token' => '123456:TEST-TOKEN']);
    app(Settings::class)->set(SettingKey::RequiredChannel, '@challenges');
});

it('refuses the check-in without a token', function () {
    test()->postJson('/api/v1/miniapp/challenges/1/check-in')->assertStatus(401);
});

it('hides a challenge the token’s user cannot check in against', function () {
    $user = aMiniAppActor();

    $someoneElses = Challenge::factory()->active()->create();
    ChallengeParticipant::factory()->for($someoneElses)->create();

    test()->postJson('/api/v1/miniapp/challenges/'.$someoneElses->getKey().'/check-in')->assertStatus(404);
    test()->postJson('/api/v1/miniapp/challenges/99999/check-in')->assertStatus(404);
});

it('checks a button proof in and returns the participant’s new state', function () {
    $user = aMiniAppActor();
    [$challenge, $period, $participant] = aTappableChallenge($user);

    $response = test()->postJson('/api/v1/miniapp/challenges/'.$challenge->getKey().'/check-in');

    $response->assertStatus(201)
        ->assertJsonPath('data.id', $challenge->getKey())
        ->assertJsonPath('data.current_period.check_in.value', 'approved')
        ->assertJsonPath('data.current_period.owes_check_in', false)
        ->assertJsonPath('data.me.current_streak', 1);

    $checkIn = CheckIn::query()->sole();
    expect($checkIn->status)->toBe(CheckInStatus::Approved)
        ->and($checkIn->challenge_period_id)->toBe($period->getKey())
        ->and($participant->refresh()->current_streak)->toBe(1);
});

it('taps a button proof even when the body carries a stray phrase', function () {
    // The dispatch is on the challenge's own `proof_type`, not on what the
    // body happens to contain — a tap challenge is proven by a tap, and the
    // extra field is not a second path into `typePhrase()`.
    $user = aMiniAppActor();
    [$challenge] = aTappableChallenge($user);

    test()->postJson('/api/v1/miniapp/challenges/'.$challenge->getKey().'/check-in', [
        'text' => 'ancient orchard 46',
    ])
        ->assertStatus(201)
        ->assertJsonPath('data.current_period.check_in.value', 'approved');

    $checkIn = CheckIn::query()->sole();
    expect($checkIn->submitted_text)->toBeNull()
        ->and($checkIn->expected_phrase)->toBeNull();
});

/*
 * A media proof exists only in the bot — the task's scope stops at the two
 * non-media proofs, and this surface's `wrong_proof_type` refusal is what a
 * client that tries anyway runs into. The SPA never does; it sends these
 * participants to the bot instead.
 */
it('refuses with the reason when the proof is media', function (ProofType $proof) {
    $user = aMiniAppActor();

    $challenge = Challenge::factory()->active()->create([
        'creator_id' => User::factory()->telegram()->create()->getKey(),
        'proof_type' => $proof,
        'starts_at' => now()->startOfDay(),
    ]);
    ChallengePeriod::factory()->for($challenge)->create([
        'index' => 0,
        'starts_at' => now()->startOfDay(),
        'ends_at' => now()->addDay()->startOfDay(),
    ]);
    ChallengeParticipant::factory()->for($challenge)->for($user)->create();

    test()->postJson('/api/v1/miniapp/challenges/'.$challenge->getKey().'/check-in')
        ->assertStatus(422)
        ->assertJsonPath('reason', 'wrong_proof_type');

    expect(CheckIn::query()->count())->toBe(0);
})->with([
    ProofType::ImageApproval,
    ProofType::VoiceApproval,
]);

it('refuses when the period is already settled', function () {
    $user = aMiniAppActor();
    [$challenge, $period, $participant] = aTappableChallenge($user);

    CheckIn::factory()->on($participant, $period)->approved()->create();

    test()->postJson('/api/v1/miniapp/challenges/'.$challenge->getKey().'/check-in')
        ->assertStatus(422)
        ->assertJsonPath('reason', 'already_settled');
});

it('refuses when no period is open', function () {
    $user = aMiniAppActor();

    $challenge = Challenge::factory()->active()->create([
        'creator_id' => User::factory()->telegram()->create()->getKey(),
        'starts_at' => now()->subDays(2)->startOfDay(),
        'total_periods' => 1,
    ]);
    ChallengePeriod::factory()->for($challenge)->create([
        'index' => 0,
        'starts_at' => now()->subDays(2)->startOfDay(),
        'ends_at' => now()->subDay()->startOfDay(),
        'rolled_over_at' => now()->subDay(),
    ]);
    ChallengeParticipant::factory()->for($challenge)->for($user)->create();

    test()->postJson('/api/v1/miniapp/challenges/'.$challenge->getKey().'/check-in')
        ->assertStatus(422)
        ->assertJsonPath('reason', 'no_open_period');
});

it('blocks the check-in behind the channel gate, with the join link', function () {
    $user = aMiniAppActor(channelVerified: false);
    [$challenge] = aTappableChallenge($user);

    Http::fake([
        '*getChatMember*' => Http::response([
            'ok' => true,
            'result' => [
                'status' => 'left',
                'user' => ['id' => 777_002_0, 'is_bot' => false, 'first_name' => 'Sara'],
            ],
        ]),
    ]);

    test()->postJson('/api/v1/miniapp/challenges/'.$challenge->getKey().'/check-in')
        ->assertStatus(403)
        ->assertJsonPath('reason', 'channel_gate')
        ->assertJsonPath('join_url', 'https://t.me/challenges');

    expect(CheckIn::query()->count())->toBe(0);
});

it('re-verifies a stale gate against Telegram and lets a member through', function () {
    // Verified an hour ago — long enough ago that the freshness window has
    // closed, so this only passes if the gate actually asked Telegram again.
    $user = aMiniAppActor();
    $user->forceFill(['channel_verified_at' => now()->subHour()])->save();
    [$challenge] = aTappableChallenge($user);

    Http::fake([
        '*getChatMember*' => Http::response([
            'ok' => true,
            'result' => [
                'status' => 'member',
                'user' => ['id' => 777_002_0, 'is_bot' => false, 'first_name' => 'Sara'],
            ],
        ]),
    ]);

    test()->postJson('/api/v1/miniapp/challenges/'.$challenge->getKey().'/check-in')
        ->assertStatus(201)
        ->assertJsonPath('data.current_period.check_in.value', 'approved');

    expect($user->refresh()->channel_verified_at)->toBeGreaterThan(now()->subMinute());
});

/*
 * A quantity challenge on the one-tap surface: the number the SPA collects
 * rides the request, and the guard in SubmitCheckIn is the last line — so a
 * request without one is refused with the reason rather than scored as zero.
 */
describe('a quantity challenge', function () {
    /**
     * An active, button-proof quantity challenge (30 pushups, 100 points)
     * whose period 0 is open now, with `$actor` enrolled.
     *
     * @return array{0: Challenge, 1: ChallengeParticipant}
     */
    function aQuantityChallenge(User $actor): array
    {
        $challenge = Challenge::factory()->active()->quantity()->create([
            'creator_id' => User::factory()->telegram()->create()->getKey(),
            'proof_type' => ProofType::Button,
            'starts_at' => now()->startOfDay(),
            'total_periods' => 3,
        ]);

        ChallengePeriod::factory()->for($challenge)->create([
            'index' => 0,
            'starts_at' => now()->startOfDay(),
            'ends_at' => now()->addDay()->startOfDay(),
        ]);

        $participant = ChallengeParticipant::factory()
            ->for($challenge)
            ->for($actor)
            ->create(['joined_period_index' => 0]);

        return [$challenge, $participant];
    }

    it('refuses a tap that reports no number', function () {
        $user = aMiniAppActor();
        [$challenge] = aQuantityChallenge($user);

        test()->postJson('/api/v1/miniapp/challenges/'.$challenge->getKey().'/check-in')
            ->assertStatus(422)
            ->assertJsonPath('reason', 'value_required');

        expect(CheckIn::query()->count())->toBe(0);
    });

    it('refuses a report the wire cannot read as a non-negative number', function () {
        $user = aMiniAppActor();
        [$challenge] = aQuantityChallenge($user);

        test()->postJson('/api/v1/miniapp/challenges/'.$challenge->getKey().'/check-in', [
            'reported_value' => -5,
        ])->assertStatus(422);
    });

    it('settles the reported number and answers with the score it earned', function () {
        $user = aMiniAppActor();
        [$challenge, $participant] = aQuantityChallenge($user);

        $response = test()->postJson('/api/v1/miniapp/challenges/'.$challenge->getKey().'/check-in', [
            'reported_value' => 45,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.scoring.target_value', 30)
            ->assertJsonPath('data.scoring.unit_label', 'pushups')
            ->assertJsonPath('data.scoring.base_points', 100)
            ->assertJsonPath('data.scoring.partial_counts_as_done', false)
            ->assertJsonPath('data.current_period.check_in.reported_value', 45)
            ->assertJsonPath('data.current_period.check_in.score', 150)
            ->assertJsonPath('data.me.total_score', 150);

        $checkIn = CheckIn::query()->sole();

        expect($checkIn->status)->toBe(CheckInStatus::Approved)
            ->and($checkIn->reported_value)->toBe('45.00')
            ->and($checkIn->score)->toBe('150.00')
            ->and($participant->refresh()->total_score)->toBe('150.00');
    });

    it('carries no scoring block on a binary challenge', function () {
        $user = aMiniAppActor();
        [$challenge] = aTappableChallenge($user);

        test()->postJson('/api/v1/miniapp/challenges/'.$challenge->getKey().'/check-in')
            ->assertStatus(201)
            ->assertJsonPath('data.scoring', null)
            ->assertJsonPath('data.me.total_score', 0);
    });
});

/*
 * A typed-proof challenge checks in through the Mini App. The phrase is the
 * participant's own — per participant per period, minted by the same
 * `IssueCheckInPhrase` the bot uses and shown back on the detail surface, so
 * the string read is the string compared. The mechanic must not be weakened by
 * having a second surface: a wrong phrase still refuses and leaves the row
 * unsent.
 */
describe('a text_autogen challenge', function () {
    /**
     * An active, text-proof daily challenge whose period 0 is open now, with
     * `$enrolled` (and whoever else the test adds) enrolled.
     *
     * @return array{0: Challenge, 1: ChallengePeriod}
     */
    function aPhraseChallenge(User $enrolled): array
    {
        $challenge = Challenge::factory()->active()->create([
            'creator_id' => User::factory()->telegram()->create()->getKey(),
            'proof_type' => ProofType::TextAutogen,
            'starts_at' => now()->startOfDay(),
            'total_periods' => 3,
        ]);

        $period = ChallengePeriod::factory()->for($challenge)->create([
            'index' => 0,
            'starts_at' => now()->startOfDay(),
            'ends_at' => now()->addDay()->startOfDay(),
        ]);

        ChallengeParticipant::factory()
            ->for($challenge)
            ->for($enrolled)
            ->create(['joined_period_index' => 0]);

        return [$challenge, $period];
    }

    it('issues the participant their phrase on the detail, scoped to the current period', function () {
        $user = aMiniAppActor();
        [$challenge] = aPhraseChallenge($user);

        $response = test()->getJson('/api/v1/miniapp/challenges/'.$challenge->getKey());

        $response->assertOk()
            ->assertJsonPath('data.current_period.check_in.value', 'pending');

        // The participant's own phrase, on the open period only — the SPA shows
        // exactly this string in its phrase field. Non-empty rather than any
        // particular words: the generator is exercised by its own suite.
        $phrase = $response->json('data.current_period.check_in.expected_phrase');
        expect($phrase)->toBeString()->not->toBe('');

        // Reading the detail is what mints it — one row, ready and unsent.
        $checkIn = CheckIn::query()->sole();
        expect($checkIn->expected_phrase)->toBe($phrase)
            ->and($checkIn->status)->toBe(CheckInStatus::Pending)
            ->and($checkIn->challenge_period_id)->toBe($challenge->periods()->sole()->getKey());
    });

    it('checks in with the participant’s own phrase and returns the settled row', function () {
        $user = aMiniAppActor();
        [$challenge, $period] = aPhraseChallenge($user);

        $phrase = (string) test()->getJson('/api/v1/miniapp/challenges/'.$challenge->getKey())->json('data.current_period.check_in.expected_phrase');

        $response = test()->postJson('/api/v1/miniapp/challenges/'.$challenge->getKey().'/check-in', [
            'text' => $phrase,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.current_period.check_in.value', 'approved')
            // Settled: the phrase has served its turn and leaves the wire.
            ->assertJsonMissingPath('data.current_period.check_in.expected_phrase')
            ->assertJsonPath('data.current_period.owes_check_in', false)
            ->assertJsonPath('data.me.current_streak', 1);

        $checkIn = CheckIn::query()->sole();
        expect($checkIn->status)->toBe(CheckInStatus::Approved)
            ->and($checkIn->challenge_period_id)->toBe($period->getKey())
            ->and($checkIn->expected_phrase)->toBe($phrase)
            // Normalised on the way in, so what is recorded is the canonical
            // form — the same comparison rule the bot uses.
            ->and($checkIn->submitted_text)->toBe(CheckIn::normalisePhrase($phrase));
    });

    it('refuses a wrong phrase with phrase_mismatch and leaves the row unsent', function () {
        $user = aMiniAppActor();
        [$challenge] = aPhraseChallenge($user);

        $phrase = (string) test()->getJson('/api/v1/miniapp/challenges/'.$challenge->getKey())->json('data.current_period.check_in.expected_phrase');

        $response = test()->postJson('/api/v1/miniapp/challenges/'.$challenge->getKey().'/check-in', [
            // A plausible-but-wrong phrase: the right shape, not the right
            // words. The mechanic's job is to tell those apart.
            'text' => $phrase === 'blue anchor 42' ? 'red anchor 42' : 'blue anchor 42',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('reason', 'phrase_mismatch');

        // The row was nothing and remains nothing: no submission time, no
        // stored text — the participant can look at their phrase and try again.
        $checkIn = CheckIn::query()->sole();
        expect($checkIn->status)->toBe(CheckInStatus::Pending)
            ->and($checkIn->submitted_text)->toBeNull()
            ->and($checkIn->submitted_at)->toBeNull();
    });

    it('refuses an empty phrase with proof_missing and writes nothing', function () {
        $user = aMiniAppActor();
        [$challenge] = aPhraseChallenge($user);

        test()->postJson('/api/v1/miniapp/challenges/'.$challenge->getKey().'/check-in', [
            'text' => '',
        ])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'proof_missing');

        // Not even the opened row: the empty check-in is refused before the
        // obligation is opened, unlike a phrase that is checked against a row.
        expect(CheckIn::query()->count())->toBe(0);
    });

    it('does not weaken the mechanic: a second surface still compares', function () {
        // The bot and the Mini App call the same action, so a phrase that
        // fails one surface fails both. This is the same wrong-phrase refusal
        // as above, asserted through the API to pin the route.
        $user = aMiniAppActor();
        [$challenge] = aPhraseChallenge($user);

        $phrase = (string) test()->getJson('/api/v1/miniapp/challenges/'.$challenge->getKey())->json('data.current_period.check_in.expected_phrase');

        test()->postJson('/api/v1/miniapp/challenges/'.$challenge->getKey().'/check-in', [
            'text' => strrev($phrase),
        ])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'phrase_mismatch');
    });

    it('renders the phrase to each participant alone', function () {
        $userA = aMiniAppActor(777_010_0);
        [$challenge] = aPhraseChallenge($userA);

        $userB = User::factory()
            ->telegram(777_010_1)
            ->channelVerified()
            ->preferring('en')
            ->create();
        ChallengeParticipant::factory()->for($challenge)->for($userB)->create(['joined_period_index' => 0]);

        $phraseA = (string) test()->getJson('/api/v1/miniapp/challenges/'.$challenge->getKey())->json('data.current_period.check_in.expected_phrase');

        // B's own detail — a different token. `forgetGuards()` because the
        // guard caches the user it resolved for the *first* request and a test
        // shares one container across both; without it B's request would be
        // answered as A, and this test would pass or fail on the harness
        // rather than on the phrase. A real open resolves its guard fresh.
        app('auth')->forgetGuards();

        $response = test()->withToken($userB->createToken('miniapp', ['miniapp'])->plainTextToken)
            ->getJson('/api/v1/miniapp/challenges/'.$challenge->getKey());

        $response->assertOk();

        $phraseB = $response->json('data.current_period.check_in.expected_phrase');
        expect($phraseB)->toBeString()->not->toBe('')
            ->and($phraseB)->not->toBe($phraseA);

        // The same database row each time: there is one check-in per
        // participant per period, and each carries its owner's phrase.
        expect(CheckIn::query()->count())->toBe(2);
    });

    it('carries no phrase on the listing', function () {
        $user = aMiniAppActor();
        [$challenge] = aPhraseChallenge($user);

        // The listing must not mint or expose: a phrase is the participant's
        // own and never spreads across rows.
        test()->getJson('/api/v1/miniapp/challenges')
            ->assertOk()
            ->assertJsonMissingPath('data.0.current_period.check_in.expected_phrase');

        expect(CheckIn::query()->count())->toBe(0);
    });

    it('takes a quantity report on a quantity challenge alongside the phrase', function () {
        $user = aMiniAppActor();

        $challenge = Challenge::factory()->active()->quantity()->create([
            'creator_id' => User::factory()->telegram()->create()->getKey(),
            'proof_type' => ProofType::TextAutogen,
            'starts_at' => now()->startOfDay(),
            'total_periods' => 3,
        ]);
        ChallengePeriod::factory()->for($challenge)->create([
            'index' => 0,
            'starts_at' => now()->startOfDay(),
            'ends_at' => now()->addDay()->startOfDay(),
        ]);
        ChallengeParticipant::factory()
            ->for($challenge)
            ->for($user)
            ->create(['joined_period_index' => 0]);

        $phrase = (string) test()->getJson('/api/v1/miniapp/challenges/'.$challenge->getKey())->json('data.current_period.check_in.expected_phrase');

        // One request, both proofs of the period's work: the phrase and the
        // number together.
        $response = test()->postJson('/api/v1/miniapp/challenges/'.$challenge->getKey().'/check-in', [
            'text' => $phrase,
            'reported_value' => 45,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.current_period.check_in.value', 'approved')
            ->assertJsonPath('data.current_period.check_in.reported_value', 45)
            ->assertJsonPath('data.current_period.check_in.score', 150)
            ->assertJsonPath('data.me.total_score', 150);

        $checkIn = CheckIn::query()->sole();
        expect($checkIn->reported_value)->toBe('45.00')
            ->and($checkIn->score)->toBe('150.00');
    });
});
