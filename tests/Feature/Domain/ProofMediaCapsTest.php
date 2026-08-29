<?php

use App\Actions\Challenges\CreateChallenge;
use App\Actions\Challenges\MaterialiseChallengePeriods;
use App\Actions\CheckIns\AdvanceCheckInStep;
use App\Actions\CheckIns\SubmitCheckIn;
use App\Enums\ChallengeVisibility;
use App\Enums\CheckInRejection;
use App\Enums\CheckInSessionStatus;
use App\Enums\CheckInStatus;
use App\Enums\FlowType;
use App\Enums\PeriodType;
use App\Enums\ProofType;
use App\Enums\SessionRejection;
use App\Enums\SettingKey;
use App\Enums\StepInputType;
use App\Exceptions\CheckInRejectedException;
use App\Exceptions\SessionRejectedException;
use App\Jobs\Challenges\AnnounceChallenge;
use App\Models\Challenge;
use App\Models\ChallengeParticipant;
use App\Models\ChallengePeriod;
use App\Models\ChallengeStep;
use App\Models\CheckIn;
use App\Models\CheckInSession;
use App\Models\Entitlement;
use App\Models\User;
use App\Services\Settings;
use Carbon\CarbonImmutable;
use Database\Factories\ChallengeStepFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

/*
 * The schema half of voice/video proof: the caps a recording challenge must
 * carry, the ceilings those caps live under, and where the caps bite — at
 * creation, and at submission time before a single byte is stored. What is
 * deliberately absent here is anything about AI review or capture surfaces:
 * those are later tasks, and this file must stay green without them.
 */

beforeEach(function () {
    Bus::fake([AnnounceChallenge::class]);

    $this->settings = app(Settings::class);
    $this->settings->set(SettingKey::ProofMediaMaxSeconds, 300);
    $this->settings->set(SettingKey::ProofMediaMaxSizeKb, 20480);

    $this->create = app(CreateChallenge::class);
    $this->creator = User::factory()->telegram()->create();
});

/**
 * Create a challenge, naming only what the test is about.
 *
 * Sibling of `creating()` in CreateChallengeTest under another name — a Pest
 * file's helper functions are global, and two files may not declare the same one.
 *
 * @param  array<string, mixed>  $overrides
 */
function making(array $overrides = []): Challenge
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

/**
 * The creator's free create-slot, spent by every `making()` call that succeeds.
 */
function grantingSlots(): void
{
    Entitlement::factory()->createSlot()->create(['user_id' => test()->creator->getKey()]);
}

/**
 * The reason carried by the rejection `$attempt` throws.
 */
function checkInRefusalFor(Closure $attempt): CheckInRejection
{
    try {
        $attempt();
    } catch (CheckInRejectedException $rejection) {
        return $rejection->reason;
    }

    throw new RuntimeException('Expected the submission to be refused, but it was accepted.');
}

/**
 * A `$proofType` challenge that carries caps, with one open period.
 */
function recordingChallenge(ProofType $proofType): Challenge
{
    $challenge = Challenge::factory()->active()->provenBy($proofType)->create([
        'proof_media_max_seconds' => 60,
        'proof_media_max_size_kb' => 2048,
    ]);

    ChallengePeriod::factory()->for($challenge)->atIndex(0)->create([
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHour(),
    ]);

    return $challenge;
}

/**
 * Enrol a fresh user in `$challenge` as the verified actor.
 */
function enrolledActor(Challenge $challenge): User
{
    $user = User::factory()->telegram()->create(['locale' => 'en']);

    ChallengeParticipant::factory()->for($challenge)->for($user)->create();

    return $user;
}

describe('the new proof types', function () {
    it('treats voice and video as media needing review', function (ProofType $type) {
        expect($type->isMediaApproval())->toBeTrue()
            ->and($type->requiresReview())->toBeTrue()
            ->and($type->isAutoApproved())->toBeFalse()
            ->and($type->expectsFile())->toBeTrue()
            ->and($type->supportsPublicProof())->toBeTrue();
    })->with([
        ProofType::VoiceApproval,
        ProofType::VideoApproval,
    ]);

    it('keeps the established types exactly as they were', function (ProofType $type, bool $file, bool $duration) {
        expect($type->expectsFile())->toBe($file)
            ->and($type->expectsDuration())->toBe($duration);
    })->with([
        'button' => [ProofType::Button, false, false],
        'phrase' => [ProofType::TextAutogen, false, false],
        'photo' => [ProofType::ImageApproval, true, false],
    ]);

    it('creates a voice or video challenge that carries its caps', function (ProofType $type) {
        grantingSlots();

        $challenge = making([
            'proofType' => $type,
            'proofMediaMaxSeconds' => 120,
            'proofMediaMaxSizeKb' => 8192,
        ]);

        expect($challenge->proof_type)->toBe($type)
            ->and($challenge->proof_media_max_seconds)->toBe(120)
            ->and($challenge->proof_media_max_size_kb)->toBe(8192);
    })->with([
        ProofType::VoiceApproval,
        ProofType::VideoApproval,
    ]);
});

