<?php

use App\Models\Challenge;
use App\Models\ChallengeParticipant;
use App\Models\ChallengePeriod;
use App\Models\CheckIn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;

uses(RefreshDatabase::class);

/*
 * The Mini App's challenge surface: the list of what the token's user takes
 * part in, and one challenge's full picture — timeline, their streak and
 * freezes, the period open right now, and the history of periods already
 * settled.
 *
 * The resource resolves the participant from the bearer token and the route
 * id together; a challenge the user is not in is indistinguishable from one
 * that does not exist.
 */

/**
 * A user with a working Mini App bearer token, without the initData ceremony
 * — that exchange has its own test file; this one is about what the token
 * can see.
 */
function aMiniAppUser(int $telegramId = 777_001_0, string $locale = 'en'): User
{
    $user = User::factory()->telegram($telegramId)->preferring($locale)->create();
    $token = $user->createToken('miniapp', ['miniapp'])->plainTextToken;

    test()->withToken($token);

    return $user;
}

/**
 * A daily, active, invite-only challenge whose period 0 closed this morning
 * and whose period 1 is open now — the shape every "status" assertion wants.
 */
function aMiniAppChallengeInView(User $participantUser): array
{
    $challenge = Challenge::factory()->active()->create([
        'creator_id' => User::factory()->telegram()->create()->getKey(),
        'title' => 'Read every day',
        'description' => 'Twenty pages, minimum.',
        'starts_at' => now()->subDay()->startOfDay(),
        'total_periods' => 3,
        'default_freezes' => 2,
    ]);

    $settled = ChallengePeriod::factory()->for($challenge)->create([
        'index' => 0,
        'starts_at' => now()->subDay()->startOfDay(),
        'ends_at' => now()->startOfDay(),
        'rolled_over_at' => now()->startOfDay(),
    ]);

    $open = ChallengePeriod::factory()->for($challenge)->create([
        'index' => 1,
        'starts_at' => now()->startOfDay(),
        'ends_at' => now()->addDay()->startOfDay(),
    ]);

    $participant = ChallengeParticipant::factory()
        ->for($challenge)
        ->for($participantUser)
        ->withStreak(2, 3)
        ->withFreezes(2, 1)
        ->create(['joined_period_index' => 0]);

    return [$challenge, $settled, $open, $participant];
}

/**
 * An enum as the surface states it: value plus translated label.
 *
 * @return array<string, string>
 */
function anEnumValueLabel(string $group, string $value): array
{
    return ['value' => $value, 'label' => Lang::get("enums.{$group}.{$value}")];
}

it('refuses every challenge route without a token', function () {
    test()->getJson('/api/v1/miniapp/challenges')->assertStatus(401);
    test()->getJson('/api/v1/miniapp/challenges/1')->assertStatus(401);
});

it('lists the user’s challenges with their place in each', function () {
    $user = aMiniAppUser();
    [$challenge, $settled, $open, $participant] = aMiniAppChallengeInView($user);

    CheckIn::factory()->on($participant, $settled)->approved()->create(['submitted_at' => now()->subDay()]);
    CheckIn::factory()->on($participant, $open)->create();

    // Somebody else's participation must not leak into this list.
    ChallengeParticipant::factory()->create();

    test()->getJson('/api/v1/miniapp/challenges')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0', [
            'id' => $challenge->getKey(),
            'title' => 'Read every day',
            'description' => 'Twenty pages, minimum.',
            'status' => anEnumValueLabel('challenge_status', 'active'),
            'period_type' => anEnumValueLabel('period_type', 'daily'),
            'starts_at' => $challenge->starts_at->toIso8601String(),
            'timezone' => 'UTC',
            'total_periods' => 3,
            'proof_type' => anEnumValueLabel('proof_type', 'button'),
            'visibility' => anEnumValueLabel('challenge_visibility', 'invite_only'),
            'is_creator' => false,
            'me' => [
                'status' => anEnumValueLabel('participant_status', 'active'),
                'current_streak' => 2,
                'longest_streak' => 3,
                'joined_period_index' => 0,
                'freezes' => ['total' => 2, 'used' => 1, 'remaining' => 1],
            ],
            'current_period' => [
                'index' => 1,
                'starts_at' => $open->starts_at->toIso8601String(),
                'ends_at' => $open->ends_at->toIso8601String(),
                'owes_check_in' => true,
                'check_in' => anEnumValueLabel('check_in_status', 'pending'),
            ],
            'history' => [
                ['index' => 0, 'status' => anEnumValueLabel('check_in_status', 'approved')],
            ],
        ]);
});

it('shows an already-kept current period as not owing', function () {
    $user = aMiniAppUser();
    [, , $open, $participant] = aMiniAppChallengeInView($user);

    CheckIn::factory()->on($participant, $open)->approved()->create(['submitted_at' => now()]);

    test()->getJson('/api/v1/miniapp/challenges/'.$participant->challenge_id)
        ->assertOk()
        ->assertJsonPath('data.current_period.owes_check_in', false)
        ->assertJsonPath('data.current_period.check_in', anEnumValueLabel('check_in_status', 'approved'));
});

