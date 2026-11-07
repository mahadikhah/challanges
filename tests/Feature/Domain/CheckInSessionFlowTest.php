<?php

use App\Actions\Challenges\MaterialiseChallengePeriods;
use App\Actions\CheckIns\AdvanceCheckInStep;
use App\Actions\CheckIns\CompleteCheckInSession;
use App\Actions\CheckIns\ExpireStaleCheckInSessions;
use App\Actions\CheckIns\OpenCheckIn;
use App\Actions\CheckIns\SettleCheckIn;
use App\Actions\CheckIns\StartCheckInSession;
use App\Enums\CheckInSessionStatus;
use App\Enums\CheckInStatus;
use App\Enums\SessionRejection;
use App\Exceptions\SessionRejectedException;
use App\Models\Challenge;
use App\Models\ChallengeParticipant;
use App\Models\ChallengePeriod;
use App\Models\ChallengeStep;
use App\Models\CheckIn;
use App\Models\CheckInSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * The timed session as a way of *arriving* at a settlement: start → gated
 * steps → complete, feeding the existing `SettleCheckIn` untouched. What these
 * tests pin down is that the session never becomes a second engine — its
 * completion produces exactly the streak effects a tap would — and that every
 * refusal carries a reason a surface can turn into a specific sentence.
 */

beforeEach(function () {
    $this->creator = User::factory()->telegram()->create();

    $this->challenge = Challenge::factory()
        ->active()
        ->timedSession()
        ->timeline(now()->subDay()->startOfDay()->toDateTimeString(), 'UTC', 30)
        ->create(['creator_id' => $this->creator->getKey()]);

    // The timeline is a property of the challenge, materialised once — the
    // factory does not build it, so the same action that will build every
    // real challenge's timeline builds this one.
    app(MaterialiseChallengePeriods::class)->handle($this->challenge);

    // A three-step design: tap → photo → voice. Wait sums are tiny so tests
    // travel time by argument rather than sleep.
    $this->steps = collect([
        ChallengeStep::factory()->for($this->challenge)->atOrder(1)->waiting(60)->create(),
        ChallengeStep::factory()->for($this->challenge)->atOrder(2)->waiting(120)->image()->create(),
        ChallengeStep::factory()->for($this->challenge)->atOrder(3)->waiting(60)->voice(30)->create(),
    ]);

    $this->participant = ChallengeParticipant::factory()->create([
        'challenge_id' => $this->challenge->getKey(),
    ]);

    /** @var ChallengePeriod $period */
    $period = $this->challenge->periods()->where('index', 1)->firstOrFail();
    $this->period = $period;

    // To the whole second: waits are compared with whole-second diffs, and a
    // microsecond tail in the anchor would make every "exactly 30 left" read
    // as 29.
    $this->now = CarbonImmutable::now('UTC')->startOfSecond();
});

/**
 * The session waiting on the given step, started at the given instant.
 */
function openSessionOn(ChallengeParticipant $participant, ChallengePeriod $period, int $stepOrder, ?CarbonInterface $startedAt = null): CheckInSession
{
    return CheckInSession::factory()
        ->for($participant, 'participant')
        ->startedAt($startedAt ?? test()->now->subMinutes(10))
        ->create([
            'challenge_period_id' => $period->getKey(),
            'current_step_order' => $stepOrder,
        ]);
}

/**
 * Advance one step, defaulting to the test's clock.
 *
 * @param  array{proof_path?: string|null, voice_seconds?: int|null}  $submission
 */
function advancing(CheckInSession $session, ChallengeStep $step, array $submission = [], ?CarbonInterface $at = null): CheckInSession
{
    return app(AdvanceCheckInStep::class)->handle($session, $step, $submission, $at ?? test()->now);
}

