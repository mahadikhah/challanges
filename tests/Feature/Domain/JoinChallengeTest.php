<?php

namespace Tests\Feature\Domain;

use App\Actions\Challenges\JoinChallenge;
use App\Actions\Challenges\MaterialiseChallengePeriods;
use App\Enums\ChallengeStatus;
use App\Enums\EntitlementType;
use App\Enums\JoinRejection;
use App\Enums\ParticipantStatus;
use App\Exceptions\ChallengeNotJoinableException;
use App\Exceptions\NoEntitlementAvailableException;
use App\Models\Challenge;
use App\Models\ChallengeParticipant;
use App\Models\Entitlement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * The one place a `challenge_participants` row is written. The bot's join button,
 * an invite-only deep link, the Mini App and the admin panel all come through
 * here, so what this file pins down is the floor whichever surface called.
 *
 * The two properties the whole economy rests on: **joining twice costs one
 * slot**, because a double-tapped button and a Telegram retry both arrive as two
 * calls for one intention; and **nothing is written when the join is refused**,
 * because a refusal that still spent a slot is a slot stolen.
 */

beforeEach(function () {
    $this->join = app(JoinChallenge::class);
    $this->joiner = User::factory()->telegram()->create();
});

/**
 * Give the joiner join-slots to spend, without buying them.
 */
function withJoinSlots(User $user, int $count = 1): void
{
    Entitlement::factory()->count($count)->joinSlot()->create(['user_id' => $user->getKey()]);
}

/**
 * A challenge that is open to join, naming only what the test is about.
 *
 * The factory writes no timeline — that is `CreateChallenge`'s job in production —
 * so the real materialiser runs here, which keeps `joined_period_index` honest
 * about where "now" falls rather than leaning on hand-built periods.
 *
 * @param  array<string, mixed>  $overrides
 */
