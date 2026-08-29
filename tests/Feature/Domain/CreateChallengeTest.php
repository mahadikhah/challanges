<?php

use App\Actions\Challenges\CreateChallenge;
use App\Actions\Entitlements\ConsumeEntitlement;
use App\Enums\ChallengeStatus;
use App\Enums\ChallengeVisibility;
use App\Enums\EntitlementType;
use App\Enums\PeriodType;
use App\Enums\ProofType;
use App\Enums\ScoringStrategy;
use App\Enums\ScoringType;
use App\Enums\SettingKey;
use App\Exceptions\NoEntitlementAvailableException;
use App\Jobs\Challenges\AnnounceChallenge;
use App\Models\Challenge;
use App\Models\ChallengePeriod;
use App\Models\Entitlement;
use App\Models\User;
use App\Services\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

/*
 * The one place a `challenges` row is written. The bot wizard, the Mini App and the
 * admin panel all come through here, so what this file pins down is the floor: what
 * must hold whichever surface called, and — more importantly — that the four
 * writes are all-or-nothing. A challenge with no slot spent is a free extra
 * challenge; a spent slot with no challenge is a slot stolen; a challenge with no
 * periods can never be checked into and never reminds anybody.
 */

beforeEach(function () {
    Bus::fake([AnnounceChallenge::class]);

    $this->settings = app(Settings::class);
    $this->create = app(CreateChallenge::class);
    $this->slots = app(ConsumeEntitlement::class);
    $this->creator = User::factory()->telegram()->create();
});

/**
 * Give the creator create-slots to spend, without buying them.
 */
function withCreateSlots(User $user, int $count = 1): void
{
    Entitlement::factory()->count($count)->createSlot()->create(['user_id' => $user->getKey()]);
}

/**
 * Create a challenge, naming only what the test is about.
 *
 * @param  array<string, mixed>  $overrides
 */
function creating(array $overrides = []): Challenge
{
    $arguments = array_replace([
        'creator' => test()->creator,
        'title' => 'Read every day',
        'description' => 'Twenty pages, no excuses.',
        'periodType' => PeriodType::Daily,
        'customPeriodDays' => null,
        'startsAt' => CarbonImmutable::now('UTC')->addDay()->startOfDay(),
        'totalPeriods' => 30,
        'timezone' => 'UTC',
        'proofType' => ProofType::Button,
        'visibility' => ChallengeVisibility::InviteOnly,
    ], $overrides);

    /** @var Challenge */
    return test()->create->handle(...$arguments);
}

describe('what it writes', function () {
    it('records every field the creator chose', function () {
        withCreateSlots($this->creator);

        $challenge = creating([
            'title' => '  Read every day  ',
            'periodType' => PeriodType::Custom,
            'customPeriodDays' => 3,
            'timezone' => 'Asia/Tehran',
            'totalPeriods' => 12,
            'proofType' => ProofType::ImageApproval,
        ]);

        expect($challenge->creator_id)->toBe($this->creator->getKey())
            ->and($challenge->title)->toBe('Read every day')
            ->and($challenge->period_type)->toBe(PeriodType::Custom)
            ->and($challenge->custom_period_days)->toBe(3)
            ->and($challenge->timezone)->toBe('Asia/Tehran')
            ->and($challenge->total_periods)->toBe(12)
            ->and($challenge->proof_type)->toBe(ProofType::ImageApproval)
            ->and($challenge->visibility)->toBe(ChallengeVisibility::InviteOnly);
    });

    it('stores the start instant as UTC whatever zone it arrived in', function () {
        withCreateSlots($this->creator);

        $challenge = creating([
            'startsAt' => CarbonImmutable::create(2099, 6, 1, 0, 0, 0, 'Asia/Tehran'),
            'timezone' => 'Asia/Tehran',
        ]);

        // Midnight in Tehran is 20:30 the previous day in UTC. Storing the local
        // wall clock would move every period boundary by the offset.
        expect($challenge->starts_at->toDateTimeString())->toBe('2099-05-31 20:30:00')
            ->and($challenge->starts_at->timezone->getName())->toBe('UTC');
    });

    it('takes the admin-configured freeze allowance when the caller names none', function () {
        withCreateSlots($this->creator);
        $this->settings->set(SettingKey::DefaultChallengeFreezes, 4);

        expect(creating()->default_freezes)->toBe(4);
    });

    it('lets a caller override the freeze allowance', function () {
        withCreateSlots($this->creator);
        $this->settings->set(SettingKey::DefaultChallengeFreezes, 4);

        expect(creating(['defaultFreezes' => 0])->default_freezes)->toBe(0);
    });

    it('drops a day count for a period type that has no use for one', function () {
        withCreateSlots($this->creator);

        // A weekly challenge with `custom_period_days = 3` is a contradiction the
        // materialiser would have to guess about. It is nulled rather than refused,
        // because the caller sending both is a UI leftover, not bad intent.
        expect(creating(['periodType' => PeriodType::Weekly, 'customPeriodDays' => 3])->custom_period_days)
            ->toBeNull();
    });

    it('reads an empty description as no description', function () {
        withCreateSlots($this->creator);

        expect(creating(['description' => '   '])->description)->toBeNull();
    });
});

