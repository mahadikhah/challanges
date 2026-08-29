<?php

use App\Actions\Challenges\MaterialiseChallengePeriods;
use App\Actions\CheckIns\AdvanceCheckInStep;
use App\Enums\AiCapabilityPurpose;
use App\Enums\AiDecisionOutcome;
use App\Enums\AiReviewPath;
use App\Enums\ApprovalMode;
use App\Enums\CheckInSessionStatus;
use App\Enums\CheckInStatus;
use App\Enums\ProofType;
use App\Enums\SettingKey;
use App\Models\AiApprovalDecision;
use App\Models\AiCapability;
use App\Models\AiProviderAccount;
use App\Models\Challenge;
use App\Models\ChallengeParticipant;
use App\Models\ChallengePeriod;
use App\Models\ChallengeStep;
use App\Models\CheckIn;
use App\Models\CheckInSession;
use App\Services\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

const SESSION_AI_HOST = 'https://session-moderation.example/v1';
const SESSION_VOICE_PATH = 'check-in-proofs/ai/session.ogg';

/**
 * The same two-leg voice fake as the simple-flow suite, namespaced to this
 * file: closures read the latest state at request time because a later
 * `Http::fake()` merges stubs — first match wins.
 */
function sessionAiProviderAnswers(string $transcript, string $verdictContent): void
{
    test()->sessionTranscript = $transcript;
    test()->sessionVerdict = $verdictContent;

    Http::fake([
        '*sendMessage*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        SESSION_AI_HOST.'/audio/transcriptions' => function () {
            return Http::response([
                'text' => test()->sessionTranscript,
                'usage' => ['input_tokens' => 120, 'output_tokens' => 0],
            ]);
        },
        SESSION_AI_HOST.'/*' => function () {
            return Http::response([
                'model' => 'session-model',
                'choices' => [['message' => ['role' => 'assistant', 'content' => test()->sessionVerdict]]],
                'usage' => ['prompt_tokens' => 300, 'completion_tokens' => 20],
            ]);
        },
    ]);
}

function sessionAiModerationCapability(): void
{
    // Self-healing rather than firstOrFail: the migration seeds this row,
    // but the concurrency suites in this directory truncate every table
    // without re-seeding, so depending on the run order the seed may already
    // be gone. The migration itself inserts with firstOrCreate, so this is
    // the same idempotent shape.
    $capability = AiCapability::query()->firstOrCreate(
        ['key' => AiCapability::KEY_PROOF_MODERATION],
        ['label' => 'Proof moderation', 'purpose' => AiCapabilityPurpose::Vision],
    );
    $capability->update(['is_active' => true]);

    AiProviderAccount::factory()->configured()->atUrl(SESSION_AI_HOST)->create([
        'ai_capability_id' => $capability->getKey(),
        'sort_order' => 0,
    ]);
}

/**
 * An `approval_mode = ai` timed-session challenge proven by voice: one voice
 * step is the whole design, so the first (and final) submission completes the
 * session under review.
 *
 * @return array{0: Challenge, 1: ChallengeParticipant, 2: ChallengePeriod, 3: ChallengeStep}
 */
function aiVoiceSessionChallenge(): array
{
    Storage::disk('local')->put(SESSION_VOICE_PATH, 'OggS'.'opus-bytes');

    $challenge = Challenge::factory()
        ->active()
        ->timedSession()
        ->timeline(now()->subDay()->startOfDay()->toDateTimeString(), 'UTC', 30)
        ->provenBy(ProofType::VoiceApproval)
        ->create([
            'approval_mode' => ApprovalMode::Ai,
            'approval_criteria' => 'The runner says what they did this morning, out loud.',
            'proof_media_max_seconds' => 120,
            'proof_media_max_size_kb' => 4096,
        ]);

    app(MaterialiseChallengePeriods::class)->handle($challenge);

    $step = ChallengeStep::factory()->for($challenge)->atOrder(1)->waiting(60)->voice(30)->create();

    $participant = ChallengeParticipant::factory()->for($challenge)->create();

    /** @var ChallengePeriod $period */
    $period = $challenge->periods()->where('index', 1)->firstOrFail();

    return [$challenge, $participant, $period, $step];
}

/**
 * The session waiting on its only step, started well before the test clock.
 */
function anAiReviewSession(ChallengeParticipant $participant, ChallengePeriod $period, CarbonImmutable $now): CheckInSession
{
    return CheckInSession::factory()
        ->for($participant, 'participant')
        ->startedAt($now->subMinutes(10))
        ->create([
            'challenge_period_id' => $period->getKey(),
            'current_step_order' => 1,
        ]);
}

