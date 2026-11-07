<?php

use App\Actions\Challenges\CreateChallenge;
use App\Actions\Challenges\ValidateChallengeStepDesign;
use App\Enums\ChallengeVisibility;
use App\Enums\CheckInSessionStatus;
use App\Enums\FlowType;
use App\Enums\PeriodType;
use App\Enums\ProofType;
use App\Enums\StepInputType;
use App\Jobs\Challenges\AnnounceChallenge;
use App\Models\Challenge;
use App\Models\ChallengeParticipant;
use App\Models\ChallengePeriod;
use App\Models\ChallengeStep;
use App\Models\CheckInSession;
use App\Models\Entitlement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\ChallengeStepFactory;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

/*
 * Design-time validation for timed sessions: a step list whose minimum total
 * wait overruns one period of the challenge is refused *before* anything is
 * spent on it. The period's length always comes from the materialiser — the
 * same one that will build the real timeline — so no test hardcodes
 * seconds-per-period and the validator can never disagree with the timeline.
 */

beforeEach(function () {
    Bus::fake([AnnounceChallenge::class]);

    $this->validator = app(ValidateChallengeStepDesign::class);
    $this->creator = User::factory()->telegram()->create();
});

/**
 * One period's length in seconds, from the same arithmetic the timeline uses.
 */
function periodSeconds(PeriodType $type, ?int $customDays = null): int
{
    return test()->validator->periodSeconds($type, $customDays, CarbonImmutable::now('UTC')->addDay()->startOfDay(), 'UTC');
}

/**
 * A proposed design as the validator takes it.
 *
 * @param  list<array{input_type: StepInputType, min_wait_seconds: int, voice_max_seconds?: int|null, label?: string}>|null  $steps
 */
function validating(PeriodType $type, ?array $steps, ?int $customDays = null): void
{
    test()->validator->handle(
        $type,
        $customDays,
        CarbonImmutable::now('UTC')->addDay()->startOfDay(),
        'UTC',
        $steps ?? [],
    );
}

/**
 * Create a timed-session challenge, naming only what the test is about.
 *
 * @param  list<array{input_type: StepInputType, min_wait_seconds: int, voice_max_seconds?: int|null, label?: string}>  $steps
 */
function creatingTimed(PeriodType $type, array $steps, ?int $customDays = null)
{
    Entitlement::factory()->createSlot()->create(['user_id' => test()->creator->getKey()]);

    return app(CreateChallenge::class)->handle(
        test()->creator,
        'Morning pages',
        null,
        $type,
        $customDays,
        CarbonImmutable::now('UTC')->addDay()->startOfDay(),
        10,
        'UTC',
        ProofType::Button,
        ChallengeVisibility::InviteOnly,
        false,
        null,
        FlowType::TimedSession,
        $steps,
    );
}

describe('the design validator', function () {
    it('accepts a step list whose waits fit inside one period of every period type', function (PeriodType $type, ?int $customDays) {
        validating($type, ChallengeStepFactory::design(periodSeconds($type, $customDays) - 60), $customDays);

        expect(true)->toBeTrue();
    })->with([
        'daily' => [PeriodType::Daily, null],
        'weekly' => [PeriodType::Weekly, null],
        'monthly' => [PeriodType::Monthly, null],
        'seasonal' => [PeriodType::Seasonal, null],
        'yearly' => [PeriodType::Yearly, null],
        'custom' => [PeriodType::Custom, 21],
    ]);

    it('rejects a step list whose waits overrun one period of every period type, naming the excess', function (PeriodType $type, ?int $customDays) {
        $overrun = 600;
        $design = ChallengeStepFactory::design(periodSeconds($type, $customDays) + $overrun);

        expect(fn () => validating($type, $design, $customDays))
            ->toThrow(InvalidArgumentException::class, "{$overrun} seconds too many");
    })->with([
        'daily' => [PeriodType::Daily, null],
        'weekly' => [PeriodType::Weekly, null],
        'monthly' => [PeriodType::Monthly, null],
        'seasonal' => [PeriodType::Seasonal, null],
        'yearly' => [PeriodType::Yearly, null],
        'custom' => [PeriodType::Custom, 21],
    ]);

    it('refuses a timed-session challenge with no steps at all', function () {
        expect(fn () => validating(PeriodType::Daily, []))
            ->toThrow(InvalidArgumentException::class, 'at least one step');
    });

    it('requires a voice cap on a voice step and refuses one anywhere else', function () {
        validating(PeriodType::Daily, [
            ['input_type' => StepInputType::Voice, 'min_wait_seconds' => 60, 'voice_max_seconds' => null],
        ]);
    })->throws(InvalidArgumentException::class, 'voice step needs a voice_max_seconds');

    it('rejects a voice cap set on a button or image step', function (StepInputType $type) {
        validating(PeriodType::Daily, [
            ['input_type' => $type, 'min_wait_seconds' => 60, 'voice_max_seconds' => 30],
        ]);
    })->throws(InvalidArgumentException::class, 'only allowed on a voice step')->with([
        'button' => [StepInputType::Button],
        'image' => [StepInputType::Image],
    ]);

    it('rejects a negative wait outright', function () {
        validating(PeriodType::Daily, [
            ['input_type' => StepInputType::Button, 'min_wait_seconds' => -1],
        ]);
    })->throws(InvalidArgumentException::class, 'may not be negative');
});