describe('caps at creation', function () {
    it('refuses a cap above the admin ceiling', function (string $key, int $value, int $ceiling) {
        grantingSlots();
        test()->settings->set($key === 'proofMediaMaxSeconds' ? SettingKey::ProofMediaMaxSeconds : SettingKey::ProofMediaMaxSizeKb, $ceiling);

        making([
            'proofType' => ProofType::VideoApproval,
            'proofMediaMaxSeconds' => 120,
            'proofMediaMaxSizeKb' => 8192,
            $key => $value,
        ]);
    })->with([
        'duration over ceiling' => ['proofMediaMaxSeconds', 301, 300],
        'size over ceiling' => ['proofMediaMaxSizeKb', 20481, 20480],
    ])->throws(InvalidArgumentException::class);

    it('accepts a cap exactly at the ceiling', function () {
        grantingSlots();

        $challenge = making([
            'proofType' => ProofType::VoiceApproval,
            'proofMediaMaxSeconds' => 300,
            'proofMediaMaxSizeKb' => 20480,
        ]);

        expect($challenge->proof_media_max_seconds)->toBe(300)
            ->and($challenge->proof_media_max_size_kb)->toBe(20480);
    });

    it('refuses a recording challenge with no caps to enforce', function (array $overrides) {
        grantingSlots();

        making(array_replace([
            'proofType' => ProofType::VoiceApproval,
            'proofMediaMaxSeconds' => 120,
            'proofMediaMaxSizeKb' => 8192,
        ], $overrides));
    })->with([
        'no duration cap' => [['proofMediaMaxSeconds' => null]],
        'no size cap' => [['proofMediaMaxSizeKb' => null]],
        'zero duration cap' => [['proofMediaMaxSeconds' => 0]],
    ])->throws(InvalidArgumentException::class);

    it('drops caps a non-recording challenge has no use for', function () {
        grantingSlots();

        $challenge = making([
            'proofType' => ProofType::Button,
            'proofMediaMaxSeconds' => 120,
            'proofMediaMaxSizeKb' => 8192,
        ]);

        // A UI leftover, not bad intent: the caller picked caps and then
        // changed the proof type. Same treatment as a stray day count.
        expect($challenge->proof_media_max_seconds)->toBeNull()
            ->and($challenge->proof_media_max_size_kb)->toBeNull();
    });

    it('leaves image proof exactly as it was: created without caps', function () {
        grantingSlots();

        $challenge = making(['proofType' => ProofType::ImageApproval]);

        expect($challenge->proof_type)->toBe(ProofType::ImageApproval)
            ->and($challenge->proof_media_max_seconds)->toBeNull()
            ->and($challenge->proof_media_max_size_kb)->toBeNull();
    });
});

describe('a video step in a timed session', function () {
    it('requires caps when a step asks for video', function () {
        grantingSlots();

        making([
            'flowType' => FlowType::TimedSession,
            'steps' => videoDesign(),
        ]);
    })->throws(InvalidArgumentException::class);

    it('stores the caps the design forced the creator to pick', function () {
        grantingSlots();

        $challenge = making([
            'flowType' => FlowType::TimedSession,
            'steps' => videoDesign(),
            'proofMediaMaxSeconds' => 120,
            'proofMediaMaxSizeKb' => 8192,
        ]);

        expect($challenge->steps()->where('input_type', StepInputType::Video)->exists())->toBeTrue()
            ->and($challenge->proof_media_max_seconds)->toBe(120);
    });

    it('asks nothing of a voice-step design, which caps itself per step', function () {
        grantingSlots();

        $challenge = making([
            'flowType' => FlowType::TimedSession,
            'steps' => ChallengeStepFactory::design(60),
        ]);

        expect($challenge->proof_media_max_seconds)->toBeNull()
            ->and($challenge->proof_media_max_size_kb)->toBeNull();
    });
});

