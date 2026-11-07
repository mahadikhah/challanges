<?php

use App\Actions\Ai\ApplyAiVerdict;
use App\Actions\Challenges\CreateChallenge;
use App\Enums\ApprovalMode;
use App\Enums\ChallengeVisibility;
use App\Enums\CheckInStatus;
use App\Enums\PeriodType;
use App\Enums\ProofType;
use App\Enums\SettingKey;
use App\Models\ChallengeParticipant;
use App\Models\CheckIn;
use App\Models\Entitlement;
use App\Models\User;
use App\Services\Ai\AiApprovalGate;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
 * The §2.11 boundary: an admin decides whether AI approval exists at all and
 * for which media type; a creator decides everything else. What these tests
 * pin down is the two-switch rule — global AND per-type — and that it bites
 * at creation, at submission time, and nowhere else.
 */

beforeEach(function (): void {
    config(['services.telegram.bot_token' => '123456:TEST-TOKEN']);

    $this->settings = app(Settings::class);
    $this->creator = User::factory()->telegram()->create();

    // No bot or provider may be reached from this file.
    Http::fake();
    Http::preventStrayRequests();
});

/**
 * Flip the AI approval gates.
 */
function aiApprovalGates(bool $global, bool $image, bool $voice = false, bool $video = false): void
{
    $settings = app(Settings::class);
    $settings->set(SettingKey::AiApprovalGloballyEnabled, $global);
    $settings->set(SettingKey::AiApprovalAllowedImage, $image);
    $settings->set(SettingKey::AiApprovalAllowedVoice, $voice);
    $settings->set(SettingKey::AiApprovalAllowedVideo, $video);
}

describe('the gate itself', function () {
    it('allows nothing by default — every axis ships off', function () {
        $gate = app(AiApprovalGate::class);

        expect($gate->allows(ProofType::ImageApproval))->toBeFalse()
            ->and($gate->allows(ProofType::VoiceApproval))->toBeFalse()
            ->and($gate->allows(ProofType::VideoApproval))->toBeFalse()
            ->and($gate->allows(ProofType::Button))->toBeFalse();
    });

    it('needs both switches: global alone or per-type alone is not enough', function () {
        aiApprovalGates(global: true, image: false);
        expect(app(AiApprovalGate::class)->allows(ProofType::ImageApproval))->toBeFalse();

        aiApprovalGates(global: false, image: true);
        expect(app(AiApprovalGate::class)->allows(ProofType::ImageApproval))->toBeFalse();

        aiApprovalGates(global: true, image: true);
        expect(app(AiApprovalGate::class)->allows(ProofType::ImageApproval))->toBeTrue();
    });

    it('allows one media type without allowing the others', function () {
        aiApprovalGates(global: true, image: true);

        $gate = app(AiApprovalGate::class);

        expect($gate->allows(ProofType::ImageApproval))->toBeTrue()
            ->and($gate->allows(ProofType::VoiceApproval))->toBeFalse()
            ->and($gate->allows(ProofType::VideoApproval))->toBeFalse();
    });
});

describe('creation refuses what the gate refuses', function () {
    it('refuses approval_mode = ai with the global switch off, whatever the per-type flags', function () {
        aiApprovalGates(global: false, image: true, voice: true, video: true);

        foreach ([ProofType::ImageApproval, ProofType::VoiceApproval, ProofType::VideoApproval] as $type) {
            Entitlement::factory()->createSlot()->create(['user_id' => $this->creator->getKey()]);

            app(CreateChallenge::class)->handle(
                creator: $this->creator,
                title: 'Morning run',
                description: null,
                periodType: PeriodType::Daily,
                customPeriodDays: null,
                startsAt: now()->addDay(),
                totalPeriods: 7,
                timezone: 'UTC',
                proofType: $type,
                visibility: ChallengeVisibility::InviteOnly,
                proofMediaMaxSeconds: $type->expectsDuration() ? 120 : null,
                proofMediaMaxSizeKb: $type->expectsDuration() ? 4096 : null,
                approvalMode: ApprovalMode::Ai,
                approvalCriteria: 'The proof shows what it should.',
            );
        }
    })->throws(InvalidArgumentException::class, 'AI review is not available');

    it('allows an image challenge but refuses a voice challenge under image-only permissions', function () {
        aiApprovalGates(global: true, image: true, voice: false);

        Entitlement::factory()->count(2)->createSlot()->create(['user_id' => $this->creator->getKey()]);

        $image = app(CreateChallenge::class)->handle(
            creator: $this->creator,
            title: 'Photo run',
            description: null,
            periodType: PeriodType::Daily,
            customPeriodDays: null,
            startsAt: now()->addDay(),
            totalPeriods: 7,
            timezone: 'UTC',
            proofType: ProofType::ImageApproval,
            visibility: ChallengeVisibility::InviteOnly,
            approvalMode: ApprovalMode::Ai,
            approvalCriteria: 'A photo of the runner outdoors.',
        );

        expect($image->approval_mode)->toBe(ApprovalMode::Ai);

        app(CreateChallenge::class)->handle(
            creator: $this->creator,
            title: 'Voice run',
            description: null,
            periodType: PeriodType::Daily,
            customPeriodDays: null,
            startsAt: now()->addDay(),
            totalPeriods: 7,
            timezone: 'UTC',
            proofType: ProofType::VoiceApproval,
            visibility: ChallengeVisibility::InviteOnly,
            proofMediaMaxSeconds: 120,
            proofMediaMaxSizeKb: 4096,
            approvalMode: ApprovalMode::Ai,
            approvalCriteria: 'The runner says what they did.',
        );
    })->throws(InvalidArgumentException::class, 'AI review is not available for voice_approval');
});

describe('the runtime gate', function () {
    it('routes a submission to the manual queue when permission was withdrawn after creation', function () {
        // Created while allowed — the row keeps its honest history.
        aiApprovalGates(global: true, image: true);

        Entitlement::factory()->createSlot()->create(['user_id' => $this->creator->getKey()]);

        $challenge = app(CreateChallenge::class)->handle(
            creator: $this->creator,
            title: 'Morning run',
            description: null,
            periodType: PeriodType::Daily,
            customPeriodDays: null,
            startsAt: now()->subDay(),
            totalPeriods: 7,
            timezone: 'UTC',
            proofType: ProofType::ImageApproval,
            visibility: ChallengeVisibility::InviteOnly,
            approvalMode: ApprovalMode::Ai,
            approvalCriteria: 'A photo of the runner outdoors.',
        );

        // The action materialised the timeline; the factory period below would
        // collide with it.
        $participant = ChallengeParticipant::factory()->for($challenge)->create();

        $checkIn = CheckIn::factory()
            ->on($participant, $challenge->periods()->first())
            ->submitted()
            ->create();

        // The admin flips the per-type switch off between submissions.
        aiApprovalGates(global: true, image: false);

        app(ApplyAiVerdict::class)->handle($checkIn);

        expect($checkIn->fresh()->status)->toBe(CheckInStatus::Submitted)
            // The provider chain was never entered — no AI call was attempted.
            ->and(Http::recorded())->toBeEmpty();
    });
});
