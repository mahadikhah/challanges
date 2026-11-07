<?php

use App\Actions\Challenges\RollOverPeriod;
use App\Actions\CheckIns\SettleCheckIn;
use App\Enums\CheckInStatus;
use App\Enums\ParticipantStatus;
use App\Exceptions\PeriodNotEndedException;
use App\Models\Challenge;
use App\Models\ChallengeParticipant;
use App\Models\ChallengePeriod;
use App\Models\CheckIn;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->settle = app(SettleCheckIn::class);
    $this->rollOver = app(RollOverPeriod::class);
    $this->challenge = Challenge::factory()->active()->create();
});

/**
 * A period on the shared challenge's timeline that closed yesterday.
 */
function endedPeriod(Challenge $challenge, int $index = 0): ChallengePeriod
{
    return ChallengePeriod::factory()->for($challenge)->atIndex($index)->create([
        'starts_at' => now()->subDays(2),
        'ends_at' => now()->subDay(),
    ]);
}

/**
 * A period on the shared challenge's timeline that is still open.
 */
function openPeriod(Challenge $challenge, int $index = 0): ChallengePeriod
{
    return ChallengePeriod::factory()->for($challenge)->atIndex($index)->create([
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHour(),
    ]);
}

/**
 * A pending obligation for a participant and period.
 */
function obligation(ChallengeParticipant $participant, ChallengePeriod $period): CheckIn
{
    return CheckIn::factory()->on($participant, $period)->create();
}

describe('approving a check-in', function () {
    it('extends the streak', function () {
        $participant = ChallengeParticipant::factory()->for($this->challenge)->withStreak(4)->create();
        $checkIn = obligation($participant, openPeriod($this->challenge));

        $this->settle->approve($checkIn);

        expect($checkIn->status)->toBe(CheckInStatus::Approved)
            ->and($participant->refresh()->current_streak)->toBe(5)
            ->and($participant->longest_streak)->toBe(5);
    });

    it('leaves the record streak alone when the current one is still behind it', function () {
        $participant = ChallengeParticipant::factory()->for($this->challenge)->withStreak(2, 9)->create();

        $this->settle->approve(obligation($participant, openPeriod($this->challenge)));

        expect($participant->refresh()->current_streak)->toBe(3)
            ->and($participant->longest_streak)->toBe(9);
    });

    it('does not spend a freeze', function () {
        $participant = ChallengeParticipant::factory()->for($this->challenge)->withFreezes(2)->create();

        $this->settle->approve(obligation($participant, openPeriod($this->challenge)));

        expect($participant->refresh()->freezes_used)->toBe(0)
            ->and($participant->freezesRemaining())->toBe(2);
    });

    it('counts a period once however many times the approval arrives', function () {
        // A double-tapped button, or a webhook Telegram retried.
        $participant = ChallengeParticipant::factory()->for($this->challenge)->create();
        $checkIn = obligation($participant, openPeriod($this->challenge));

        $this->settle->approve($checkIn);
        $this->settle->approve($checkIn);
        $this->settle->approve($checkIn->fresh());

        expect($participant->refresh()->current_streak)->toBe(1);
    });

    it('builds a streak across consecutive periods', function () {
        $participant = ChallengeParticipant::factory()->for($this->challenge)->create();

        foreach (range(0, 4) as $index) {
            $this->settle->approve(obligation($participant, openPeriod($this->challenge, $index)));
        }

        expect($participant->refresh()->current_streak)->toBe(5)
            ->and($participant->longest_streak)->toBe(5);
    });

    it('reports the settled status rather than silently failing on a late review', function () {
        // The rollover already closed this period. A creator approving the photo
        // afterwards must be told, not shown a success message.
        $participant = ChallengeParticipant::factory()->for($this->challenge)->withoutFreezes()->create();
        $missed = CheckIn::factory()->on($participant, endedPeriod($this->challenge))->missed()->create();

        $result = $this->settle->approve($missed);

        expect($result->status)->toBe(CheckInStatus::Missed)
            ->and($participant->refresh()->current_streak)->toBe(0);
    });

    it('settles a rejected photo when a better one is approved', function () {
        // `Rejected` is not an ending — resubmission is allowed until the period
        // closes, so the streak is still winnable.
        $participant = ChallengeParticipant::factory()->for($this->challenge)->withStreak(3)->create();
        $rejected = CheckIn::factory()->on($participant, openPeriod($this->challenge))->rejected()->create();

        $this->settle->approve($rejected);

        expect($rejected->status)->toBe(CheckInStatus::Approved)
            ->and($participant->refresh()->current_streak)->toBe(4);
    });
});

