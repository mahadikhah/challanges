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
use App\Exceptions\SessionRejectedException;
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
use App\Services\Telegram\BotCallback;
use App\Services\Telegram\BotMessenger;
use App\Services\Telegram\Callbacks\ReviewCheckInCallback;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

/*
 * The creator's half of the sentence the participant already hears.
 *
 * A session that falls back to the manual queue tells the participant "in for
 * review" — and until this, told the person doing the reviewing nothing at all.
 * The media, the buttons and the verdict engine are the simple flow's, shared
 * through `ProofReviewNotifier`, so what is under test here is the *arrival*:
 * that this path reaches the notifier, once, and that a session whose row never
 * waits on a human stays silent.
 */

dataset('session media kinds', ['image' => ['image'], 'voice' => ['voice'], 'video' => ['video']]);

it('sends the creator the session proof itself, with both verdict buttons', function (string $kind): void {
    sessionAiModerationCapability();
    sessionAiProviderAnswers('Something about running?', verdictForSession(true, 40, 'Too unclear to say.'));
    theCreatorCanBeSentMedia();

    [$challenge, $participant, $period, $step, $path] = aiSessionForKind($kind);
    $session = anAiReviewSession($participant, $period, $this->now);

    app(AdvanceCheckInStep::class)->handle($session, $step, sessionSubmissionFor($kind, $path), $this->now);

    $creator = $challenge->creator;

    $checkIn = CheckIn::query()
        ->where('challenge_participant_id', $participant->getKey())
        ->where('challenge_period_id', $period->getKey())
        ->firstOrFail();

    // The row really is the manual queue's, which is what the notification is
    // about: an approved session has nothing for the creator to decide.
    expect($checkIn->status)->toBe(CheckInStatus::Submitted)
        ->and($checkIn->proof_path)->toBe($path)
        // Guards the fixture itself: a path the notifier cannot classify
        // silently degrades to text, and the media assertion below would then
        // be measuring the fallback.
        ->and($checkIn->proofKind())->toBe($kind);

    $sent = botMediaMessages();

    expect($sent)->toHaveCount(1)
        ->and($sent[0]['endpoint'])->toBe(mediaEndpointFor($kind))
        ->and($sent[0]['fields']['chat_id'])->toBe((string) $creator->platform_user_id)
        // The bytes, not a description of them.
        ->and($sent[0]['fields'][mediaEndpointFor($kind)])->toContain("{$kind}-proof-bytes")
        ->and($sent[0]['fields']['caption'])->toBe(botCopy("bot.checkin.review_prompt_{$kind}", [
            'name' => $participant->user->first_name ?? $participant->user->name,
            'title' => $challenge->title,
        ], app(BotMessenger::class)->localeFor($creator)))
        ->and(verdictData($sent[0]['fields']))->toBe([
            "rv:{$checkIn->getKey()}:a",
            "rv:{$checkIn->getKey()}:r",
        ]);
})->with('session media kinds');

it('notifies the creator in words when the session proof is gone from disk', function (): void {
    sessionAiModerationCapability();
    sessionAiProviderAnswers('Something about running?', verdictForSession(true, 40, 'Too unclear to say.'));
    theCreatorCanBeSentMedia();
    Log::spy();

    [, $participant, $period, $step, $path] = aiSessionForKind('voice');
    Storage::disk('local')->delete($path);

    $session = anAiReviewSession($participant, $period, $this->now);

    app(AdvanceCheckInStep::class)->handle($session, $step, sessionSubmissionFor('voice', $path), $this->now);

    // No media send at all, and the creator still holds the decision: a
    // sentence with the buttons on it beats learning nothing.
    expect(botMediaMessages())->toBeEmpty()
        ->and(botMessages())->toHaveCount(1)
        ->and(verdictsOn(botMessages()[0]))->toHaveCount(2);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'missing from disk'))
        ->once();
});