describe('caps at submission', function () {
    beforeEach(function () {
        // SubmitCheckIn carries the AI-verdict router, which holds the bot
        // messenger; the client binding needs a token even though the
        // recording paths never invoke it.
        config(['services.telegram.bot_token' => '123456:TEST-TOKEN']);

        $this->submit = app(SubmitCheckIn::class);
    });

    it('stores a recording within the caps and leaves it for the creator', function (ProofType $type) {
        $challenge = recordingChallenge($type);
        $actor = enrolledActor($challenge);

        $checkIn = $type === ProofType::VoiceApproval
            ? $this->submit->uploadVoice($actor, $challenge, 'proofs/one.ogg', 59)
            : $this->submit->uploadVideo($actor, $challenge, 'proofs/one.mp4', 59, 2047);

        expect($checkIn->status)->toBe(CheckInStatus::Submitted)
            ->and($checkIn->proof_path)->not->toBeNull()
            ->and($checkIn->submitted_at)->not->toBeNull();
    })->with([
        ProofType::VoiceApproval,
        ProofType::VideoApproval,
    ]);

    it('refuses a recording past the duration cap before storing anything', function (ProofType $type) {
        $challenge = recordingChallenge($type);
        $actor = enrolledActor($challenge);

        expect(checkInRefusalFor(fn () => $type === ProofType::VoiceApproval
            ? $this->submit->uploadVoice($actor, $challenge, 'proofs/one.ogg', 61)
            : $this->submit->uploadVideo($actor, $challenge, 'proofs/one.mp4', 61)))
            ->toBe(CheckInRejection::MediaTooLong)
            ->and(CheckIn::query()->count())->toBe(0);
    })->with([
        ProofType::VoiceApproval,
        ProofType::VideoApproval,
    ]);

    it('refuses a recording past the size cap before storing anything', function (ProofType $type) {
        $challenge = recordingChallenge($type);
        $actor = enrolledActor($challenge);

        expect(checkInRefusalFor(fn () => $type === ProofType::VoiceApproval
            ? $this->submit->uploadVoice($actor, $challenge, 'proofs/one.ogg', 30, 2049)
            : $this->submit->uploadVideo($actor, $challenge, 'proofs/one.mp4', 30, 2049)))
            ->toBe(CheckInRejection::MediaTooLarge)
            ->and(CheckIn::query()->count())->toBe(0);
    })->with([
        ProofType::VoiceApproval,
        ProofType::VideoApproval,
    ]);

    it('accepts a recording the surface could not measure the size of', function () {
        $challenge = recordingChallenge(ProofType::VideoApproval);
        $actor = enrolledActor($challenge);

        $checkIn = $this->submit->uploadVideo($actor, $challenge, 'proofs/one.mp4', 30);

        expect($checkIn->status)->toBe(CheckInStatus::Submitted);
    });
});

describe('a video step at submission', function () {
    beforeEach(function () {
        $this->challenge = Challenge::factory()
            ->active()
            ->timedSession()
            ->timeline(now()->subDay()->startOfDay()->toDateTimeString(), 'UTC', 30)
            ->create([
                'proof_media_max_seconds' => 30,
                'proof_media_max_size_kb' => 1024,
            ]);

        app(MaterialiseChallengePeriods::class)->handle($this->challenge);

        ChallengeStep::factory()->for($this->challenge)->atOrder(1)->waiting(60)->create();
        ChallengeStep::factory()->for($this->challenge)->atOrder(2)->waiting(60)->video()->create();

        $this->participant = ChallengeParticipant::factory()->create([
            'challenge_id' => $this->challenge->getKey(),
        ]);

        $this->now = CarbonImmutable::now('UTC')->startOfSecond();
    });

    /**
     * A session sitting on the video step, its waits long elapsed.
     */
    function sessionOnVideoStep(): CheckInSession
    {
        return CheckInSession::factory()
            ->for(test()->participant, 'participant')
            ->startedAt(test()->now->subMinutes(10))
            ->create([
                'challenge_period_id' => test()->challenge->periods()->where('index', 1)->firstOrFail()->getKey(),
                'current_step_order' => 2,
            ]);
    }

    it('completes the session for a video within the caps', function () {
        $session = sessionOnVideoStep();

        $session = app(AdvanceCheckInStep::class)->handle(
            $session,
            test()->challenge->steps()->where('step_order', 2)->firstOrFail(),
            ['proof_path' => 'steps/2.mp4', 'video_seconds' => 25],
            test()->now,
        );

        expect($session->status)->toBe(CheckInSessionStatus::Completed);
    });

    it('refuses a video past the challenge cap with the numbers it blew past', function () {
        $session = sessionOnVideoStep();

        try {
            app(AdvanceCheckInStep::class)->handle(
                $session,
                test()->challenge->steps()->where('step_order', 2)->firstOrFail(),
                ['proof_path' => 'steps/2.mp4', 'video_seconds' => 45],
                test()->now,
            );
            $this->fail('An over-long video should have been refused.');
        } catch (SessionRejectedException $exception) {
            expect($exception->reason)->toBe(SessionRejection::VideoTooLong)
                ->and($exception->voiceSeconds)->toBe(45)
                ->and($exception->voiceCeiling)->toBe(30);
        }

        expect($session->submissions()->count())->toBe(0)
            ->and($session->fresh()->current_step_order)->toBe(2);
    });

    it('refuses any recording past the challenge size cap', function () {
        $session = sessionOnVideoStep();

        try {
            app(AdvanceCheckInStep::class)->handle(
                $session,
                test()->challenge->steps()->where('step_order', 2)->firstOrFail(),
                ['proof_path' => 'steps/2.mp4', 'video_seconds' => 25, 'media_size_kb' => 2048],
                test()->now,
            );
            $this->fail('An over-large video should have been refused.');
        } catch (SessionRejectedException $exception) {
            expect($exception->reason)->toBe(SessionRejection::MediaTooLarge);
        }

        expect($session->submissions()->count())->toBe(0);
    });
});

/**
 * A valid two-step design whose second step demands video.
 *
 * @return list<array<string, mixed>>
 */
function videoDesign(): array
{
    $steps = ChallengeStepFactory::design(60);
    $steps[1]['input_type'] = StepInputType::Video;
    $steps[1]['voice_max_seconds'] = null;

    return $steps;
}