describe('when it starts', function () {
    it('is scheduled when the timeline opens in the future', function () {
        withCreateSlots($this->creator);

        expect(creating(['startsAt' => CarbonImmutable::now()->addWeek()])->status)
            ->toBe(ChallengeStatus::Scheduled);
    });

    it('is already active when the timeline has opened', function () {
        withCreateSlots($this->creator);

        // Backdating is how the admin panel will import a challenge already under
        // way, and how a "start today" choice behaves for the rest of today.
        expect(creating(['startsAt' => CarbonImmutable::now()->subHour()])->status)
            ->toBe(ChallengeStatus::Active);
    });
});

describe('the proof visibility toggle', function () {
    it('publishes proofs only for the one type that can show them', function (
        ProofType $proofType,
        bool $expected,
    ) {
        withCreateSlots($this->creator);

        // Asking for public proofs on a type that cannot honour it gets private
        // ones rather than an error: it is a display preference, and publishing an
        // autogenerated phrase would hand every participant the answer.
        expect(creating(['proofType' => $proofType, 'proofIsPublic' => true])->proof_is_public)
            ->toBe($expected);
    })->with([
        'a tap has nothing to show' => [ProofType::Button, false],
        'a phrase would be the answer' => [ProofType::TextAutogen, false],
        'a photo can be shown' => [ProofType::ImageApproval, true],
    ]);

    it('keeps proofs private by default', function () {
        withCreateSlots($this->creator);

        expect(creating(['proofType' => ProofType::ImageApproval])->proof_is_public)->toBeFalse();
    });
});

describe('the create-slot', function () {
    it('spends one, recorded against the challenge it went on', function () {
        withCreateSlots($this->creator, 2);

        $challenge = creating();

        $spent = Entitlement::query()->whereNotNull('consumed_at')->get();

        expect($spent)->toHaveCount(1)
            ->and($spent->first()?->challenge_id)->toBe($challenge->getKey())
            ->and($this->slots->available($this->creator, EntitlementType::CreateSlot))->toBe(1);
    });

    it('refuses a creator with no slot left', function () {
        expect(fn () => creating())->toThrow(NoEntitlementAvailableException::class);
    });

    it('writes nothing at all when there is no slot to pay for it', function () {
        // The rollback that matters. The row is written *before* the spend, because
        // a spend is recorded against a challenge — so a failure here has to take
        // the row and its timeline back out with it.
        try {
            creating();
        } catch (NoEntitlementAvailableException) {
            // Expected.
        }

        expect(Challenge::query()->count())->toBe(0)
            ->and(ChallengePeriod::query()->count())->toBe(0);
    });

    it('does not touch a join-slot', function () {
        Entitlement::factory()->joinSlot()->create(['user_id' => $this->creator->getKey()]);

        expect(fn () => creating())->toThrow(NoEntitlementAvailableException::class)
            ->and($this->slots->available($this->creator, EntitlementType::JoinSlot))->toBe(1);
    });
});

describe('the timeline', function () {
    it('materialises one period per declared period', function () {
        withCreateSlots($this->creator);

        $challenge = creating(['totalPeriods' => 7]);

        expect($challenge->periods()->count())->toBe(7)
            ->and($challenge->periods()->pluck('index')->all())->toBe(range(0, 6));
    });

    it('opens the first period at the instant the creator picked', function () {
        withCreateSlots($this->creator);

        $startsAt = CarbonImmutable::create(2099, 6, 1, 0, 0, 0, 'Asia/Tehran');
        $challenge = creating(['startsAt' => $startsAt, 'timezone' => 'Asia/Tehran']);

        expect($challenge->periods()->first()?->starts_at->equalTo($startsAt))->toBeTrue();
    });

    it('materialises a timeline that runs past 2038', function () {
        withCreateSlots($this->creator);

        // MySQL's TIMESTAMP stops at 2038-01-19, and the timeline is the one set of
        // dates a user picks freely — a yearly challenge of any length walks past
        // it. `challenges.starts_at` and the period boundaries are DATETIME for
        // exactly this, and nothing else in the app would notice if that regressed.
        $challenge = creating([
            'periodType' => PeriodType::Yearly,
            'startsAt' => CarbonImmutable::create(2099, 1, 1, 0, 0, 0, 'UTC'),
            'totalPeriods' => 5,
        ]);

        expect($challenge->periods()->count())->toBe(5)
            // `periods()` is ordered by `index`, so the last row is the last period.
            ->and($challenge->periods()->get()->last()?->ends_at->year)->toBe(2104);
    });
});

