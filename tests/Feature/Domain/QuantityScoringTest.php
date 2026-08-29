<?php

use App\Actions\CheckIns\SettleCheckIn;
use App\Enums\CheckInStatus;
use App\Enums\ScoringStrategy;
use App\Models\Challenge;
use App\Models\ChallengeParticipant;
use App\Models\ChallengePeriod;
use App\Models\CheckIn;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Quantity scoring, end to end: the strategy's arithmetic as a pure function,
 * then its wiring into the settlement engine — which statuses a report can
 * earn, what lands on the row, and that `total_score` moves under the same
 * lock the streak does. The binary regression bar is the rest of the Domain
 * suite passing unchanged, not a test here.
 */

beforeEach(function () {
    $this->settle = app(SettleCheckIn::class);
    $this->challenge = Challenge::factory()->active()->quantity()->create();
});

/**
 * A pending obligation on the quantity challenge.
 */
function quantityObligation(Challenge $challenge, int $index = 0): CheckIn
{
    $participant = ChallengeParticipant::factory()->for($challenge)->create();

    return CheckIn::factory()->on($participant, quantityPeriod($challenge, $index))->create();
}

/**
 * A still-open period on the quantity challenge's timeline.
 */
function quantityPeriod(Challenge $challenge, int $index = 0): ChallengePeriod
{
    return ChallengePeriod::factory()->for($challenge)->atIndex($index)->create([
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHour(),
    ]);
}

describe('the proportional strategy', function () {
    it('scores reported against target', function (int|float $target, int|float $base, int|float $reported, int $score) {
        expect(ScoringStrategy::Proportional->score($target, $base, $reported))->toBe($score);
    })->with([
        'exactly the target earns the base' => [30, 100, 30, 100],
        'above the target earns above the base, uncapped' => [30, 100, 60, 200],
        'halfway earns half' => [30, 100, 15, 50],
        'a third earns a rounded third' => [30, 100, 10, 33],
        'nothing earns nothing' => [30, 100, 0, 0],
        'plank seconds, not pushups' => [60, 50, 45, 38],
        'fractional reports round' => [30, 100, 20.4, 68],
    ]);

    it('refuses a target of zero rather than dividing by it', function () {
        expect(fn () => ScoringStrategy::Proportional->score(0, 100, 30))
            ->toThrow(InvalidArgumentException::class, 'positive target_value');
    });
});

describe('a report that clears the bar', function () {
    it('settles as done with the full score and moves the total', function () {
        $checkIn = quantityObligation($this->challenge);

        $this->settle->approve($checkIn, 30);

        $participant = $checkIn->participant->refresh();

        expect($checkIn->status)->toBe(CheckInStatus::Approved)
            ->and((float) $checkIn->reported_value)->toBe(30.0)
            ->and((float) $checkIn->score)->toBe(100.0)
            ->and($participant->current_streak)->toBe(1)
            ->and((float) $participant->total_score)->toBe(100.0);
    });

    it('awards the uncapped bonus above the target', function () {
        $checkIn = quantityObligation($this->challenge);

        $this->settle->approve($checkIn, 60);

        expect((float) $checkIn->score)->toBe(200.0)
            ->and((float) $checkIn->participant->refresh()->total_score)->toBe(200.0);
    });

    it('scores once however many times the settlement is replayed', function () {
        $checkIn = quantityObligation($this->challenge);

        $this->settle->approve($checkIn, 30);
        $this->settle->approve($checkIn, 30);

        // The status transition is the idempotency token — the score rides it.
        expect((float) $checkIn->participant->refresh()->total_score)->toBe(100.0);
    });
});

describe('a report that falls short', function () {
    it('is a miss by default: the freeze engine answers, and nothing scores', function () {
        $checkIn = quantityObligation($this->challenge);
        $checkIn->participant->forceFill(['freezes_total' => 1, 'freezes_used' => 0, 'current_streak' => 3])->save();

        $this->settle->approve($checkIn, 15);

        $participant = $checkIn->participant->refresh();

        // Exactly what an ordinary miss does: the freeze absorbs it, the streak
        // neither grows nor dies, and no score is recorded for a period that
        // was not done.
        expect($checkIn->status)->toBe(CheckInStatus::Frozen)
            ->and((float) $checkIn->reported_value)->toBe(15.0)
            ->and($checkIn->score)->toBeNull()
            ->and($participant->freezes_used)->toBe(1)
            ->and($participant->current_streak)->toBe(3)
            ->and((float) $participant->total_score)->toBe(0.0);
    });

    it('resets the streak when no freeze is left, like any miss', function () {
        $checkIn = quantityObligation($this->challenge);
        $checkIn->participant->forceFill(['current_streak' => 3, 'freezes_total' => 0, 'freezes_used' => 0])->save();

        $this->settle->approve($checkIn, 15);

        $participant = $checkIn->participant->refresh();

        expect($checkIn->status)->toBe(CheckInStatus::Missed)
            ->and($participant->current_streak)->toBe(0)
            ->and($participant->streak_resets_count)->toBe(1)
            ->and($checkIn->score)->toBeNull();
    });

    it('keeps the streak alive at the lower score when the creator opted in', function () {
        $this->challenge->forceFill(['quantity_partial_counts_as_done' => true])->save();

        $checkIn = quantityObligation($this->challenge);

        $this->settle->approve($checkIn, 15);

        $participant = $checkIn->participant->refresh();

        expect($checkIn->status)->toBe(CheckInStatus::Approved)
            ->and((float) $checkIn->score)->toBe(50.0)
            ->and($participant->current_streak)->toBe(1)
            ->and((float) $participant->total_score)->toBe(50.0);
    });
});

describe('a binary challenge', function () {
    it('settles exactly as it always did, scoring nothing', function () {
        $challenge = Challenge::factory()->active()->create();
        $participant = ChallengeParticipant::factory()->for($challenge)->create();
        $period = ChallengePeriod::factory()->for($challenge)->atIndex(0)->create([
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);
        $checkIn = CheckIn::factory()->on($participant, $period)->create();

        $this->settle->approve($checkIn);

        expect($checkIn->status)->toBe(CheckInStatus::Approved)
            ->and($checkIn->reported_value)->toBeNull()
            ->and($checkIn->score)->toBeNull()
            ->and((float) $participant->refresh()->total_score)->toBe(0.0);
    });
});
