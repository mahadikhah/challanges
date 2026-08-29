<?php

use App\Actions\CheckIns\IssueCheckInPhrase;
use App\Actions\CheckIns\OpenCheckIn;
use App\Actions\CheckIns\SubmitCheckIn;
use App\Enums\ChallengeStatus;
use App\Enums\CheckInRejection;
use App\Enums\CheckInStatus;
use App\Enums\ParticipantStatus;
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
    // SubmitCheckIn carries the AI-verdict router, which holds the bot
    // messenger. Manual-mode flows never send, but the client binding still
    // needs a token to exist for the action to resolve.
    config(['services.telegram.bot_token' => '123456:TEST-TOKEN']);

    $this->submit = app(SubmitCheckIn::class);
});

/**
 * A challenge proven by `$proofType`, with one open period on its timeline.
 */
function provenBy(ProofType $proofType): Challenge
{
    $challenge = Challenge::factory()->active()->provenBy($proofType)->create();

    ChallengePeriod::factory()->for($challenge)->atIndex(0)->create([
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHour(),
    ]);

    return $challenge;
}

/**
 * Enrol a fresh user in `$challenge` and hand back the user, since that is what a
 * surface actually holds — the verified actor, not a participant row.
 */
function enrol(Challenge $challenge, ?ChallengeParticipant &$participant = null): User
{
    $user = User::factory()->telegram()->create(['locale' => 'en']);

    $participant = ChallengeParticipant::factory()->for($challenge)->for($user)->create();

    return $user;
}

/**
 * The reason carried by the rejection `$attempt` throws.
 */
function refusalFor(Closure $attempt): CheckInRejection
{
    try {
        $attempt();
    } catch (CheckInRejectedException $rejection) {
        return $rejection->reason;
    }

    throw new RuntimeException('Expected the submission to be refused, but it was accepted.');
}

describe('tapping a button', function () {
    it('approves immediately and moves the streak', function () {
        $challenge = provenBy(ProofType::Button);
        $actor = enrol($challenge, $participant);

        $checkIn = $this->submit->tap($actor, $challenge);

        expect($checkIn->status)->toBe(CheckInStatus::Approved)
            ->and($checkIn->submitted_at)->toBeNull()
            ->and($participant->refresh()->current_streak)->toBe(1);
    });

    it('opens the obligation for a participant who never had a row', function () {
        $challenge = provenBy(ProofType::Button);
        $actor = enrol($challenge);

        expect(CheckIn::query()->count())->toBe(0);

        $this->submit->tap($actor, $challenge);

        expect(CheckIn::query()->count())->toBe(1);
    });

    it('refuses a second tap in the same period rather than double-counting it', function () {
        $challenge = provenBy(ProofType::Button);
        $actor = enrol($challenge, $participant);

        $this->submit->tap($actor, $challenge);

        expect(refusalFor(fn () => $this->submit->tap($actor, $challenge)))
            ->toBe(CheckInRejection::AlreadySettled)
            ->and($participant->refresh()->current_streak)->toBe(1)
            ->and(CheckIn::query()->count())->toBe(1);
    });

    it('refuses a tap on a challenge that wants a photo', function () {
        $challenge = provenBy(ProofType::ImageApproval);

        expect(refusalFor(fn () => $this->submit->tap(enrol($challenge), $challenge)))
            ->toBe(CheckInRejection::WrongProofType);
    });
});