describe('starting a session', function () {
    it('opens a session on the first step of a timed challenge', function () {
        $session = app(StartCheckInSession::class)->handle($this->participant, $this->period, $this->now);

        expect($session->status)->toBe(CheckInSessionStatus::InProgress)
            ->and($session->current_step_order)->toBe(1)
            ->and($session->challenge_period_id)->toBe($this->period->getKey());
    });

    it('returns the existing session on a double start rather than a second one', function () {
        $first = app(StartCheckInSession::class)->handle($this->participant, $this->period, $this->now);
        $second = app(StartCheckInSession::class)->handle($this->participant, $this->period, $this->now);

        expect($second->getKey())->toBe($first->getKey())
            ->and(CheckInSession::query()->count())->toBe(1);
    });

    it('refuses a challenge that is not a timed one', function () {
        $challenge = Challenge::factory()->active()
            ->timeline(now()->subDay()->startOfDay()->toDateTimeString(), 'UTC', 30)
            ->create();
        app(MaterialiseChallengePeriods::class)->handle($challenge);

        $participant = ChallengeParticipant::factory()->create(['challenge_id' => $challenge->getKey()]);
        $period = $challenge->periods()->where('index', 1)->firstOrFail();

        app(StartCheckInSession::class)->handle($participant, $period, $this->now);
    })->throws(SessionRejectedException::class);

    it('refuses a start when the period is not open', function () {
        app(StartCheckInSession::class)->handle($this->participant, $this->period, $this->now->addDays(3));
    })->throws(SessionRejectedException::class);

    it('refuses a start when the period is already settled', function () {
        $checkIn = CheckIn::factory()->on($this->participant, $this->period)->create();
        $checkIn->update(['status' => CheckInStatus::Approved]);

        app(StartCheckInSession::class)->handle($this->participant, $this->period, $this->now);
    })->throws(SessionRejectedException::class);
});

describe('advancing through the steps', function () {
    it('rejects an early tap with the exact seconds remaining', function () {
        // Started 30 seconds ago; the first gate opens at 60 — 30 to go.
        $session = openSessionOn($this->participant, $this->period, 1, $this->now->subSeconds(30));

        try {
            advancing($session, $this->steps[0]);
            $this->fail('An early tap should have been refused.');
        } catch (SessionRejectedException $exception) {
            expect($exception->reason)->toBe(SessionRejection::TooEarly)
                ->and($exception->remainingSeconds)->toBe(30);
        }

        expect($session->fresh()->current_step_order)->toBe(1)
            ->and($session->submissions()->count())->toBe(0);
    });

    it('advances in order when each wait has elapsed', function () {
        $session = openSessionOn($this->participant, $this->period, 1, $this->now->subMinutes(10));

        $session = advancing($session, $this->steps[0], at: $this->now);
        expect($session->fresh()->current_step_order)->toBe(2);

        // Step 2's gate opens 120s after step 1's submission.
        $session = advancing($session, $this->steps[1], ['proof_path' => 'steps/2.jpg'], $this->now->addMinutes(3));
        expect($session->fresh()->current_step_order)->toBe(3);

        // Step 3's gate opens 60s after step 2's submission.
        $session = advancing($session, $this->steps[2], ['proof_path' => 'steps/3.ogg', 'voice_seconds' => 20], $this->now->addMinutes(5));

        expect($session->fresh()->status)->toBe(CheckInSessionStatus::Completed)
            ->and($session->submissions()->count())->toBe(3);
    });

    it('measures each wait from the previous step, not from the start', function () {
        // Step 2 submitted 30 seconds ago; step 3's 60-second gate has 30 left.
        $session = openSessionOn($this->participant, $this->period, 3, $this->now->subMinutes(10));

        $session->submissions()->create([
            'challenge_step_id' => $this->steps[1]->getKey(),
            'submitted_at' => $this->now->subSeconds(30),
            'proof_path' => 'steps/2.jpg',
        ]);

        try {
            advancing($session, $this->steps[2]);
            $this->fail('A step submitted before its gate opened should have been refused.');
        } catch (SessionRejectedException $exception) {
            expect($exception->reason)->toBe(SessionRejection::TooEarly)
                ->and($exception->remainingSeconds)->toBe(30);
        }
    });

    it('rejects a step that is not the session\'s current step', function () {
        $session = openSessionOn($this->participant, $this->period, 1);

        // Replayed step-3 callback data while the session sits on step 1.
        advancing($session, $this->steps[2]);
    })->throws(SessionRejectedException::class);

    it('rejects a voice message past the step cap without recording a submission', function () {
        $session = openSessionOn($this->participant, $this->period, 3);

        try {
            advancing($session, $this->steps[2], ['proof_path' => 'steps/3.ogg', 'voice_seconds' => 45]);
            $this->fail('An over-long voice message should have been refused.');
        } catch (SessionRejectedException $exception) {
            expect($exception->reason)->toBe(SessionRejection::VoiceTooLong)
                ->and($exception->voiceSeconds)->toBe(45)
                ->and($exception->voiceCeiling)->toBe(30);
        }

        expect($session->submissions()->count())->toBe(0)
            ->and($session->fresh()->current_step_order)->toBe(3);
    });

    it('rejects media riding on a bare-tap step', function () {
        $session = openSessionOn($this->participant, $this->period, 1);

        advancing($session, $this->steps[0], ['proof_path' => 'steps/x.jpg']);
    })->throws(SessionRejectedException::class);

    it('demands media on an image step', function () {
        $session = openSessionOn($this->participant, $this->period, 2);

        advancing($session, $this->steps[1]);
    })->throws(SessionRejectedException::class);

    it('rejects anything on a session that is no longer open', function () {
        $session = openSessionOn($this->participant, $this->period, 1);
        $session->update(['status' => CheckInSessionStatus::Expired]);

        advancing($session->fresh(), $this->steps[0]);
    })->throws(SessionRejectedException::class);
});

