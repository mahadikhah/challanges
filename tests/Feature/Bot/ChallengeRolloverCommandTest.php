<?php

use App\Actions\Challenges\MaterialiseChallengePeriods;
use App\Enums\ChallengeStatus;
use App\Enums\CheckInStatus;
use App\Enums\ParticipantStatus;
use App\Models\Challenge;
use App\Models\ChallengeParticipant;
use App\Models\CheckIn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
 * `challenges:roll-over` — the platform's minute hand. Nothing else calls
 * `RollOverPeriod`, so this file owns the whole question of what the clock does:
 * activating started challenges, settling elapsed periods (and doing it exactly
 * once however often it runs), and completing finished timelines.
 *
 * The command never talks to Telegram, so the Bot API needs no faking here —
 * `Http::preventStrayRequests()` proves that by failing if it ever tries.
 */

beforeEach(function () {
    Http::preventStrayRequests();
});

/**
 * A daily challenge in UTC whose first period opened an hour ago.
 */
function aRunningChallenge(int $totalPeriods = 3): Challenge
{
    $challenge = Challenge::factory()->create([
        'status' => ChallengeStatus::Active,
        'starts_at' => now()->subHour()->startOfHour(),
        'total_periods' => $totalPeriods,
    ]);

    app(MaterialiseChallengePeriods::class)->handle($challenge);

    return $challenge;
}

/**
 * An active participant with a streak on the line.
 */
function owingParticipant(Challenge $challenge, array $attributes = []): ChallengeParticipant
{
    return ChallengeParticipant::factory()
        ->for($challenge)
        ->for(User::factory()->telegram()->preferring('en'))
        ->create($attributes);
}

it('activates a scheduled challenge whose timeline has begun', function () {
    $challenge = aRunningChallenge();
    $challenge->forceFill(['status' => ChallengeStatus::Scheduled])->save();

    Artisan::call('challenges:roll-over');

    expect($challenge->refresh()->status)->toBe(ChallengeStatus::Active);
});

it('settles an elapsed period as missed, with the streak consequence, exactly once', function () {
    $challenge = aRunningChallenge();
    $participant = owingParticipant($challenge, ['current_streak' => 2, 'freezes_total' => 0]);

    // The first period closed a minute ago.
    $this->travelTo(now()->subHour()->startOfHour()->addDays(1)->addMinute());

    Artisan::call('challenges:roll-over');

    $checkIn = CheckIn::query()->where('challenge_participant_id', $participant->getKey())->sole();

    expect($checkIn->status)->toBe(CheckInStatus::Missed)
        ->and($participant->refresh())
        ->current_streak->toBe(0)
        ->streak_resets_count->toBe(1)
        ->status->toBe(ParticipantStatus::Active);

    // The second run is the idempotency question: the same sweep, the same rows,
    // and the miss must not be counted twice.
    Artisan::call('challenges:roll-over');

    expect($participant->refresh())
        ->current_streak->toBe(0)
        ->streak_resets_count->toBe(1)
        ->and(CheckIn::query()->where('challenge_participant_id', $participant->getKey())->count())->toBe(1);
});

it('spends a freeze instead of breaking the streak when one is available', function () {
    $challenge = aRunningChallenge();
    $participant = owingParticipant($challenge, ['current_streak' => 5, 'freezes_total' => 1]);

    $this->travelTo(now()->subHour()->startOfHour()->addDays(1)->addMinute());

    Artisan::call('challenges:roll-over');

    expect(CheckIn::query()->where('challenge_participant_id', $participant->getKey())->sole()->status)
        ->toBe(CheckInStatus::Frozen)
        ->and($participant->refresh())
        ->current_streak->toBe(5)
        ->freezes_used->toBe(1)
        ->streak_resets_count->toBe(0);
});

it('leaves a late joiner out of a period that closed before they joined', function () {
    $challenge = aRunningChallenge();
    $latecomer = owingParticipant($challenge, ['joined_period_index' => 1]);

    $this->travelTo(now()->subHour()->startOfHour()->addDays(1)->addMinute());

    Artisan::call('challenges:roll-over');

    expect(CheckIn::query()->where('challenge_participant_id', $latecomer->getKey())->count())->toBe(0);
});

it('completes a challenge once every period has been swept', function () {
    $challenge = aRunningChallenge(totalPeriods: 1);
    owingParticipant($challenge);

    // One period, so one boundary: the whole timeline has elapsed.
    $this->travelTo(now()->subHour()->startOfHour()->addDays(2));

    Artisan::call('challenges:roll-over');

    expect($challenge->refresh()->status)->toBe(ChallengeStatus::Completed)
        ->and($challenge->periods()->whereNotNull('rolled_over_at')->count())->toBe(1);
});

it('leaves a running challenge alone while periods remain', function () {
    $challenge = aRunningChallenge(totalPeriods: 3);

    $this->travelTo(now()->subHour()->startOfHour()->addDays(1)->addMinute());

    Artisan::call('challenges:roll-over');

    expect($challenge->refresh()->status)->toBe(ChallengeStatus::Active);
});