it('settles a session submission when the creator taps the button the bot minted', function (): void {
    sessionAiModerationCapability();
    sessionAiProviderAnswers('Something about running?', verdictForSession(true, 40, 'Too unclear to say.'));
    theCreatorCanBeSentMedia();

    [$challenge, $participant, $period, $step, $path] = aiSessionForKind('voice');
    $session = anAiReviewSession($participant, $period, $this->now);

    app(AdvanceCheckInStep::class)->handle($session, $step, sessionSubmissionFor('voice', $path), $this->now);

    $creator = $challenge->creator;

    // Not mass-assignable by design, and the gate re-verifies at the tap
    // rather than trusting whenever the notification was sent.
    $creator->forceFill(['channel_verified_at' => now()])->save();

    $checkIn = CheckIn::query()
        ->where('challenge_participant_id', $participant->getKey())
        ->where('challenge_period_id', $period->getKey())
        ->firstOrFail();

    // The payload the bot actually sent, parsed back — so this asserts the
    // minted button routes to the verdict engine, not that a callback fired.
    $data = verdictData(botMediaMessages()[0]['fields'])[0];

    app(ReviewCheckInCallback::class)->handle($creator->refresh(), BotCallback::parse($data));

    // The verdict is the shared one: the same status, streak and reviewer a
    // simple-flow tap leaves behind, on a row a session produced.
    expect($checkIn->refresh()->status)->toBe(CheckInStatus::Approved)
        ->and($checkIn->reviewed_by)->toBe($creator->getKey())
        ->and($participant->refresh()->current_streak)->toBe(1)
        ->and($session->refresh()->status)->toBe(CheckInSessionStatus::Completed);
});

it('says nothing when the verdict approves the session itself', function (): void {
    sessionAiModerationCapability();
    sessionAiProviderAnswers('I ran five kilometres before breakfast.', verdictForSession(true, 95, 'The run is described.'));
    theCreatorCanBeSentMedia();

    [, $participant, $period, $step, $path] = aiSessionForKind('voice');
    $session = anAiReviewSession($participant, $period, $this->now);

    app(AdvanceCheckInStep::class)->handle($session, $step, sessionSubmissionFor('voice', $path), $this->now);

    $checkIn = CheckIn::query()
        ->where('challenge_participant_id', $participant->getKey())
        ->where('challenge_period_id', $period->getKey())
        ->firstOrFail();

    expect($checkIn->status)->toBe(CheckInStatus::Approved)
        ->and(botMediaMessages())->toBeEmpty();
});

it('says nothing when the verdict rejects, because the period can be retried', function (): void {
    sessionAiModerationCapability();
    sessionAiProviderAnswers('I have no idea.', verdictForSession(false, 95, 'Not this morning at all.'));
    theCreatorCanBeSentMedia();

    [, $participant, $period, $step, $path] = aiSessionForKind('voice');
    $session = anAiReviewSession($participant, $period, $this->now);

    app(AdvanceCheckInStep::class)->handle($session, $step, sessionSubmissionFor('voice', $path), $this->now);

    $checkIn = CheckIn::query()
        ->where('challenge_participant_id', $participant->getKey())
        ->where('challenge_period_id', $period->getKey())
        ->firstOrFail();

    // The row is not a human's to act on yet — the participant may resubmit
    // the same period, so notifying the creator would only invite a refusal.
    expect($checkIn->status)->toBe(CheckInStatus::Rejected)
        ->and(botMediaMessages())->toBeEmpty();
});

it('says nothing for a manual-mode session, which approves its own completion', function (): void {
    sessionAiModerationCapability();
    theCreatorCanBeSentMedia();

    [$challenge, $participant, $period, $step, $path] = aiSessionForKind('voice');
    $challenge->update(['approval_mode' => ApprovalMode::Manual]);

    $session = anAiReviewSession($participant, $period, $this->now);

    app(AdvanceCheckInStep::class)->handle($session, $step, sessionSubmissionFor('voice', $path), $this->now);

    $checkIn = CheckIn::query()
        ->where('challenge_participant_id', $participant->getKey())
        ->where('challenge_period_id', $period->getKey())
        ->firstOrFail();

    // Settled, so there is nothing to review — and no gate was opened for a
    // decision nobody has to make.
    expect($checkIn->status)->toBe(CheckInStatus::Approved)
        ->and(botMediaMessages())->toBeEmpty();
});

it('does not notify a second time when the final step is replayed', function (): void {
    sessionAiModerationCapability();
    sessionAiProviderAnswers('Something about running?', verdictForSession(true, 40, 'Too unclear to say.'));
    theCreatorCanBeSentMedia();

    [, $participant, $period, $step, $path] = aiSessionForKind('voice');
    $session = anAiReviewSession($participant, $period, $this->now);

    app(AdvanceCheckInStep::class)->handle($session, $step, sessionSubmissionFor('voice', $path), $this->now);

    expect(botMediaMessages())->toHaveCount(1);

    // A replayed message or a duplicated update arrives as a second call. The
    // session is closed by then, so the refusal happens *inside* the
    // transaction — and a rolled-back attempt owes the creator nothing.
    try {
        app(AdvanceCheckInStep::class)->handle($session->refresh(), $step, sessionSubmissionFor('voice', $path), $this->now);
    } catch (SessionRejectedException) {
        // Asserted below.
    }

    expect(botMediaMessages())->toHaveCount(1);
});

/**
 * The extension the upload pipeline writes for one proof kind, and therefore
 * the extension `CheckIn::proofKind()` reads back.
 */