describe('completing a session', function () {
    it('settles the period through the existing engine with the same effects as a tap', function () {
        $session = openSessionOn($this->participant, $this->period, 1, $this->now->subMinutes(10));

        $session = advancing($session, $this->steps[0], at: $this->now);
        $session = advancing($session, $this->steps[1], ['proof_path' => 'steps/2.jpg'], $this->now->addMinutes(3));
        $session = advancing($session, $this->steps[2], ['proof_path' => 'steps/3.ogg', 'voice_seconds' => 20], $this->now->addMinutes(5));

        $fresh = $session->fresh();

        expect($fresh->status)->toBe(CheckInSessionStatus::Completed)
            ->and($fresh->completed_at)->not->toBeNull()
            ->and($fresh->current_step_order)->toBeNull();

        $checkIn = CheckIn::query()
            ->where('challenge_participant_id', $this->participant->getKey())
            ->where('challenge_period_id', $this->period->getKey())
            ->firstOrFail();

        // The streak moved by exactly one, as a settled tap would have moved it.
        expect($checkIn->status)->toBe(CheckInStatus::Approved)
            ->and($this->participant->fresh()->current_streak)->toBe(1);
    });

    it('refuses to complete a session that is no longer open', function () {
        $session = openSessionOn($this->participant, $this->period, 1);
        $session->update(['status' => CheckInSessionStatus::Expired]);

        app(CompleteCheckInSession::class)->handle($session->fresh());
    })->throws(SessionRejectedException::class);
});

describe('the expiry sweep', function () {
    it('expires open sessions in closed periods and leaves everything else alone', function () {
        $stale = openSessionOn($this->participant, $this->period, 2);

        // An open session in a period still to come: must survive.
        $live = openSessionOn($this->participant, $this->challenge->periods()->where('index', 2)->firstOrFail(), 1);

        // Close the stale session's period under it.
        $this->period->update(['ends_at' => now()->subMinute()]);

        $expired = app(ExpireStaleCheckInSessions::class)->handle();

        expect($stale->fresh()->status)->toBe(CheckInSessionStatus::Expired)
            ->and($stale->fresh()->current_step_order)->toBeNull()
            ->and($live->fresh()->status)->toBe(CheckInSessionStatus::InProgress)
            ->and($expired->pluck('id'))->toContain($stale->getKey());
    });

    it('leaves the miss decision to the rollover, swept or not', function () {
        // The confirmation the task asks for: with NO sweep having run, an
        // in-progress session does not count as a success. A freeze is
        // available, so the period closes as frozen — exactly as an
        // un-submitted simple check-in would.
        openSessionOn($this->participant, $this->period, 2);

        $checkIn = app(OpenCheckIn::class)->handle($this->participant, $this->period);
        $settled = app(SettleCheckIn::class)->close($checkIn);

        expect($settled->status)->toBe(CheckInStatus::Frozen)
            ->and($this->participant->fresh()->freezes_used)->toBe(1)
            ->and($this->participant->fresh()->current_streak)->toBe(0);

        // And the sweep afterwards is still only bookkeeping.
        $this->period->update(['ends_at' => now()->subMinute()]);
        app(ExpireStaleCheckInSessions::class)->handle();

        expect($settled->fresh()->status)->toBe(CheckInStatus::Frozen);
    });

    it('runs as the tail of the rollover command', function () {
        openSessionOn($this->participant, $this->period, 1);
        $this->period->update(['ends_at' => now()->subMinute()]);

        $this->artisan('challenges:roll-over')->assertSuccessful();

        expect(CheckInSession::query()->where('status', CheckInSessionStatus::InProgress)->count())->toBe(0);
    });
});