describe('the announcement', function () {
    it('queues a post for a public challenge', function () {
        withCreateSlots($this->creator);

        $challenge = creating(['visibility' => ChallengeVisibility::Public]);

        Bus::assertDispatched(
            AnnounceChallenge::class,
            fn (AnnounceChallenge $job): bool => $job->challenge->is($challenge),
        );
    });

    it('queues nothing for an invite-only challenge', function () {
        withCreateSlots($this->creator);

        creating(['visibility' => ChallengeVisibility::InviteOnly]);

        Bus::assertNotDispatched(AnnounceChallenge::class);
    });

    it('leaves a public challenge awaiting its post until the job runs', function () {
        withCreateSlots($this->creator);

        $challenge = creating(['visibility' => ChallengeVisibility::Public]);

        // `announced_at` is the idempotency key, and it is the *broadcaster* that
        // claims it. Stamping it here would mean a failed post could never be
        // retried and nobody would ever see the challenge.
        expect($challenge->announced_at)->toBeNull()
            ->and($challenge->awaitsAnnouncement())->toBeTrue();
    });
});

describe('quantity scoring', function () {
    it('records the whole scoring block, defaulting the strategy it never asks for', function () {
        withCreateSlots($this->creator);

        $challenge = creating([
            'scoringType' => ScoringType::Quantity,
            'targetValue' => 30,
            'unitLabel' => 'pushups',
            'basePoints' => 100,
        ]);

        // `proportional` is the only strategy, so the wizard never offers a
        // choice of one — but the column still records which arithmetic scored
        // a period, which is the point of storing a strategy at all.
        expect($challenge->scoring_type)->toBe(ScoringType::Quantity)
            ->and((float) $challenge->target_value)->toBe(30.0)
            ->and($challenge->unit_label)->toBe('pushups')
            ->and($challenge->scoring_strategy)->toBe(ScoringStrategy::Proportional)
            ->and((float) $challenge->base_points)->toBe(100.0)
            ->and($challenge->quantity_partial_counts_as_done)->toBeFalse();
    });

    it('takes the opt-in that a below-target report still counts as done', function () {
        withCreateSlots($this->creator);

        expect(creating([
            'scoringType' => ScoringType::Quantity,
            'targetValue' => 30,
            'unitLabel' => 'pushups',
            'basePoints' => 100,
            'quantityPartialCountsAsDone' => true,
        ])->quantity_partial_counts_as_done)->toBeTrue();
    });

    it('leaves a binary challenge exactly as challenges were, scoring columns empty', function () {
        withCreateSlots($this->creator);

        // The regression bar for the whole phase: an ordinary challenge must
        // not grow half a scoring block it never asked for.
        $challenge = creating();

        expect($challenge->scoring_type)->toBe(ScoringType::Binary)
            ->and($challenge->target_value)->toBeNull()
            ->and($challenge->unit_label)->toBeNull()
            ->and($challenge->scoring_strategy)->toBeNull()
            ->and($challenge->base_points)->toBeNull()
            ->and($challenge->quantity_partial_counts_as_done)->toBeFalse();
    });

    it('refuses a quantity challenge missing any part of its configuration', function (array $overrides, string $because) {
        withCreateSlots($this->creator);

        expect(fn () => creating(array_replace([
            'scoringType' => ScoringType::Quantity,
            'targetValue' => 30,
            'unitLabel' => 'pushups',
            'basePoints' => 100,
        ], $overrides)))->toThrow(InvalidArgumentException::class, $because);
    })->with([
        'no target' => [['targetValue' => null], 'needs a positive target_value'],
        'no unit label' => [['unitLabel' => null], 'needs a unit label'],
        'a blank unit label' => [['unitLabel' => '   '], 'needs a unit label'],
        'no base points' => [['basePoints' => null], 'needs a positive base_points'],
    ]);

    it('refuses a non-positive target or base points', function (array $overrides, string $because) {
        withCreateSlots($this->creator);

        expect(fn () => creating(array_replace([
            'scoringType' => ScoringType::Quantity,
            'targetValue' => 30,
            'unitLabel' => 'pushups',
            'basePoints' => 100,
        ], $overrides)))->toThrow(InvalidArgumentException::class, $because);
    })->with([
        'a zero target' => [['targetValue' => 0], 'needs a positive target_value'],
        'a negative target' => [['targetValue' => -5], 'needs a positive target_value'],
        'zero base points' => [['basePoints' => 0], 'needs a positive base_points'],
        'negative base points' => [['basePoints' => -1], 'needs a positive base_points'],
    ]);

    it('refuses scoring configuration on a binary challenge', function () {
        withCreateSlots($this->creator);

        // Forbidden, not ignored: a target on a binary challenge is a caller
        // bug, and storing it would leave a half-configured scoring block
        // waiting to surprise whoever flips the type later.
        expect(fn () => creating(['targetValue' => 30]))->toThrow(
            InvalidArgumentException::class,
            'takes no scoring configuration',
        );
    });

    it('builds quantity challenges from the factory with the pushup defaults', function () {
        // The factory state Task 2/3/4 tests build on: legible arithmetic —
        // half the target is half the score, double is double.
        $challenge = Challenge::factory()->quantity()->make();

        expect($challenge->scoring_type)->toBe(ScoringType::Quantity)
            ->and((float) $challenge->target_value)->toBe(30.0)
            ->and($challenge->unit_label)->toBe('pushups')
            ->and($challenge->scoring_strategy)->toBe(ScoringStrategy::Proportional)
            ->and((float) $challenge->base_points)->toBe(100.0)
            ->and($challenge->quantity_partial_counts_as_done)->toBeFalse()
            // And the factory's ordinary rows stay binary.
            ->and(Challenge::factory()->make()->scoring_type)->toBe(ScoringType::Binary);
    });
});

