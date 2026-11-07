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
 * part in *or runs*, and one challenge's full picture — timeline, their streak
 * and freezes, the period open right now, and the history of periods already
 * settled.
 *
 * The resource resolves the viewer from the bearer token and the route id
 * together, as a participation or as an owner; a challenge that is neither is
 * indistinguishable from one that does not exist. A challenge the user created
 * and never joined is the shape this surface has to hold together: it has no
 * participation row at all, so `me` is null rather than an empty one.
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
 * A challenge `$creator` runs and nobody has joined — the state
 * `CreateChallenge` leaves behind, because creating a challenge does not enrol
 * its creator. Same timeline shape as `aMiniAppChallengeInView()` (period 0
 * closed, period 1 open) with no participation row at all.
 *
 * @return array{0: Challenge, 1: ChallengePeriod, 2: ChallengePeriod}
 */
function aMiniAppOwnedChallenge(User $creator, string $title = 'Run every morning'): array
{
    $challenge = Challenge::factory()->active()->create([
        'creator_id' => $creator->getKey(),
        'title' => $title,
        'description' => 'Five kilometres before work.',
        'starts_at' => now()->subDay()->startOfDay(),
        'total_periods' => 3,
    ]);

    $closed = ChallengePeriod::factory()->for($challenge)->create([
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

    return [$challenge, $closed, $open];
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
            'has_joined' => true,
            'scoring' => null,
            'me' => [
                'status' => anEnumValueLabel('participant_status', 'active'),
                'current_streak' => 2,
                'longest_streak' => 3,
                'total_score' => 0,
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

it('lists a challenge its creator never joined', function () {
    $user = aMiniAppUser();
    [$challenge, , $open] = aMiniAppOwnedChallenge($user);

    // The premise: creating a challenge enrols nobody, so this row had nothing
    // for the old listing to find it by.
    expect($challenge->participants()->count())->toBe(0);

    test()->getJson('/api/v1/miniapp/challenges')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0', [
            'id' => $challenge->getKey(),
            'title' => 'Run every morning',
            'description' => 'Five kilometres before work.',
            'status' => anEnumValueLabel('challenge_status', 'active'),
            'period_type' => anEnumValueLabel('period_type', 'daily'),
            'starts_at' => $challenge->starts_at->toIso8601String(),
            'timezone' => 'UTC',
            'total_periods' => 3,
            'proof_type' => anEnumValueLabel('proof_type', 'button'),
            'visibility' => anEnumValueLabel('challenge_visibility', 'invite_only'),
            'is_creator' => true,
            'has_joined' => false,
            'scoring' => null,
            // No participant at all — never a participant whose counters
            // happen to read zero.
            'me' => null,
            // The timeline is the challenge's and is stated for every viewer;
            // what is owed is not this viewer's to owe, and there is no
            // check-in of theirs to report.
            'current_period' => [
                'index' => 1,
                'starts_at' => $open->starts_at->toIso8601String(),
                'ends_at' => $open->ends_at->toIso8601String(),
                'owes_check_in' => false,
                'check_in' => null,
            ],
            'history' => [],
        ]);
});

it('shows the detail screen to a creator who never joined', function () {
    $user = aMiniAppUser();
    [$challenge, , $open] = aMiniAppOwnedChallenge($user);

    test()->getJson('/api/v1/miniapp/challenges/'.$challenge->getKey())
        ->assertOk()
        ->assertJsonPath('data.title', 'Run every morning')
        ->assertJsonPath('data.description', 'Five kilometres before work.')
        ->assertJsonPath('data.status', anEnumValueLabel('challenge_status', 'active'))
        ->assertJsonPath('data.proof_type', anEnumValueLabel('proof_type', 'button'))
        ->assertJsonPath('data.visibility', anEnumValueLabel('challenge_visibility', 'invite_only'))
        ->assertJsonPath('data.total_periods', 3)
        ->assertJsonPath('data.is_creator', true)
        ->assertJsonPath('data.has_joined', false)
        ->assertJsonPath('data.me', null)
        ->assertJsonPath('data.history', [])
        ->assertJsonPath('data.current_period', [
            'index' => 1,
            'starts_at' => $open->starts_at->toIso8601String(),
            'ends_at' => $open->ends_at->toIso8601String(),
            'owes_check_in' => false,
            'check_in' => null,
        ]);
});

it('does not spell an absent participation the way it spells zero', function () {
    $user = aMiniAppUser();

    // Side by side: a challenge the user is in but has earned nothing in yet,
    // and one they run and are not in. The first has a streak of zero; the
    // second has no streak, and the two must not come out the same.
    [$joined] = aMiniAppOwnedChallenge(User::factory()->telegram()->create(), 'In, nothing earned');
    ChallengeParticipant::factory()->for($joined)->for($user)->create();

    [$owned] = aMiniAppOwnedChallenge($user, 'Mine, not joined');

    $rows = test()->getJson('/api/v1/miniapp/challenges')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $rows->assertJsonPath('data.0.id', $owned->getKey())
        ->assertJsonPath('data.0.has_joined', false)
        ->assertJsonPath('data.0.me', null)
        ->assertJsonPath('data.1.id', $joined->getKey())
        ->assertJsonPath('data.1.has_joined', true)
        ->assertJsonPath('data.1.me.current_streak', 0)
        ->assertJsonPath('data.1.me.freezes.remaining', 1);
});

it('still hides a challenge nobody has anything to do with', function () {
    $user = aMiniAppUser();

    // Owned by somebody else and joined by nobody: reading ownership must not
    // have widened into "any challenge without participants".
    [$someoneElses] = aMiniAppOwnedChallenge(User::factory()->telegram()->create());

    // Their challenge, an id that never existed, and an id that is not one:
    // three answers, all the same.
    test()->getJson('/api/v1/miniapp/challenges/'.$someoneElses->getKey())->assertStatus(404);
    test()->getJson('/api/v1/miniapp/challenges/99999')->assertStatus(404);
    test()->getJson('/api/v1/miniapp/challenges/not-an-id')->assertStatus(404);

    test()->getJson('/api/v1/miniapp/challenges')->assertOk()->assertJsonCount(0, 'data');
});

it('names a challenge its creator both runs and is in exactly once', function () {
    $user = aMiniAppUser();
    [$challenge] = aMiniAppOwnedChallenge($user);

    ChallengeParticipant::factory()
        ->for($challenge)
        ->for($user)
        ->withStreak(4, 6)
        ->create();

    test()->getJson('/api/v1/miniapp/challenges')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.is_creator', true)
        ->assertJsonPath('data.0.has_joined', true)
        // Their real numbers, not the not-joined shape.
        ->assertJsonPath('data.0.me.current_streak', 4)
        ->assertJsonPath('data.0.me.longest_streak', 6);
});

it('orders what the user runs and what they are in by challenge, newest first', function () {
    $user = aMiniAppUser();

    // Two challenges the user is merely in, joined in the opposite order to
    // the order they were created — so "most recently joined" and "newest
    // challenge" disagree, and only one of them can be the rule.
    [$older] = aMiniAppOwnedChallenge(User::factory()->telegram()->create(), 'Older');
    [$newer] = aMiniAppOwnedChallenge(User::factory()->telegram()->create(), 'Newer');

    ChallengeParticipant::factory()->for($newer)->for($user)->create();
    ChallengeParticipant::factory()->for($older)->for($user)->create();

    // And one the user runs without being in it, created last of all.
    [$owned] = aMiniAppOwnedChallenge($user, 'Mine');

    $ids = test()->getJson('/api/v1/miniapp/challenges')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->json('data.*.id');

    expect($ids)->toBe([$owned->getKey(), $newer->getKey(), $older->getKey()]);
});