describe('closing a missed period', function () {
    it('spends a freeze and keeps the streak', function () {
        $participant = ChallengeParticipant::factory()->for($this->challenge)->withStreak(6)->withFreezes(1)->create();
        $checkIn = obligation($participant, endedPeriod($this->challenge));

        $this->settle->close($checkIn);

        expect($checkIn->status)->toBe(CheckInStatus::Frozen)
            ->and($participant->refresh()->current_streak)->toBe(6)
            ->and($participant->freezes_used)->toBe(1)
            ->and($participant->streak_resets_count)->toBe(0);
    });

    it('does not extend the streak when a freeze absorbs the miss', function () {
        // A freeze protects a streak; it does not earn one. The participant did not
        // do the thing.
        $participant = ChallengeParticipant::factory()->for($this->challenge)->withStreak(6)->withFreezes(1)->create();

        $this->settle->close(obligation($participant, endedPeriod($this->challenge)));

        expect($participant->refresh()->current_streak)->toBe(6)
            ->and($participant->longest_streak)->toBe(6);
    });

    it('resets the streak and counts the reset once the freezes are gone', function () {
        $participant = ChallengeParticipant::factory()->for($this->challenge)->withStreak(11)->withoutFreezes()->create();
        $checkIn = obligation($participant, endedPeriod($this->challenge));

        $this->settle->close($checkIn);

        expect($checkIn->status)->toBe(CheckInStatus::Missed)
            ->and($participant->refresh()->current_streak)->toBe(0)
            ->and($participant->streak_resets_count)->toBe(1);
    });

    it('keeps the record streak after a reset', function () {
        // The reset is to the current streak only. A personal best is history and
        // stays on the record.
        $participant = ChallengeParticipant::factory()->for($this->challenge)->withStreak(11)->withoutFreezes()->create();

        $this->settle->close(obligation($participant, endedPeriod($this->challenge)));

        expect($participant->refresh()->longest_streak)->toBe(11);
    });

    it('leaves the participant in the challenge after a reset', function () {
        // The settled decision: no auto-removal. They may have spent a friend's
        // invite on this slot, and burning it over one bad day is the wrong trade.
        $participant = ChallengeParticipant::factory()->for($this->challenge)->withoutFreezes()->create();

        $this->settle->close(obligation($participant, endedPeriod($this->challenge)));

        expect($participant->refresh()->status)->toBe(ParticipantStatus::Active)
            ->and($participant->status->owesCheckIns())->toBeTrue();
    });

    it('keeps counting resets so a stricter rule can be layered on later', function () {
        $participant = ChallengeParticipant::factory()->for($this->challenge)->withoutFreezes()->create();

        foreach (range(0, 2) as $index) {
            $this->settle->close(obligation($participant, endedPeriod($this->challenge, $index)));
        }

        expect($participant->refresh()->streak_resets_count)->toBe(3)
            ->and($participant->status)->toBe(ParticipantStatus::Active);
    });

    it('spends each freeze on a different period, then loses the streak', function () {
        // The freeze count is the whole budget for the challenge, not per period.
        $participant = ChallengeParticipant::factory()->for($this->challenge)->withStreak(4)->withFreezes(2)->create();

        $outcomes = collect(range(0, 2))
            ->map(fn (int $index): CheckInStatus => $this->settle->close(
                obligation($participant, endedPeriod($this->challenge, $index)),
            )->status);

        expect($outcomes->all())->toBe([CheckInStatus::Frozen, CheckInStatus::Frozen, CheckInStatus::Missed])
            ->and($participant->refresh()->freezes_used)->toBe(2)
            ->and($participant->freezesRemaining())->toBe(0)
            ->and($participant->current_streak)->toBe(0);
    });

    it('cannot spend one freeze on two periods', function () {
        // The availability check and the spend are one atomic step under the
        // participant lock, so the second close sees the freeze already gone.
        $participant = ChallengeParticipant::factory()->for($this->challenge)->withFreezes(1)->create();

        $first = $this->settle->close(obligation($participant, endedPeriod($this->challenge, 0)));
        $second = $this->settle->close(obligation($participant, endedPeriod($this->challenge, 1)));

        expect($first->status)->toBe(CheckInStatus::Frozen)
            ->and($second->status)->toBe(CheckInStatus::Missed)
            ->and($participant->refresh()->freezes_used)->toBe(1);
    });

    it('spends one freeze however many times the close arrives', function () {
        $participant = ChallengeParticipant::factory()->for($this->challenge)->withFreezes(3)->create();
        $checkIn = obligation($participant, endedPeriod($this->challenge));

        $this->settle->close($checkIn);
        $this->settle->close($checkIn);
        $this->settle->close($checkIn->fresh());

        expect($participant->refresh()->freezes_used)->toBe(1);
    });

    it('counts one reset however many times the close arrives', function () {
        $participant = ChallengeParticipant::factory()->for($this->challenge)->withStreak(5)->withoutFreezes()->create();
        $checkIn = obligation($participant, endedPeriod($this->challenge));

        $this->settle->close($checkIn);
        $this->settle->close($checkIn->fresh());

        expect($participant->refresh()->streak_resets_count)->toBe(1);
    });

    it('leaves an approved period alone', function () {
        // The rollover reaches every obligation, including ones already won.
        $participant = ChallengeParticipant::factory()->for($this->challenge)->withStreak(3)->withFreezes(1)->create();
        $approved = CheckIn::factory()->on($participant, endedPeriod($this->challenge))->approved()->create();

        $this->settle->close($approved);

        expect($approved->status)->toBe(CheckInStatus::Approved)
            ->and($participant->refresh()->current_streak)->toBe(3)
            ->and($participant->freezes_used)->toBe(0);
    });

    it('closes a photo still waiting on the creator', function () {
        // Unapproved when the period closed is unapproved, however far along the
        // submission got. Otherwise a creator who never reviews freezes the timeline.
        $participant = ChallengeParticipant::factory()->for($this->challenge)->withoutFreezes()->create();
        $waiting = CheckIn::factory()->on($participant, endedPeriod($this->challenge))->submitted()->create();

        $this->settle->close($waiting);

        expect($waiting->status)->toBe(CheckInStatus::Missed);
    });

    it('does not read counters from a stale participant relation', function () {
        // Hydrating the relation before another settlement commits, then writing the
        // remembered streak back, is how an increment silently disappears.
        $participant = ChallengeParticipant::factory()->for($this->challenge)->withStreak(2)->create();
        $stale = obligation($participant, openPeriod($this->challenge, 0));
        $stale->load('participant');

        $this->settle->approve(obligation($participant, openPeriod($this->challenge, 1)));
        $this->settle->approve($stale);

        expect($participant->refresh()->current_streak)->toBe(4);
    });
});

