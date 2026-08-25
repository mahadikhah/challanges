<?php

use App\Actions\CheckIns\ReviewCheckIn;
use App\Enums\CheckInRejection;
use App\Enums\CheckInStatus;
use App\Enums\ProofType;
use App\Exceptions\CheckInRejectedException;
use App\Models\Challenge;
use App\Models\ChallengeParticipant;
use App\Models\ChallengePeriod;
use App\Models\CheckIn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->review = app(ReviewCheckIn::class);
});

/**
 * A photo sitting in the creator's queue, with the challenge, participant and
 * period behind it wired up.
 */
function awaitingVerdict(): CheckIn
{
    $challenge = Challenge::factory()->active()->provenBy(ProofType::ImageApproval)->create();

    $period = ChallengePeriod::factory()->for($challenge)->atIndex(0)->create([
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHour(),
    ]);

    $participant = ChallengeParticipant::factory()->for($challenge)->create();

    return CheckIn::factory()->on($participant, $period)->submitted()->create([
        'proof_path' => 'proofs/photo.jpg',
    ]);
}

/**
 * The reason carried by the rejection `$attempt` throws.
 */
function verdictRefusal(Closure $attempt): CheckInRejection
{
    try {
        $attempt();
    } catch (CheckInRejectedException $rejection) {
        return $rejection->reason;
    }

    throw new RuntimeException('Expected the review to be refused, but it went through.');
}

describe('approving a photo', function () {
    it('settles the period, moves the streak and records who decided', function () {
        $checkIn = awaitingVerdict();
        $creator = $checkIn->participant->challenge->creator;

        $approved = $this->review->approve($creator, $checkIn);

        expect($approved->status)->toBe(CheckInStatus::Approved)
            ->and($approved->reviewed_by)->toBe($creator->id)
            ->and($approved->reviewed_at)->not->toBeNull()
            ->and($approved->participant->refresh()->current_streak)->toBe(1);
    });

    it('keeps the proof and the submission time intact', function () {
        $checkIn = awaitingVerdict();
        $submittedAt = $checkIn->submitted_at;

        $approved = $this->review->approve($checkIn->participant->challenge->creator, $checkIn);

        expect($approved->proof_path)->toBe('proofs/photo.jpg')
            ->and($approved->submitted_at->equalTo($submittedAt))->toBeTrue();
    });

    it('lets a platform admin step in for an absent creator', function () {
        $checkIn = awaitingVerdict();
        $admin = User::factory()->admin()->create();

        $approved = $this->review->approve($admin, $checkIn);

        expect($approved->status)->toBe(CheckInStatus::Approved)
            ->and($approved->reviewed_by)->toBe($admin->id);
    });

    it('refuses to approve twice, and leaves the first verdict standing', function () {
        $checkIn = awaitingVerdict();
        $creator = $checkIn->participant->challenge->creator;
        $admin = User::factory()->admin()->create();

        $this->review->approve($creator, $checkIn);

        expect(verdictRefusal(fn () => $this->review->approve($admin, $checkIn)))
            ->toBe(CheckInRejection::AlreadySettled)
            ->and($checkIn->refresh()->reviewed_by)->toBe($creator->id)
            ->and($checkIn->participant->refresh()->current_streak)->toBe(1);
    });

    it('refuses to approve a row with no photo on the table', function (CheckInStatus $status) {
        // Nothing was submitted, so there is nothing the creator has seen. Waving
        // it through would credit a period on no proof at all.
        $checkIn = awaitingVerdict();
        $checkIn->update(['status' => $status]);
        $creator = $checkIn->participant->challenge->creator;

        expect(verdictRefusal(fn () => $this->review->approve($creator, $checkIn)))
            ->toBe(CheckInRejection::NotAwaitingReview)
            ->and($checkIn->refresh()->status)->toBe($status)
            ->and($checkIn->participant->refresh()->current_streak)->toBe(0);
    })->with([
        'never submitted' => CheckInStatus::Pending,
        'already rejected' => CheckInStatus::Rejected,
    ]);

    it('refuses a late approval and does not stamp the reviewer on it', function (CheckInStatus $settled) {
        // The rollover closed the period before the creator got to the queue.
        $checkIn = awaitingVerdict();
        $checkIn->update(['status' => $settled]);
        $creator = $checkIn->participant->challenge->creator;

        expect(verdictRefusal(fn () => $this->review->approve($creator, $checkIn)))
            ->toBe(CheckInRejection::AlreadySettled);

        $checkIn->refresh();

        expect($checkIn->status)->toBe($settled)
            ->and($checkIn->reviewed_by)->toBeNull()
            ->and($checkIn->reviewed_at)->toBeNull()
            ->and($checkIn->participant->refresh()->current_streak)->toBe(0);
    })->with([
        'missed by the rollover' => CheckInStatus::Missed,
        'frozen by the rollover' => CheckInStatus::Frozen,
    ]);

    it('carries the settled row on the rejection so the creator can be told what happened', function () {
        $checkIn = awaitingVerdict();
        $checkIn->update(['status' => CheckInStatus::Missed]);

        try {
            $this->review->approve($checkIn->participant->challenge->creator, $checkIn);
        } catch (CheckInRejectedException $rejection) {
            expect($rejection->checkIn?->id)->toBe($checkIn->id)
                ->and($rejection->checkIn?->status)->toBe(CheckInStatus::Missed);

            return;
        }

        throw new RuntimeException('Expected the late approval to be refused.');
    });
});

