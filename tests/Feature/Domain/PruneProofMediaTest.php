<?php

use App\Actions\CheckIns\PruneProofMedia;
use App\Enums\CheckInSessionStatus;
use App\Enums\CheckInStatus;
use App\Enums\SettingKey;
use App\Models\ChallengeParticipant;
use App\Models\ChallengePeriod;
use App\Models\ChallengeStep;
use App\Models\CheckIn;
use App\Models\CheckInSession;
use App\Models\User;
use App\Services\Settings;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
 * Bytes vs. record: after the retention window, a decided submission's file
 * goes and its row stays — status, reviewer and timestamps intact — while a
 * submission still awaiting a human is never touched, however old. These tests
 * watch the disk as closely as the rows, because the whole point is the disk.
 */

beforeEach(function () {
    Storage::fake('local');

    app(Settings::class)->set(SettingKey::ProofMediaRetentionDays, 90);

    $this->prune = app(PruneProofMedia::class);
});

/**
 * A real file on the local disk, at `$path`.
 */
function storedFile(string $path): string
{
    Storage::disk('local')->put($path, 'proof bytes');

    return $path;
}

/**
 * A check-in on its own challenge, with `$attributes` applied afterwards.
 *
 * @param  array<string, mixed>  $attributes
 */
function aCheckIn(array $attributes = []): CheckIn
{
    $participant = ChallengeParticipant::factory()->create();
    $period = ChallengePeriod::factory()->for($participant->challenge)->atIndex(0)->create();

    $checkIn = CheckIn::factory()->on($participant, $period)->create();
    $checkIn->update($attributes);

    return $checkIn->fresh();
}

describe('decided check-ins', function () {
    it('deletes the file past the window and keeps the decision record', function () {
        $reviewer = User::factory()->create();
        $path = storedFile('proofs/decided.jpg');

        $checkIn = aCheckIn(['proof_path' => $path]);
        $checkIn->update([
            'status' => CheckInStatus::Approved,
            'reviewed_by' => $reviewer->getKey(),
            'reviewed_at' => now()->subDays(100),
        ]);

        $pruned = $this->prune->handle();

        expect($pruned)->toBe(1)
            ->and(Storage::disk('local')->exists($path))->toBeFalse()
            ->and($checkIn->fresh()->proof_path)->toBeNull()
            // The verdict survives its evidence: a decision that can no longer
            // be examined is one the participant cannot appeal.
            ->and($checkIn->fresh()->status)->toBe(CheckInStatus::Approved)
            ->and($checkIn->fresh()->reviewed_by)->toBe($reviewer->getKey())
            ->and($checkIn->fresh()->reviewed_at->isSameDay(now()->subDays(100)))->toBeTrue();
    });

    it('never prunes a submission still awaiting review, however old', function () {
        $path = storedFile('proofs/waiting.jpg');

        $checkIn = aCheckIn(['proof_path' => $path]);
        $checkIn->update([
            'status' => CheckInStatus::Submitted,
            'submitted_at' => now()->subDays(365),
        ]);

        $this->prune->handle();

        expect(Storage::disk('local')->exists($path))->toBeTrue()
            ->and($checkIn->fresh()->proof_path)->toBe($path);
    });

    it('leaves decisions inside the window alone', function () {
        $path = storedFile('proofs/recent.jpg');

        $checkIn = aCheckIn(['proof_path' => $path]);
        $checkIn->update([
            'status' => CheckInStatus::Approved,
            'reviewed_at' => now()->subDays(10),
        ]);

        $this->prune->handle();

        expect(Storage::disk('local')->exists($path))->toBeTrue()
            ->and($checkIn->fresh()->proof_path)->toBe($path);
    });

    it('treats a rollover verdict as decided, measured from updated_at', function () {
        // A Missed or Frozen row carries no reviewer: the rollover decided it,
        // and `updated_at` is the moment it did.
        $path = storedFile('proofs/rollover.jpg');

        $checkIn = aCheckIn(['proof_path' => $path]);
        // `updated_at` is not mass-assignable, and the rollover's moment is
        // exactly what this test needs to place — force it, then let the
        // explicit dirty value survive save().
        $checkIn->forceFill([
            'status' => CheckInStatus::Frozen,
            'updated_at' => now()->subDays(120),
        ])->save();

        $this->prune->handle();

        expect(Storage::disk('local')->exists($path))->toBeFalse()
            ->and($checkIn->fresh()->proof_path)->toBeNull()
            ->and($checkIn->fresh()->status)->toBe(CheckInStatus::Frozen);
    });
});

describe('step submissions', function () {
    it('prunes the media of a finished session past the window', function () {
        [$session, $path] = aStepSubmission(CheckInSessionStatus::Completed, now()->subDays(100));

        $pruned = $this->prune->handle();

        expect($pruned)->toBe(1)
            ->and(Storage::disk('local')->exists($path))->toBeFalse()
            ->and($session->submissions()->sole()->proof_path)->toBeNull();
    });

    it('never prunes the media of a session still in progress', function () {
        [$session, $path] = aStepSubmission(CheckInSessionStatus::InProgress, now()->subDays(365));

        $this->prune->handle();

        expect(Storage::disk('local')->exists($path))->toBeTrue()
            ->and($session->submissions()->sole()->proof_path)->toBe($path);
    });
});

describe('re-runs', function () {
    it('delete nothing and report zero', function () {
        $path = storedFile('proofs/once.jpg');

        $checkIn = aCheckIn(['proof_path' => $path]);
        $checkIn->update([
            'status' => CheckInStatus::Approved,
            'reviewed_at' => now()->subDays(100),
        ]);

        expect($this->prune->handle())->toBe(1)
            ->and($this->prune->handle())->toBe(0);
    });

    it('clear a row whose file already went missing, so it is not revisited forever', function () {
        $checkIn = aCheckIn(['proof_path' => 'proofs/vanished.jpg']);
        $checkIn->update([
            'status' => CheckInStatus::Approved,
            'reviewed_at' => now()->subDays(100),
        ]);

        expect($this->prune->handle())->toBe(0)
            ->and($checkIn->fresh()->proof_path)->toBeNull();
    });
});

it('runs as the scheduled command', function () {
    $this->artisan('challenges:prune-proof-media')->assertSuccessful();
});

/**
 * A session in `$status` carrying one media submission from `$submittedAt`.
 *
 * @return array{0: CheckInSession, 1: string} the session and the stored path
 */
function aStepSubmission(CheckInSessionStatus $status, CarbonInterface $submittedAt): array
{
    $participant = ChallengeParticipant::factory()->create();
    $step = ChallengeStep::factory()->for($participant->challenge)->atOrder(1)->image()->create();

    $session = CheckInSession::factory()
        ->for($participant, 'participant')
        ->create([
            'status' => $status,
            'current_step_order' => $status === CheckInSessionStatus::InProgress ? 1 : null,
        ]);

    $path = storedFile('steps/'.$session->getKey().'.jpg');

    $session->submissions()->create([
        'challenge_step_id' => $step->getKey(),
        'submitted_at' => $submittedAt,
        'proof_path' => $path,
    ]);

    return [$session->fresh(), $path];
}