it('starts a late joiner’s history where their obligations did', function () {
    $user = aMiniAppUser();
    [$challenge, , $open, $participant] = aMiniAppChallengeInView($user);

    // Join at period 1, then close the open period early: both periods are
    // now settled, but only period 1 was ever this user's to answer for.
    $participant->forceFill(['joined_period_index' => 1])->save();
    $open->forceFill([
        'ends_at' => now()->subHour(),
        'rolled_over_at' => now()->subHour(),
    ])->save();

    CheckIn::factory()->on($participant, $open)->missed()->create();

    $history = test()->getJson('/api/v1/miniapp/challenges/'.$challenge->getKey())
        ->assertOk()
        ->json('data.history');

    expect($history)->toBe([
        ['index' => 1, 'status' => anEnumValueLabel('check_in_status', 'missed')],
    ]);
});

it('fills a settled period the rollover has not swept yet as pending', function () {
    $user = aMiniAppUser();
    [$challenge, , , $participant] = aMiniAppChallengeInView($user);

    // No check-in row for the settled period at all — closed, but rollover
    // has not minted the terminal row yet.
    expect($participant->checkIns()->count())->toBe(0);

    $history = test()->getJson('/api/v1/miniapp/challenges/'.$challenge->getKey())
        ->assertOk()
        ->json('data.history');

    expect($history)->toBe([
        ['index' => 0, 'status' => anEnumValueLabel('check_in_status', 'pending')],
    ]);
});

it('shows no current period before the timeline opens', function () {
    $user = aMiniAppUser();

    $challenge = Challenge::factory()->create([
        'creator_id' => $user->getKey(),
        'starts_at' => now()->addDay()->startOfDay(),
    ]);

    ChallengeParticipant::factory()->for($challenge)->for($user)->create();
    ChallengePeriod::factory()->for($challenge)->create([
        'index' => 0,
        'starts_at' => now()->addDay()->startOfDay(),
        'ends_at' => now()->addDays(2)->startOfDay(),
    ]);

    test()->getJson('/api/v1/miniapp/challenges/'.$challenge->getKey())
        ->assertOk()
        ->assertJsonPath('data.current_period', null)
        ->assertJsonPath('data.is_creator', true)
        ->assertJsonPath('data.history', []);
});

it('shows no current period once the timeline is spent', function () {
    $user = aMiniAppUser();

    $challenge = Challenge::factory()->completed()->create([
        'creator_id' => User::factory()->telegram()->create()->getKey(),
    ]);

    ChallengeParticipant::factory()
        ->for($challenge)
        ->for($user)
        ->completed()
        ->create(['joined_period_index' => 0]);

    ChallengePeriod::factory()->for($challenge)->create([
        'index' => 0,
        'starts_at' => now()->subDays(2)->startOfDay(),
        'ends_at' => now()->subDay()->startOfDay(),
        'rolled_over_at' => now()->subDay(),
    ]);

    test()->getJson('/api/v1/miniapp/challenges/'.$challenge->getKey())
        ->assertOk()
        ->assertJsonPath('data.current_period', null);
});

it('hides a challenge the token’s user does not take part in', function () {
    $user = aMiniAppUser();

    $someoneElses = Challenge::factory()->active()->create();
    ChallengeParticipant::factory()->for($someoneElses)->create();

    // Their challenge exists, this user is not in it, and the answer is the
    // same as for a challenge that never existed.
    test()->getJson('/api/v1/miniapp/challenges/'.$someoneElses->getKey())->assertStatus(404);
    test()->getJson('/api/v1/miniapp/challenges/99999')->assertStatus(404);
});

it('labels everything in the language the user chose', function () {
    // The stored preference must beat the Accept-Language the request rode
    // in on: the user picked Farsi in the bot, so Farsi is what they see.
    $user = aMiniAppUser(777_001_0, 'fa');
    [, , , $participant] = aMiniAppChallengeInView($user);

    $response = test()->withHeaders(['Accept-Language' => 'en'])
        ->getJson('/api/v1/miniapp/challenges/'.$participant->challenge_id);

    $response->assertOk();

    expect($response->json('data.period_type.label'))
        ->toBe(Lang::get('enums.period_type.daily', [], 'fa'))
        ->and($response->json('data.me.status.label'))
        ->toBe(Lang::get('enums.participant_status.active', [], 'fa'));
});

it('shows a cancelled challenge to the participant who was in it', function () {
    $user = aMiniAppUser();

    $challenge = Challenge::factory()->cancelled()->create();
    ChallengeParticipant::factory()->for($challenge)->for($user)->create();

    test()->getJson('/api/v1/miniapp/challenges/'.$challenge->getKey())
        ->assertOk()
        ->assertJsonPath('data.status', anEnumValueLabel('challenge_status', 'cancelled'));
});