describe('typing a phrase', function () {
    it('approves an exact match', function () {
        $challenge = provenBy(ProofType::TextAutogen);
        $actor = enrol($challenge, $participant);
        $period = $challenge->periods()->sole();

        $phrase = (string) app(IssueCheckInPhrase::class)
            ->forParticipant($participant, $period)
            ->expected_phrase;

        $checkIn = $this->submit->typePhrase($actor, $challenge, $phrase);

        expect($checkIn->status)->toBe(CheckInStatus::Approved)
            ->and($checkIn->submitted_text)->toBe($phrase)
            ->and($checkIn->submitted_at)->not->toBeNull()
            ->and($participant->refresh()->current_streak)->toBe(1);
    });

    it('issues the phrase on demand for a participant no reminder has reached', function () {
        $challenge = provenBy(ProofType::TextAutogen);
        $actor = enrol($challenge);

        // Nothing has issued a phrase yet, so the first attempt cannot match —
        // but it must leave a phrase behind for the participant to be told.
        $refusal = refusalFor(fn () => $this->submit->typePhrase($actor, $challenge, 'anything at all'));

        expect($refusal)->toBe(CheckInRejection::PhraseMismatch)
            ->and(CheckIn::query()->sole()->expected_phrase)->not->toBeNull();
    });

    it('accepts the phrase however it was retyped', function (Closure $retype) {
        $challenge = provenBy(ProofType::TextAutogen);
        $actor = enrol($challenge, $participant);

        $issued = app(IssueCheckInPhrase::class)
            ->forParticipant($participant, $challenge->periods()->sole());

        $checkIn = $this->submit->typePhrase($actor, $challenge, $retype((string) $issued->expected_phrase));

        expect($checkIn->status)->toBe(CheckInStatus::Approved);
    })->with([
        'shouted' => [fn (string $phrase): string => mb_strtoupper($phrase)],
        'padded' => [fn (string $phrase): string => "  {$phrase} "],
        'double-spaced' => [fn (string $phrase): string => str_replace(' ', '   ', $phrase)],
    ]);

    it('refuses a wrong phrase without recording it or touching the streak', function () {
        $challenge = provenBy(ProofType::TextAutogen);
        $actor = enrol($challenge, $participant);

        expect(refusalFor(fn () => $this->submit->typePhrase($actor, $challenge, 'not my phrase 11')))
            ->toBe(CheckInRejection::PhraseMismatch);

        $checkIn = CheckIn::query()->sole();

        expect($checkIn->status)->toBe(CheckInStatus::Pending)
            ->and($checkIn->submitted_text)->toBeNull()
            ->and($checkIn->submitted_at)->toBeNull()
            ->and($participant->refresh()->current_streak)->toBe(0);
    });

    it('refuses another participant\'s phrase', function () {
        $challenge = provenBy(ProofType::TextAutogen);
        $period = $challenge->periods()->sole();
        $issuer = app(IssueCheckInPhrase::class);

        $mine = enrol($challenge, $myParticipant);
        enrol($challenge, $theirParticipant);

        $issuer->forParticipant($myParticipant, $period);
        $theirs = (string) $issuer->forParticipant($theirParticipant, $period)->expected_phrase;

        expect(refusalFor(fn () => $this->submit->typePhrase($mine, $challenge, $theirs)))
            ->toBe(CheckInRejection::PhraseMismatch);
    });

    it('refuses an empty message before it resolves anything', function () {
        $challenge = provenBy(ProofType::TextAutogen);

        expect(refusalFor(fn () => $this->submit->typePhrase(enrol($challenge), $challenge, '   ')))
            ->toBe(CheckInRejection::ProofMissing)
            ->and(CheckIn::query()->count())->toBe(0);
    });

    it('refuses text on a challenge that wants a tap', function () {
        $challenge = provenBy(ProofType::Button);

        expect(refusalFor(fn () => $this->submit->typePhrase(enrol($challenge), $challenge, 'blue anchor 42')))
            ->toBe(CheckInRejection::WrongProofType);
    });
});

describe('uploading a photo', function () {
    it('stores the proof and leaves it for the creator', function () {
        $challenge = provenBy(ProofType::ImageApproval);
        $actor = enrol($challenge, $participant);

        $checkIn = $this->submit->uploadPhoto($actor, $challenge, 'proofs/1/photo.jpg');

        expect($checkIn->status)->toBe(CheckInStatus::Submitted)
            ->and($checkIn->proof_path)->toBe('proofs/1/photo.jpg')
            ->and($checkIn->submitted_at)->not->toBeNull()
            ->and($checkIn->reviewed_by)->toBeNull()
            // Nothing moves until the creator decides.
            ->and($participant->refresh()->current_streak)->toBe(0);
    });

    it('refuses a second photo while the first is still with the creator', function () {
        $challenge = provenBy(ProofType::ImageApproval);
        $actor = enrol($challenge);

        $this->submit->uploadPhoto($actor, $challenge, 'proofs/first.jpg');

        expect(refusalFor(fn () => $this->submit->uploadPhoto($actor, $challenge, 'proofs/second.jpg')))
            ->toBe(CheckInRejection::AwaitingReview)
            ->and(CheckIn::query()->sole()->proof_path)->toBe('proofs/first.jpg');
    });

    it('accepts a replacement after a rejection and clears the stale review', function () {
        $challenge = provenBy(ProofType::ImageApproval);
        $actor = enrol($challenge, $participant);
        $creator = $challenge->creator;

        $checkIn = CheckIn::factory()
            ->on($participant, $challenge->periods()->sole())
            ->rejected($creator)
            ->create(['proof_path' => 'proofs/first.jpg']);

        expect($checkIn->reviewed_by)->toBe($creator->id);

        $resubmitted = $this->submit->uploadPhoto($actor, $challenge, 'proofs/second.jpg');

        expect($resubmitted->id)->toBe($checkIn->id)
            ->and($resubmitted->status)->toBe(CheckInStatus::Submitted)
            ->and($resubmitted->proof_path)->toBe('proofs/second.jpg')
            ->and($resubmitted->reviewed_by)->toBeNull()
            ->and($resubmitted->reviewed_at)->toBeNull();
    });

    it('refuses an empty path', function () {
        $challenge = provenBy(ProofType::ImageApproval);

        expect(refusalFor(fn () => $this->submit->uploadPhoto(enrol($challenge), $challenge, '')))
            ->toBe(CheckInRejection::ProofMissing);
    });

    it('refuses a photo on a challenge that wants a phrase', function () {
        $challenge = provenBy(ProofType::TextAutogen);

        expect(refusalFor(fn () => $this->submit->uploadPhoto(enrol($challenge), $challenge, 'proofs/x.jpg')))
            ->toBe(CheckInRejection::WrongProofType);
    });
});