function joinable(array $overrides = []): Challenge
{
    $challenge = Challenge::factory()->active()->create($overrides);

    app(MaterialiseChallengePeriods::class)->handle($challenge);

    return $challenge;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function joining(Challenge $challenge, ?User $joiner = null): ChallengeParticipant
{
    return test()->join->handle($joiner ?? test()->joiner, $challenge);
}

describe('what it writes', function () {
    it('records the participant against the challenge and the user', function () {
        withJoinSlots($this->joiner);

        $challenge = joinable();
        $participant = joining($challenge);

        expect($participant->challenge_id)->toBe($challenge->getKey())
            ->and($participant->user_id)->toBe($this->joiner->getKey())
            ->and($participant->status)->toBe(ParticipantStatus::Active);
    });

    it('seeds freezes from the challenge, not the admin default', function () {
        withJoinSlots($this->joiner);

        // Snapshotted at join: a creator raising the allowance later must not
        // retroactively un-freeze periods that were already missed.
        $participant = joining(joinable(['default_freezes' => 0]));

        expect($participant->freezes_total)->toBe(0);
    });

    it('joins at the period that is open now', function () {
        withJoinSlots($this->joiner);

        // A daily challenge that opened three days ago: periods 0, 1 and 2 are
        // closed, period 3 is live.
        $challenge = joinable(['starts_at' => now()->subDays(3)->startOfDay()]);

        expect(joining($challenge)->joined_period_index)->toBe(3);
    });

    it('joins at the top of a challenge that has not started yet', function () {
        withJoinSlots($this->joiner);

        $challenge = joinable(['status' => ChallengeStatus::Scheduled, 'starts_at' => now()->addWeek()]);

        expect(joining($challenge)->joined_period_index)->toBe(0);
    });

    it('does not owe periods that closed before a late joiner arrived', function () {
        withJoinSlots($this->joiner);

        $challenge = joinable(['starts_at' => now()->subDays(5)->startOfDay()]);
        $participant = joining($challenge);

        $closed = $challenge->periods()->where('index', 2)->first();

        expect($participant->owesPeriod($closed))->toBeFalse()
            ->and($participant->owesPeriod($challenge->periods()->containing(now())->first()))->toBeTrue();
    });
});

describe('the join-slot', function () {
    it('spends one, recorded against the challenge it went on', function () {
        withJoinSlots($this->joiner, 2);

        $challenge = joinable();
        joining($challenge);

        $spent = Entitlement::query()->whereNotNull('consumed_at')->get();

        expect($spent)->toHaveCount(1)
            ->and($spent->first()?->challenge_id)->toBe($challenge->getKey())
            ->and($spent->first()?->type)->toBe(EntitlementType::JoinSlot);
    });

    it('refuses a joiner with no slot left', function () {
        expect(fn () => joining(joinable()))->toThrow(NoEntitlementAvailableException::class);
    });

    it('writes no participant when there was no slot to pay for it', function () {
        try {
            joining(joinable());
        } catch (NoEntitlementAvailableException) {
            // Expected.
        }

        expect(ChallengeParticipant::query()->count())->toBe(0);
    });

    it('does not touch a create-slot', function () {
        Entitlement::factory()->createSlot()->create(['user_id' => $this->joiner->getKey()]);

        expect(fn () => joining(joinable()))->toThrow(NoEntitlementAvailableException::class)
            ->and(Entitlement::query()->whereNull('consumed_at')->count())->toBe(1);
    });
});

describe('joining twice', function () {
    it('returns the same participation without spending a second slot', function () {
        withJoinSlots($this->joiner, 2);

        $challenge = joinable();
        $first = joining($challenge);
        $second = joining($challenge);

        expect($second->is($first))->toBeTrue()
            ->and($second->wasRecentlyCreated)->toBeFalse()
            ->and(ChallengeParticipant::query()->count())->toBe(1)
            ->and(Entitlement::query()->whereNotNull('consumed_at')->count())->toBe(1);
    });

    it('charges nothing when an admin put the participant there without a spend', function () {
        // The reason the existence check comes before the spend: an admin-seeded
        // participant has no entitlement recorded against the challenge, so
        // `ConsumeEntitlement`'s own idempotency would not fire — a returning
        // user would be billed for a participation they already had.
        withJoinSlots($this->joiner);

        $challenge = joinable();
        $adminSeeded = ChallengeParticipant::factory()->for($challenge)->for($this->joiner)->create();

        expect(joining($challenge)->is($adminSeeded))->toBeTrue()
            ->and(Entitlement::query()->whereNotNull('consumed_at')->count())->toBe(0);
    });
});

describe('who may join', function () {
    it('lets the creator join their own challenge, spending a slot like anybody else', function () {
        $creator = User::factory()->telegram()->create();
        $challenge = Challenge::factory()->for($creator, 'creator')->active()->create();

        app(MaterialiseChallengePeriods::class)->handle($challenge);

        withJoinSlots($creator);

        $participant = $this->join->handle($creator, $challenge);

        expect($participant->user_id)->toBe($creator->getKey())
            ->and(Entitlement::query()->whereNotNull('consumed_at')->count())->toBe(1);
    });

    it('refuses a challenge that is completed', function () {
        withJoinSlots($this->joiner);

        $challenge = Challenge::factory()->completed()->create();

        expect(fn () => joining($challenge))->toThrow(ChallengeNotJoinableException::class);
    });

    it('refuses a challenge that was cancelled', function () {
        withJoinSlots($this->joiner);

        $challenge = Challenge::factory()->cancelled()->create();

        expect(fn () => joining($challenge))->toThrow(ChallengeNotJoinableException::class);
    });

    it('carries the reason on the refusal', function () {
        withJoinSlots($this->joiner);

        $challenge = Challenge::factory()->cancelled()->create();

        try {
            joining($challenge);
        } catch (ChallengeNotJoinableException $refused) {
            expect($refused->reason)->toBe(JoinRejection::ChallengeClosed)
                ->and($refused->challenge->getKey())->toBe($challenge->getKey());

            return;
        }

        $this->fail('The join was not refused.');
    });

    it('spends no slot on a challenge it refused', function () {
        withJoinSlots($this->joiner);

        try {
            joining(Challenge::factory()->cancelled()->create());
        } catch (ChallengeNotJoinableException) {
            // Expected.
        }

        expect(Entitlement::query()->whereNotNull('consumed_at')->count())->toBe(0)
            ->and(ChallengeParticipant::query()->count())->toBe(0);
    });
});

describe('a participation that has ended', function () {
    it('refuses to reactivate somebody who left', function () {
        withJoinSlots($this->joiner, 2);

        $challenge = joinable();
        ChallengeParticipant::factory()->for($challenge)->for($this->joiner)->left()->create();

        expect(fn () => joining($challenge))->toThrow(ChallengeNotJoinableException::class);
    });

    it('refuses to reactivate somebody a moderator removed', function () {
        withJoinSlots($this->joiner, 2);

        $challenge = joinable();
        ChallengeParticipant::factory()->for($challenge)->for($this->joiner)->removed()->create();

        expect(fn () => joining($challenge))->toThrow(ChallengeNotJoinableException::class);
    });

    it('refuses to reactivate somebody who completed it', function () {
        withJoinSlots($this->joiner, 2);

        $challenge = joinable();
        ChallengeParticipant::factory()->for($challenge)->for($this->joiner)->completed()->create();

        expect(fn () => joining($challenge))->toThrow(ChallengeNotJoinableException::class);
    });

    it('spends no second slot on the refusal', function () {
        withJoinSlots($this->joiner, 2);

        $challenge = joinable();
        ChallengeParticipant::factory()->for($challenge)->for($this->joiner)->left()->create();

        try {
            joining($challenge);
        } catch (ChallengeNotJoinableException) {
            // Expected.
        }

        expect(Entitlement::query()->whereNotNull('consumed_at')->count())->toBe(0);
    });
});

describe('a timeline that has run out', function () {
    it('refuses a challenge whose periods have all closed but whose status has not caught up', function () {
        withJoinSlots($this->joiner);

        // Rollover moves a challenge to `completed` on a schedule, not at the
        // instant the final period closes, so this window is real.
        $challenge = Challenge::factory()->active()
            ->timeline(now()->subMonth()->startOfDay()->toDateString(), 'UTC', 3)
            ->create();

        expect(fn () => joining($challenge))->toThrow(ChallengeNotJoinableException::class);
    });

    it('spends no slot on a timeline it refused', function () {
        withJoinSlots($this->joiner);

        $challenge = Challenge::factory()->active()
            ->timeline(now()->subMonth()->startOfDay()->toDateString(), 'UTC', 3)
            ->create();

        try {
            joining($challenge);
        } catch (ChallengeNotJoinableException) {
            // Expected.
        }

        expect(Entitlement::query()->whereNotNull('consumed_at')->count())->toBe(0)
            ->and(ChallengeParticipant::query()->count())->toBe(0);
    });
});