describe('rolling a period over', function () {
    it('settles everyone who owed the period', function () {
        $period = endedPeriod($this->challenge);
        $kept = ChallengeParticipant::factory()->for($this->challenge)->withFreezes(1)->create();
        $lost = ChallengeParticipant::factory()->for($this->challenge)->withStreak(3)->withoutFreezes()->create();

        $settled = $this->rollOver->handle($period);

        expect($settled)->toHaveCount(2)
            ->and($kept->refresh()->freezes_used)->toBe(1)
            ->and($lost->refresh()->current_streak)->toBe(0)
            ->and($lost->streak_resets_count)->toBe(1);
    });

    it('creates the obligation for a participant who never opened the bot', function () {
        // No check-in row at all is still a missed period.
        $period = endedPeriod($this->challenge);
        $absent = ChallengeParticipant::factory()->for($this->challenge)->withoutFreezes()->create();

        $this->rollOver->handle($period);

        expect($absent->checkIns()->count())->toBe(1)
            ->and($absent->checkIns()->sole()->status)->toBe(CheckInStatus::Missed);
    });

    it('marks the period swept', function () {
        $period = endedPeriod($this->challenge);

        $this->rollOver->handle($period);

        expect($period->refresh()->isRolledOver())->toBeTrue()
            ->and(ChallengePeriod::query()->awaitingRollover()->count())->toBe(0);
    });

    it('refuses to settle a period that is still open', function () {
        // Destructive if allowed: everyone who has not checked in yet would lose a
        // streak they still had time to keep.
        $period = openPeriod($this->challenge);
        $participant = ChallengeParticipant::factory()->for($this->challenge)->withoutFreezes()->create();

        expect(fn () => $this->rollOver->handle($period))
            ->toThrow(PeriodNotEndedException::class);

        expect($participant->checkIns()->count())->toBe(0)
            ->and($period->refresh()->isRolledOver())->toBeFalse();
    });

    it('settles a period the instant it closes, not a moment before', function () {
        // `ends_at` is exclusive, so the boundary instant belongs to the next period
        // and the closing one is done.
        $period = ChallengePeriod::factory()->for($this->challenge)->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addHour(),
        ]);
        ChallengeParticipant::factory()->for($this->challenge)->create();

        expect(fn () => $this->rollOver->handle($period, now()->addHour()->subSecond()))
            ->toThrow(PeriodNotEndedException::class);

        expect($this->rollOver->handle($period, now()->addHour()))->toHaveCount(1);
    });

    it('spares a late joiner the periods that closed before they arrived', function () {
        // The timeline is shared and fixed; a late joiner catches up rather than
        // being judged on history they were absent for.
        $period = endedPeriod($this->challenge, 3);
        $late = ChallengeParticipant::factory()->for($this->challenge)->joinedAtPeriod(4)->withoutFreezes()->create();
        $early = ChallengeParticipant::factory()->for($this->challenge)->withoutFreezes()->create();

        $this->rollOver->handle($period);

        expect($late->checkIns()->count())->toBe(0)
            ->and($late->refresh()->streak_resets_count)->toBe(0)
            ->and($early->checkIns()->sole()->status)->toBe(CheckInStatus::Missed);
    });

    it('judges a late joiner from the period they joined on', function () {
        $late = ChallengeParticipant::factory()->for($this->challenge)->joinedAtPeriod(4)->withoutFreezes()->create();

        $this->rollOver->handle(endedPeriod($this->challenge, 4));

        expect($late->checkIns()->sole()->status)->toBe(CheckInStatus::Missed)
            ->and($late->refresh()->streak_resets_count)->toBe(1);
    });

    it('stops accruing misses for a participant who is no longer taking part', function (string $state) {
        $gone = ChallengeParticipant::factory()->for($this->challenge)->withoutFreezes()->{$state}()->create();

        $this->rollOver->handle(endedPeriod($this->challenge));

        expect($gone->checkIns()->count())->toBe(0)
            ->and($gone->refresh()->streak_resets_count)->toBe(0);
    })->with([
        'left' => 'left',
        'removed' => 'removed',
        'completed' => 'completed',
    ]);

    it('never reaches into another challenge’s participants', function () {
        $other = Challenge::factory()->active()->create();
        $bystander = ChallengeParticipant::factory()->for($other)->withoutFreezes()->create();
        ChallengeParticipant::factory()->for($this->challenge)->withoutFreezes()->create();

        expect($this->rollOver->handle(endedPeriod($this->challenge)))->toHaveCount(1);

        expect($bystander->refresh()->streak_resets_count)->toBe(0);
    });

    it('changes nothing when it is re-run', function () {
        // The scheduled sweep can fire twice, and a queued job can be retried.
        $period = endedPeriod($this->challenge);
        $frozen = ChallengeParticipant::factory()->for($this->challenge)->withStreak(4)->withFreezes(1)->create();
        $missed = ChallengeParticipant::factory()->for($this->challenge)->withStreak(4)->withoutFreezes()->create();

        $this->rollOver->handle($period);
        $this->rollOver->handle($period);
        $this->rollOver->handle($period->fresh());

        expect(CheckIn::query()->count())->toBe(2)
            ->and($frozen->refresh()->freezes_used)->toBe(1)
            ->and($frozen->current_streak)->toBe(4)
            ->and($missed->refresh()->streak_resets_count)->toBe(1);
    });

    it('finishes a run that died halfway through', function () {
        // There is deliberately no early return on `rolled_over_at`. If a previous
        // attempt marked the period swept but crashed before settling everyone, an
        // early return would leave the rest unsettled forever.
        $period = endedPeriod($this->challenge);
        $done = ChallengeParticipant::factory()->for($this->challenge)->withoutFreezes()->create();
        CheckIn::factory()->on($done, $period)->missed()->create();
        $period->update(['rolled_over_at' => now()->subMinute()]);

        $stranded = ChallengeParticipant::factory()->for($this->challenge)->withoutFreezes()->create();

        $this->rollOver->handle($period);

        expect($stranded->checkIns()->sole()->status)->toBe(CheckInStatus::Missed)
            ->and($stranded->refresh()->streak_resets_count)->toBe(1)
            ->and($done->refresh()->streak_resets_count)->toBe(0);
    });

    it('does not restamp the sweep marker on a re-run', function () {
        $period = endedPeriod($this->challenge);
        $this->rollOver->handle($period);
        $firstSweep = $period->refresh()->rolled_over_at;

        $this->travel(1)->hours();
        $this->rollOver->handle($period->fresh());

        expect($period->refresh()->rolled_over_at?->equalTo($firstSweep))->toBeTrue();

        $this->travelBack();
    });

    it('settles a whole timeline in order, freezing then breaking the streak', function () {
        // The engine end to end: check in, check in, miss with a freeze, miss
        // without one.
        $participant = ChallengeParticipant::factory()->for($this->challenge)->withFreezes(1)->create();

        $periods = collect(range(0, 3))->map(fn (int $index): ChallengePeriod => endedPeriod($this->challenge, $index));
        $this->settle->approve(obligation($participant, $periods[0]));
        $this->settle->approve(obligation($participant, $periods[1]));

        $outcomes = $periods
            ->flatMap(fn (ChallengePeriod $period): array => $this->rollOver->handle($period)->all())
            ->map(fn (CheckIn $checkIn): CheckInStatus => $checkIn->status);

        expect($outcomes->all())->toBe([
            CheckInStatus::Approved,
            CheckInStatus::Approved,
            CheckInStatus::Frozen,
            CheckInStatus::Missed,
        ])
            ->and($participant->refresh()->current_streak)->toBe(0)
            ->and($participant->longest_streak)->toBe(2)
            ->and($participant->freezes_used)->toBe(1)
            ->and($participant->streak_resets_count)->toBe(1);
    });
});