describe('who may check in', function () {
    it('refuses a user who never joined', function () {
        $challenge = provenBy(ProofType::Button);
        $stranger = User::factory()->telegram()->create();

        expect(refusalFor(fn () => $this->submit->tap($stranger, $challenge)))
            ->toBe(CheckInRejection::NotAParticipant)
            ->and(CheckIn::query()->count())->toBe(0);
    });

    it('refuses a participant who is no longer taking part', function (ParticipantStatus $status) {
        $challenge = provenBy(ProofType::Button);
        $actor = enrol($challenge, $participant);
        $participant->update(['status' => $status]);

        expect(refusalFor(fn () => $this->submit->tap($actor, $challenge)))
            ->toBe(CheckInRejection::NotAParticipant);
    })->with([
        'left' => ParticipantStatus::Left,
        'removed' => ParticipantStatus::Removed,
        'completed' => ParticipantStatus::Completed,
    ]);

    it('does not let one participant check in as another', function () {
        // The signature takes the verified actor and resolves the participant
        // row from it, so there is no id for a client to swap. This asserts the
        // consequence: A's tap only ever settles A's row.
        $challenge = provenBy(ProofType::Button);
        $a = enrol($challenge, $participantA);
        enrol($challenge, $participantB);

        $this->submit->tap($a, $challenge);

        expect($participantA->refresh()->current_streak)->toBe(1)
            ->and($participantB->refresh()->current_streak)->toBe(0)
            ->and(CheckIn::query()->sole()->challenge_participant_id)->toBe($participantA->id);
    });

    it('refuses a challenge that is not accepting check-ins', function (ChallengeStatus $status) {
        $challenge = provenBy(ProofType::Button);
        $actor = enrol($challenge);
        $challenge->update(['status' => $status]);

        expect(refusalFor(fn () => $this->submit->tap($actor, $challenge)))
            ->toBe(CheckInRejection::ChallengeClosed);
    })->with([
        'scheduled' => ChallengeStatus::Scheduled,
        'completed' => ChallengeStatus::Completed,
        'cancelled' => ChallengeStatus::Cancelled,
    ]);
});