describe('creation through CreateChallenge', function () {
    it('persists the step rows with their order when the design fits', function () {
        $design = ChallengeStepFactory::design(3600);

        $challenge = creatingTimed(PeriodType::Daily, $design);

        expect($challenge->flow_type)->toBe(FlowType::TimedSession)
            ->and($challenge->steps)->toHaveCount(2)
            ->and($challenge->steps->pluck('step_order')->all())->toBe([1, 2])
            ->and($challenge->steps->get(1)->input_type)->toBe(StepInputType::Voice)
            ->and($challenge->steps->get(1)->voice_max_seconds)->toBe(90)
            ->and($challenge->steps->get(0)->voice_max_seconds)->toBeNull();
    });

    it('refuses an overrunning design without spending a slot or creating anything', function () {
        $overrun = 3600;

        try {
            creatingTimed(PeriodType::Daily, ChallengeStepFactory::design(periodSeconds(PeriodType::Daily) + $overrun));
        } catch (InvalidArgumentException) {
            // Expected; assertions below are the point.
        }

        expect(ChallengeStep::query()->exists())->toBeFalse()
            ->and(Challenge::query()->exists())->toBeFalse()
            ->and(Entitlement::query()->where('consumed_at', '!=', null)->exists())->toBeFalse();
    });

    it('leaves simple challenges exactly as they were', function () {
        Entitlement::factory()->createSlot()->create(['user_id' => $this->creator->getKey()]);

        $challenge = app(CreateChallenge::class)->handle(
            $this->creator,
            'Plain old challenge',
            null,
            PeriodType::Daily,
            null,
            CarbonImmutable::now('UTC')->addDay()->startOfDay(),
            10,
            'UTC',
            ProofType::Button,
            ChallengeVisibility::InviteOnly,
        );

        expect($challenge->flow_type)->toBe(FlowType::Simple)
            ->and($challenge->steps)->toBeEmpty();
    });
});

describe('the one-open-session constraint', function () {
    it('permits a second session for the same pair only after the first leaves in_progress', function () {
        $participant = ChallengeParticipant::factory()->create();
        $period = ChallengePeriod::factory()->for($participant->challenge)->atIndex(0)->create();

        CheckInSession::factory()
            ->for($participant, 'participant')
            ->create(['challenge_period_id' => $period->getKey()]);

        // The unique index rides a generated `open` column: 1 while
        // in_progress, NULL otherwise, and MySQL ignores NULLs. A second open
        // row for the same pair must violate it...
        expect(fn () => CheckInSession::factory()
            ->for($participant, 'participant')
            ->create(['challenge_period_id' => $period->getKey()]))
            ->toThrow(UniqueConstraintViolationException::class);

        // ...while the same participant may open one in the *next* period...
        $next = ChallengePeriod::factory()->for($participant->challenge)->atIndex(1)->create();
        CheckInSession::factory()->for($participant, 'participant')->create(['challenge_period_id' => $next->getKey()]);

        // ...and may open another here the moment the first is finished with.
        CheckInSession::factory()->expired()->for($participant, 'participant')
            ->create(['challenge_period_id' => $period->getKey()]);

        expect(CheckInSession::query()
            ->where('challenge_participant_id', $participant->getKey())
            ->where('challenge_period_id', $period->getKey())
            ->where('status', CheckInSessionStatus::InProgress)
            ->count())->toBe(1);
    });
});