beforeEach(function (): void {
    config(['services.telegram.bot_token' => '123456:TEST-TOKEN']);

    $settings = app(Settings::class);
    $settings->set(SettingKey::AiApprovalGloballyEnabled, true);
    $settings->set(SettingKey::AiApprovalAllowedVoice, true);

    // To the whole second, as the session suite does.
    $this->now = CarbonImmutable::now('UTC')->startOfSecond();

    Http::fake(['*sendMessage*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
    Http::preventStrayRequests();
});

/*
 * The final step of an AI-reviewed session submits for review — it does not
 * auto-approve.
 */

it('settles an approved verdict and completes the session through the ordinary path', function (): void {
    sessionAiModerationCapability();
    sessionAiProviderAnswers('I ran five kilometres before breakfast.', verdictForSession(true, 95, 'The run is described.'));

    [, $participant, $period, $step] = aiVoiceSessionChallenge();
    $session = anAiReviewSession($participant, $period, $this->now);

    $result = app(AdvanceCheckInStep::class)->handle($session, $step, [
        'proof_path' => SESSION_VOICE_PATH,
        'voice_seconds' => 20,
        'media_size_kb' => 300,
    ], $this->now);

    $checkIn = CheckIn::query()
        ->where('challenge_participant_id', $participant->getKey())
        ->where('challenge_period_id', $period->getKey())
        ->firstOrFail();

    // The session completed, the check-in settled Approved with the evidence
    // attached, the streak moved exactly once — the same downstream state a
    // manual session completion produces.
    expect($result->status)->toBe(CheckInSessionStatus::Completed)
        ->and($checkIn->status)->toBe(CheckInStatus::Approved)
        ->and($checkIn->proof_path)->toBe(SESSION_VOICE_PATH)
        ->and($participant->refresh()->current_streak)->toBe(1)
        ->and($checkIn->reviewed_by)->toBeNull();

    $decision = AiApprovalDecision::query()->sole();

    expect($decision->outcome)->toBe(AiDecisionOutcome::Applied)
        ->and($decision->review_path)->toBe(AiReviewPath::Transcript);
});

it('lands a high-confidence rejection on the resubmittable state and keeps the session honestly closed', function (): void {
    sessionAiModerationCapability();
    sessionAiProviderAnswers('Silence, mostly.', verdictForSession(false, 90, 'Nothing was said.'));

    [, $participant, $period, $step] = aiVoiceSessionChallenge();
    $session = anAiReviewSession($participant, $period, $this->now);

    $result = app(AdvanceCheckInStep::class)->handle($session, $step, [
        'proof_path' => SESSION_VOICE_PATH,
        'voice_seconds' => 20,
        'media_size_kb' => 300,
    ], $this->now);

    $checkIn = CheckIn::query()
        ->where('challenge_participant_id', $participant->getKey())
        ->where('challenge_period_id', $period->getKey())
        ->firstOrFail();

    // The steps were genuinely run, so the session is completed — but the
    // check-in is Rejected, not approved by the completion it bypassed, and
    // the period can be retried with a fresh session.
    expect($result->status)->toBe(CheckInSessionStatus::Completed)
        ->and($result->current_step_order)->toBeNull()
        ->and($checkIn->status)->toBe(CheckInStatus::Rejected)
        ->and($participant->refresh()->current_streak)->toBe(0)
        ->and($checkIn->status->allowsSubmission())->toBeTrue();
});

it('routes a below-threshold verdict to the manual queue instead of approving the session', function (): void {
    sessionAiModerationCapability();
    sessionAiProviderAnswers('Something about running?', verdictForSession(true, 40, 'Too unclear to say.'));

    [, $participant, $period, $step] = aiVoiceSessionChallenge();
    $session = anAiReviewSession($participant, $period, $this->now);

    $result = app(AdvanceCheckInStep::class)->handle($session, $step, [
        'proof_path' => SESSION_VOICE_PATH,
        'voice_seconds' => 20,
        'media_size_kb' => 300,
    ], $this->now);

    $checkIn = CheckIn::query()
        ->where('challenge_participant_id', $participant->getKey())
        ->where('challenge_period_id', $period->getKey())
        ->firstOrFail();

    expect($result->status)->toBe(CheckInSessionStatus::Completed)
        ->and($checkIn->status)->toBe(CheckInStatus::Submitted)
        ->and($participant->refresh()->current_streak)->toBe(0)
        ->and(AiApprovalDecision::query()->sole()->outcome)->toBe(AiDecisionOutcome::FellBack);
});

it('keeps the manual queue the destination when no provider can answer', function (): void {
    sessionAiModerationCapability();
    Http::fake([
        '*sendMessage*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        SESSION_AI_HOST.'/*' => Http::response(['error' => ['message' => 'down']], 500),
    ]);

    [, $participant, $period, $step] = aiVoiceSessionChallenge();
    $session = anAiReviewSession($participant, $period, $this->now);

    $result = app(AdvanceCheckInStep::class)->handle($session, $step, [
        'proof_path' => SESSION_VOICE_PATH,
        'voice_seconds' => 20,
        'media_size_kb' => 300,
    ], $this->now);

    $checkIn = CheckIn::query()
        ->where('challenge_participant_id', $participant->getKey())
        ->where('challenge_period_id', $period->getKey())
        ->firstOrFail();

    expect($result->status)->toBe(CheckInSessionStatus::Completed)
        ->and($checkIn->status)->toBe(CheckInStatus::Submitted)
        ->and(AiApprovalDecision::query()->sole()->approved)->toBeNull();
});

/*
 * Gating and the ordinary flow it must not disturb.
 */

it('respects the voice gate: a withdrawn media type lands in the manual queue with no provider called', function (): void {
    app(Settings::class)->set(SettingKey::AiApprovalAllowedVoice, false);

    [, $participant, $period, $step] = aiVoiceSessionChallenge();
    $session = anAiReviewSession($participant, $period, $this->now);

    $result = app(AdvanceCheckInStep::class)->handle($session, $step, [
        'proof_path' => SESSION_VOICE_PATH,
        'voice_seconds' => 20,
        'media_size_kb' => 300,
    ], $this->now);

    $checkIn = CheckIn::query()
        ->where('challenge_participant_id', $participant->getKey())
        ->where('challenge_period_id', $period->getKey())
        ->firstOrFail();

    expect($result->status)->toBe(CheckInSessionStatus::Completed)
        ->and($checkIn->status)->toBe(CheckInStatus::Submitted);

    expect(collect(Http::recorded())
        ->contains(fn ($pair) => str_contains((string) $pair[0]->url(), 'session-moderation.example')))
        ->toBeFalse();
});

it('leaves manual-mode sessions exactly as they were: final step approves', function (): void {
    // No AI gates, no provider — the pre-Task-3 flow, unchanged.
    [, $participant, $period, $step] = aiVoiceSessionChallenge();
    $participant->challenge->update(['approval_mode' => ApprovalMode::Manual]);

    $session = anAiReviewSession($participant, $period, $this->now);

    $result = app(AdvanceCheckInStep::class)->handle($session, $step, [
        'proof_path' => SESSION_VOICE_PATH,
        'voice_seconds' => 20,
        'media_size_kb' => 300,
    ], $this->now);

    $checkIn = CheckIn::query()
        ->where('challenge_participant_id', $participant->getKey())
        ->where('challenge_period_id', $period->getKey())
        ->firstOrFail();

    expect($result->status)->toBe(CheckInSessionStatus::Completed)
        ->and($checkIn->status)->toBe(CheckInStatus::Approved)
        ->and($participant->refresh()->current_streak)->toBe(1)
        ->and(AiApprovalDecision::query()->count())->toBe(0);
});

it('uses the session\'s own evidence: the reviewed media is the last proof-bearing submission', function (): void {
    sessionAiModerationCapability();
    sessionAiProviderAnswers('I ran five kilometres before breakfast.', verdictForSession(true, 95, 'The run is described.'));

    // A two-step design — tap, then voice — so the reviewed evidence is the
    // voice submission, not the button tap that never carried proof.
    [$challenge, $participant, $period] = aiVoiceSessionChallenge();
    $challenge->steps()->delete();
    ChallengeStep::factory()->for($challenge)->atOrder(1)->waiting(0)->create();
    ChallengeStep::factory()->for($challenge)->atOrder(2)->waiting(0)->voice(30)->create();

    $session = anAiReviewSession($participant, $period, $this->now);

    $button = $challenge->steps()->where('step_order', 1)->firstOrFail();
    $voice = $challenge->steps()->where('step_order', 2)->firstOrFail();

    $session = app(AdvanceCheckInStep::class)->handle($session, $button, [], $this->now);
    $result = app(AdvanceCheckInStep::class)->handle($session, $voice, [
        'proof_path' => SESSION_VOICE_PATH,
        'voice_seconds' => 20,
        'media_size_kb' => 300,
    ], $this->now);

    $checkIn = CheckIn::query()
        ->where('challenge_participant_id', $participant->getKey())
        ->where('challenge_period_id', $period->getKey())
        ->firstOrFail();

    expect($result->status)->toBe(CheckInSessionStatus::Completed)
        ->and($checkIn->proof_path)->toBe(SESSION_VOICE_PATH);
});

function verdictForSession(bool $approved, float $confidence, string $reason): string
{
    return (string) json_encode([
        'approved' => $approved,
        'confidence' => $confidence,
        'reason' => $reason,
    ]);
}