describe('which period a submission lands in', function () {
    it('uses the period that contains the moment, not the first one', function () {
        $challenge = Challenge::factory()->active()->provenBy(ProofType::Button)->create();

        ChallengePeriod::factory()->for($challenge)->atIndex(0)->create([
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subDay(),
        ]);
        $today = ChallengePeriod::factory()->for($challenge)->atIndex(1)->create([
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);

        $checkIn = $this->submit->tap(enrol($challenge), $challenge);

        expect($checkIn->challenge_period_id)->toBe($today->id);
    });

    it('refuses when the timeline has not started yet', function () {
        $challenge = Challenge::factory()->active()->provenBy(ProofType::Button)->create();
        ChallengePeriod::factory()->for($challenge)->atIndex(0)->create([
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(2),
        ]);

        expect(refusalFor(fn () => $this->submit->tap(enrol($challenge), $challenge)))
            ->toBe(CheckInRejection::NoOpenPeriod);
    });

    it('refuses when every period has closed', function () {
        $challenge = Challenge::factory()->active()->provenBy(ProofType::Button)->create();
        ChallengePeriod::factory()->for($challenge)->atIndex(0)->create([
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subDay(),
        ]);

        expect(refusalFor(fn () => $this->submit->tap(enrol($challenge), $challenge)))
            ->toBe(CheckInRejection::NoOpenPeriod);
    });

    it('treats the closing instant as belonging to the next period', function () {
        $challenge = Challenge::factory()->active()->provenBy(ProofType::Button)->create();
        $period = ChallengePeriod::factory()->for($challenge)->atIndex(0)->create([
            'starts_at' => now()->subHours(2),
            'ends_at' => now(),
        ]);

        expect(refusalFor(fn () => $this->submit->tap(enrol($challenge), $challenge, $period->ends_at)))
            ->toBe(CheckInRejection::NoOpenPeriod);
    });

    it('accepts at the opening instant', function () {
        $challenge = Challenge::factory()->active()->provenBy(ProofType::Button)->create();
        $period = ChallengePeriod::factory()->for($challenge)->atIndex(0)->create([
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHours(2),
        ]);

        $checkIn = $this->submit->tap(enrol($challenge), $challenge, $period->starts_at);

        expect($checkIn->status)->toBe(CheckInStatus::Approved);
    });

    it('refuses a period the rollover already settled', function () {
        $challenge = provenBy(ProofType::Button);
        $actor = enrol($challenge, $participant);

        CheckIn::factory()->on($participant, $challenge->periods()->sole())->missed()->create();

        expect(refusalFor(fn () => $this->submit->tap($actor, $challenge)))
            ->toBe(CheckInRejection::AlreadySettled);
    });
});

/*
 * A quantity challenge is judged on a number, so the submission entry points
 * insist on one — whatever surface they are reached from. The bot asks the
 * question before the proof settles; the Mini App validates on the wire; the
 * guard here is the last line, refusing a null rather than guessing zero.
 */
describe('a quantity challenge', function () {
    /**
     * A quantity challenge proven by `$proofType`, with one open period.
     */
    function quantityProvenBy(ProofType $proofType): Challenge
    {
        $challenge = Challenge::factory()->active()->provenBy($proofType)->quantity()->create();

        ChallengePeriod::factory()->for($challenge)->atIndex(0)->create([
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);

        return $challenge;
    }

    it('refuses a tap with no number behind it', function () {
        $challenge = quantityProvenBy(ProofType::Button);

        expect(refusalFor(fn () => $this->submit->tap(enrol($challenge), $challenge)))
            ->toBe(CheckInRejection::ValueRequired)
            ->and(CheckIn::query()->count())->toBe(0);
    });

    it('settles a tap on the number reported', function () {
        $challenge = quantityProvenBy(ProofType::Button);
        $actor = enrol($challenge, $participant);

        $checkIn = $this->submit->tap($actor, $challenge, reportedValue: '45');

        expect($checkIn->status)->toBe(CheckInStatus::Approved)
            ->and($checkIn->reported_value)->toBe('45.00')
            ->and($checkIn->score)->toBe('150.00')
            ->and($participant->refresh()->total_score)->toBe('150.00');
    });

    it('refuses a phrase with no number riding along', function () {
        $challenge = quantityProvenBy(ProofType::TextAutogen);

        expect(refusalFor(fn () => $this->submit->typePhrase(enrol($challenge), $challenge, 'anything at all')))
            ->toBe(CheckInRejection::ValueRequired)
            ->and(CheckIn::query()->count())->toBe(0);
    });

    it('judges the phrase and the number as one submission', function () {
        $challenge = quantityProvenBy(ProofType::TextAutogen);
        $actor = enrol($challenge, $participant);

        // The phrase a reminder would have delivered, issued directly so the
        // first typed attempt can be judged against the real thing.
        $checkIn = app(OpenCheckIn::class)->handle(
            $participant,
            $challenge->periods()->where('index', 0)->sole(),
        );
        app(IssueCheckInPhrase::class)->handle($checkIn);
        $phrase = $checkIn->expected_phrase;

        expect(refusalFor(fn () => $this->submit->typePhrase($actor, $challenge, $phrase)))
            ->toBe(CheckInRejection::ValueRequired);

        $settled = $this->submit->typePhrase($actor, $challenge, $phrase, reportedValue: 45);

        expect($settled->status)->toBe(CheckInStatus::Approved)
            ->and($settled->score)->toBe('150.00');
    });

    it('refuses a photo with no number on it', function () {
        $challenge = quantityProvenBy(ProofType::ImageApproval);

        expect(refusalFor(fn () => $this->submit->uploadPhoto(enrol($challenge), $challenge, 'proofs/x.jpg')))
            ->toBe(CheckInRejection::ValueRequired)
            ->and(CheckIn::query()->count())->toBe(0);
    });

    it('carries the number onto a photo awaiting review', function () {
        $challenge = quantityProvenBy(ProofType::ImageApproval);
        $actor = enrol($challenge);

        $checkIn = $this->submit->uploadPhoto($actor, $challenge, 'proofs/x.jpg', reportedValue: 20);

        expect($checkIn->status)->toBe(CheckInStatus::Submitted)
            ->and($checkIn->reported_value)->toBe('20.00')
            ->and($checkIn->score)->toBeNull();
    });
});