describe('rejecting a photo', function () {
    it('marks it rejected, records the reviewer and moves no streak', function () {
        $checkIn = awaitingVerdict();
        $creator = $checkIn->participant->challenge->creator;

        $rejected = $this->review->reject($creator, $checkIn);

        expect($rejected->status)->toBe(CheckInStatus::Rejected)
            ->and($rejected->reviewed_by)->toBe($creator->id)
            ->and($rejected->reviewed_at)->not->toBeNull()
            ->and($rejected->participant->refresh()->current_streak)->toBe(0);
    });

    it('leaves the row open to another photo', function () {
        $checkIn = awaitingVerdict();

        $rejected = $this->review->reject($checkIn->participant->challenge->creator, $checkIn);

        expect($rejected->status->allowsSubmission())->toBeTrue()
            ->and($rejected->status->isSettled())->toBeFalse();
    });

    it('refuses to reject a row with no photo on the table', function (CheckInStatus $status) {
        $checkIn = awaitingVerdict();
        $checkIn->update(['status' => $status]);
        $creator = $checkIn->participant->challenge->creator;

        expect(verdictRefusal(fn () => $this->review->reject($creator, $checkIn)))
            ->toBe(CheckInRejection::NotAwaitingReview)
            ->and($checkIn->refresh()->status)->toBe($status);
    })->with([
        'never submitted' => CheckInStatus::Pending,
        'already rejected' => CheckInStatus::Rejected,
    ]);

    it('refuses to reject a period that is already settled', function (CheckInStatus $status) {
        $checkIn = awaitingVerdict();
        $checkIn->update(['status' => $status]);
        $creator = $checkIn->participant->challenge->creator;

        expect(verdictRefusal(fn () => $this->review->reject($creator, $checkIn)))
            ->toBe(CheckInRejection::AlreadySettled)
            ->and($checkIn->refresh()->status)->toBe($status);
    })->with([
        'approved' => CheckInStatus::Approved,
        'missed' => CheckInStatus::Missed,
        'frozen' => CheckInStatus::Frozen,
    ]);

    it('reads the status from the database, not from a stale instance', function () {
        $checkIn = awaitingVerdict();
        $creator = $checkIn->participant->challenge->creator;

        // The creator opened the queue, then the rollover ran underneath them.
        CheckIn::query()->whereKey($checkIn->getKey())->update(['status' => CheckInStatus::Missed]);

        // The in-memory instance still says Submitted, which would have passed the
        // guard had it not been re-read.
        expect($checkIn->status)->toBe(CheckInStatus::Submitted)
            ->and(verdictRefusal(fn () => $this->review->reject($creator, $checkIn)))
            ->toBe(CheckInRejection::AlreadySettled);
    });
});

describe('who may review', function () {
    it('refuses a stranger', function () {
        $checkIn = awaitingVerdict();
        $stranger = User::factory()->telegram()->create();

        expect(verdictRefusal(fn () => $this->review->approve($stranger, $checkIn)))
            ->toBe(CheckInRejection::NotTheReviewer)
            ->and(verdictRefusal(fn () => $this->review->reject($stranger, $checkIn)))
            ->toBe(CheckInRejection::NotTheReviewer)
            ->and($checkIn->refresh()->status)->toBe(CheckInStatus::Submitted);
    });

    it('refuses the participant whose photo it is', function () {
        $checkIn = awaitingVerdict();

        expect(verdictRefusal(fn () => $this->review->approve($checkIn->participant->user, $checkIn)))
            ->toBe(CheckInRejection::NotTheReviewer);
    });

    it('refuses the creator of a different challenge', function () {
        $checkIn = awaitingVerdict();
        $otherCreator = Challenge::factory()->active()->create()->creator;

        expect(verdictRefusal(fn () => $this->review->approve($otherCreator, $checkIn)))
            ->toBe(CheckInRejection::NotTheReviewer);
    });

    it('derives ownership from the row itself', function () {
        // Two challenges, and the creator of one holding the other's check-in id.
        // Authorization is read off `$checkIn->participant->challenge`, so the
        // borrowed id names its own owner and the mismatch is caught.
        $mine = awaitingVerdict();
        $theirs = awaitingVerdict();

        expect(verdictRefusal(fn () => $this->review->approve($mine->participant->challenge->creator, $theirs)))
            ->toBe(CheckInRejection::NotTheReviewer)
            ->and($theirs->refresh()->status)->toBe(CheckInStatus::Submitted);
    });

    it('lets a creator who is also a participant approve their own photo', function () {
        $challenge = Challenge::factory()->active()->provenBy(ProofType::ImageApproval)->create();
        $period = ChallengePeriod::factory()->for($challenge)->atIndex(0)->create([
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);
        $participant = ChallengeParticipant::factory()
            ->for($challenge)
            ->for($challenge->creator, 'user')
            ->create();

        $checkIn = CheckIn::factory()->on($participant, $period)->submitted()->create();

        $approved = $this->review->approve($challenge->creator, $checkIn);

        // Allowed by design — they chose the proof type — and visible in the audit
        // trail rather than hidden.
        expect($approved->status)->toBe(CheckInStatus::Approved)
            ->and($approved->reviewed_by)->toBe($challenge->creator_id);
    });
});