function sessionProofExtension(string $kind): string
{
    return match ($kind) {
        'image' => 'jpg',
        'video' => 'mp4',
        default => 'ogg',
    };
}

/**
 * The Bot API endpoint one proof kind travels through — `photo`, `voice`,
 * `video`. The notifier's own vocabulary is `CheckIn::proofKind()`'s, which
 * says `image` for the thing `sendPhoto` uploads.
 */
function mediaEndpointFor(string $kind): string
{
    return $kind === 'image' ? 'photo' : $kind;
}

/**
 * A challenge whose single, final step demands one media kind and whose
 * completion passes through the verdict router — the smallest session that can
 * end in the manual queue.
 *
 * @return array{0: Challenge, 1: ChallengeParticipant, 2: ChallengePeriod, 3: ChallengeStep, 4: string}
 */
function aiSessionForKind(string $kind): array
{
    // A *real* extension, because `CheckIn::proofKind()` reads it: a fixture
    // named `session.voice` is a path the notifier cannot classify, and every
    // assertion below would pass against the text fallback instead of the send
    // it is meant to be checking. `sessionProofKind()` pins it.
    $path = 'check-in-proofs/ai/session.'.sessionProofExtension($kind);

    Storage::disk('local')->put($path, "{$kind}-proof-bytes");

    $challenge = Challenge::factory()
        ->active()
        ->timedSession()
        ->timeline(now()->subDay()->startOfDay()->toDateTimeString(), 'UTC', 30)
        ->provenBy(match ($kind) {
            'image' => ProofType::ImageApproval,
            'video' => ProofType::VideoApproval,
            default => ProofType::VoiceApproval,
        })
        ->create([
            'approval_mode' => ApprovalMode::Ai,
            'approval_criteria' => 'The runner says what they did this morning, out loud.',
            'proof_media_max_seconds' => 120,
            'proof_media_max_size_kb' => 4096,
        ]);

    app(MaterialiseChallengePeriods::class)->handle($challenge);

    $step = match ($kind) {
        'image' => ChallengeStep::factory()->for($challenge)->atOrder(1)->waiting(60)->image()->create(),
        'video' => ChallengeStep::factory()->for($challenge)->atOrder(1)->waiting(60)->video()->create(),
        default => ChallengeStep::factory()->for($challenge)->atOrder(1)->waiting(60)->voice(30)->create(),
    };

    $participant = ChallengeParticipant::factory()->for($challenge)->create();

    /** @var ChallengePeriod $period */
    $period = $challenge->periods()->where('index', 1)->firstOrFail();

    return [$challenge, $participant, $period, $step, $path];
}

/**
 * The submission array one media kind's step demands.
 *
 * @return array{proof_path: string, voice_seconds?: int, video_seconds?: int, media_size_kb: int}
 */
function sessionSubmissionFor(string $kind, string $path): array
{
    $submission = ['proof_path' => $path, 'media_size_kb' => 300];

    return match ($kind) {
        'voice' => $submission + ['voice_seconds' => 20],
        'video' => $submission + ['video_seconds' => 20],
        default => $submission,
    };
}

/**
 * Answer the three media endpoints, so a proof the creator never receives
 * fails this suite instead of straying into a real Bot API.
 *
 * A test that only stubs `sendMessage` proves nothing about the media path:
 * the multipart upload raises a stray-request refusal, the notifier swallows
 * it as a send failure, and the text fallback passes the assertion for the
 * wrong reason.
 */
function theCreatorCanBeSentMedia(): void
{
    Http::fake([
        '*sendPhoto*' => Http::response(['ok' => true, 'result' => ['message_id' => 7]]),
        '*sendVoice*' => Http::response(['ok' => true, 'result' => ['message_id' => 7]]),
        '*sendVideo*' => Http::response(['ok' => true, 'result' => ['message_id' => 7]]),
    ]);
}

/**
 * The verdict buttons' callback payloads, in the order they were laid out.
 *
 * @param  array<string, string>  $message
 * @return list<string>
 */
function verdictData(array $message): array
{
    return array_map(
        static fn (array $button): string => $button['callback_data'],
        verdictsOn($message),
    );
}

/**
 * The buttons on a message, rows flattened into the order they were laid out.
 *
 * @param  array<string, string>  $message
 * @return list<array<string, string>>
 */
function verdictsOn(array $message): array
{
    return array_merge([], ...keyboardOn($message));
}

function verdictForSession(bool $approved, float $confidence, string $reason): string
{
    return (string) json_encode([
        'approved' => $approved,
        'confidence' => $confidence,
        'reason' => $reason,
    ]);
}