describe('what it refuses', function () {
    it('refuses an incoherent challenge', function (array $overrides, string $because) {
        withCreateSlots($this->creator);

        expect(fn () => creating($overrides))->toThrow(InvalidArgumentException::class, $because);
    })->with([
        'no title' => [['title' => '   '], 'needs a title'],
        'a title past the limit' => [['title' => str_repeat('x', 121)], 'may not exceed 120'],
        'a description past the limit' => [['description' => str_repeat('y', 1001)], 'may not exceed 1000'],
        'no periods at all' => [['totalPeriods' => 0], 'between 1 and 1000'],
        'more periods than we allow' => [['totalPeriods' => 1001], 'between 1 and 1000'],
        'a custom period with no day count' => [
            ['periodType' => PeriodType::Custom, 'customPeriodDays' => null],
            'needs custom_period_days',
        ],
        'a custom period of no days' => [
            ['periodType' => PeriodType::Custom, 'customPeriodDays' => 0],
            'needs custom_period_days',
        ],
        'a custom period longer than a year' => [
            ['periodType' => PeriodType::Custom, 'customPeriodDays' => 366],
            'needs custom_period_days',
        ],
        'a timezone that does not exist' => [['timezone' => 'Mars/Olympus'], 'not a known timezone'],
    ]);

    it('spends no slot on a challenge it refused', function () {
        withCreateSlots($this->creator);

        try {
            creating(['title' => '']);
        } catch (InvalidArgumentException) {
            // Expected.
        }

        // Validation runs before the transaction, so this is really asserting the
        // order: a refusal must not cost the creator the slot they would have spent.
        expect($this->slots->available($this->creator, EntitlementType::CreateSlot))->toBe(1)
            ->and(Challenge::query()->count())->toBe(0);
    });

    it('counts a title in characters, not bytes', function () {
        withCreateSlots($this->creator);

        // 120 Farsi characters is 240 bytes. Counting bytes would cut a Farsi title
        // at half the length of an English one, which is the whole reason the bound
        // is `mb_strlen`.
        $farsi = str_repeat('ک', 120);

        expect(creating(['title' => $farsi])->title)->toBe($farsi);
    });

    it('accepts the limits it publishes', function () {
        withCreateSlots($this->creator);

        // The surfaces validate against `limits()` before asking the user twice, so
        // a bound that disagreed with the one enforced here would show a user a
        // rule that then refuses their input anyway.
        $limits = CreateChallenge::limits();

        $challenge = creating([
            'title' => str_repeat('x', $limits['title_max']),
            'description' => str_repeat('y', $limits['description_max']),
            'periodType' => PeriodType::Custom,
            'customPeriodDays' => $limits['custom_period_days_max'],
            'totalPeriods' => 1,
        ]);

        expect($challenge->exists)->toBeTrue();
    });
});
