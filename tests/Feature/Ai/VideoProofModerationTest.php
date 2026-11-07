<?php

use App\Actions\Ai\BuildProofModerationPrompt;
use App\Actions\Challenges\MaterialiseChallengePeriods;
use App\Actions\CheckIns\AdvanceCheckInStep;
use App\Actions\CheckIns\SubmitCheckIn;
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
use App\Models\User;
use App\Services\Ai\FfmpegDetector;
use App\Services\Media\VideoFrameSampler;
use App\Services\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

const VIDEO_AI_HOST = 'https://video-moderation.example/v1';
const VIDEO_PROOF_PATH = 'check-in-proofs/ai/run.mp4';

/**
 * The environment as the tests need it: ffmpeg's presence is a property of
 * the host, so the detector is swapped for one the test controls. Without
 * this the suite's verdicts would depend on whether the CI container ships
 * ffmpeg — which is exactly the environment-dependence the feature itself
 * has to be honest about.
 */
function theEnvironmentHasFfmpeg(bool $present): void
{
    app()->instance(FfmpegDetector::class, new class($present) extends FfmpegDetector
    {
        public function __construct(private readonly bool $present) {}

        public function present(): bool
        {
            return $this->present;
        }
    });
}

/**
 * The frame leg as the tests need it: instead of really calling ffmpeg, the
 * stub writes the promised number of scratch frames and hands them to the
 * callback, deleting them afterwards exactly like the real sampler. The
 * files must genuinely exist — the client seam reads them to attach them.
 */
function frameExtractionAnswers(int $frames = VideoFrameSampler::FRAME_COUNT): void
{
    app()->instance(VideoFrameSampler::class, new class($frames) extends VideoFrameSampler
    {
        public function __construct(private readonly int $count) {}

        public function withFrames(string $path, callable $callback, string $disk = 'local'): mixed
        {
            $paths = [];

            foreach (range(1, $this->count) as $index) {
                $file = sys_get_temp_dir().'/video-frame-test-'.uniqid('', true).'.jpg';
                file_put_contents($file, 'jpeg-bytes');
                $paths[] = $file;
            }

            try {
                return $callback($paths);
            } finally {
                foreach ($paths as $file) {
                    @unlink($file);
                }
            }
        }
    });
}

/**
 * Frame extraction that dies where the real one can: an unreadable video, a
 * missing binary mid-run. The provider is never reached.
 */
function frameExtractionFails(): void
{
    app()->instance(VideoFrameSampler::class, new class extends VideoFrameSampler
    {
        public function withFrames(string $path, callable $callback, string $disk = 'local'): mixed
        {
            throw new RuntimeException('The video duration could not be read.');
        }
    });
}

function videoModerationCapability(): void
{
    // Self-healing rather than firstOrFail: the migration seeds this row,
    // but the Domain concurrency suites truncate every table without
    // re-seeding, so the seed may be gone depending on run order.
    $capability = AiCapability::query()->firstOrCreate(
        ['key' => AiCapability::KEY_PROOF_MODERATION],
        ['label' => 'Proof moderation', 'purpose' => AiCapabilityPurpose::Vision],
    );
    $capability->update(['is_active' => true]);

    AiProviderAccount::factory()->configured()->atUrl(VIDEO_AI_HOST)->create([
        'ai_capability_id' => $capability->getKey(),
        'sort_order' => 0,
    ]);
}

/**
 * The chat leg: one endpoint, one verdict, read at request time so a later
 * call can change the answer (the fake merges stubs, first match wins).
 */
function videoProviderAnswers(string $verdictContent): void
{
    test()->videoVerdictContent = $verdictContent;

    Http::fake([
        '*sendMessage*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        VIDEO_AI_HOST.'/*' => function () {
            return Http::response([
                'model' => 'video-model',
                'choices' => [['message' => ['role' => 'assistant', 'content' => test()->videoVerdictContent]]],
                'usage' => ['prompt_tokens' => 400, 'completion_tokens' => 20],
            ]);
        },
    ]);
}

function videoVerdictJson(bool $approved, float $confidence, string $reason): string
{
    return (string) json_encode([
        'approved' => $approved,
        'confidence' => $confidence,
        'reason' => $reason,
    ]);
}

/**
 * The last moderation request body, as decoded JSON.
 *
 * @return array<string, mixed>
 */
function theVideoModerationRequest(): array
{
    [$request] = collect(Http::recorded())
        ->last(fn ($pair) => str_contains((string) $pair[0]->url(), 'chat/completions'));

    return json_decode((string) $request->body(), true);
}

/**
 * An `approval_mode = ai` video-approval challenge with one open period, the
 * stored recording on disk, and a freshly enrolled participant.
 *
 * @return array{0: Challenge, 1: User, 2: ChallengeParticipant}
 */
function videoReviewedChallenge(string $criteria = 'The video shows the morning workout, start to finish.'): array
{
    Storage::disk('local')->put(VIDEO_PROOF_PATH, 'mp4-bytes');

    $challenge = Challenge::factory()
        ->active()
        ->provenBy(ProofType::VideoApproval)
        ->create([
            'approval_mode' => ApprovalMode::Ai,
            'approval_criteria' => $criteria,
            'proof_media_max_seconds' => 120,
            'proof_media_max_size_kb' => 4096,
        ]);

    ChallengePeriod::factory()->for($challenge)->atIndex(0)->create([
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHour(),
    ]);

    $user = User::factory()->telegram()->create(['locale' => 'en']);
    $participant = ChallengeParticipant::factory()->for($challenge)->for($user)->create();

    return [$challenge, $user, $participant];
}

function submittingVideo(User $actor, Challenge $challenge): CheckIn
{
    return app(SubmitCheckIn::class)->uploadVideo($actor, $challenge, VIDEO_PROOF_PATH, 20, 300);
}

beforeEach(function (): void {
    config(['services.telegram.bot_token' => '123456:TEST-TOKEN']);
    // The panel tests render Inertia; SSR would phone the Vite dev server,
    // which `preventStrayRequests` rightly refuses.
    config(['inertia.ssr.enabled' => false]);

    $settings = app(Settings::class);
    $settings->set(SettingKey::AiApprovalGloballyEnabled, true);
    $settings->set(SettingKey::AiApprovalAllowedVideo, true);

    // Deterministic environment: ffmpeg is whatever this test needs, never
    // whatever the container happens to ship.
    theEnvironmentHasFfmpeg(true);

    Http::fake(['*sendMessage*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
    Http::preventStrayRequests();
});

/*
 * The frame path itself: extract, then judge the set.
 */

it('extracts evenly-spaced frames and judges the set through the locked schema', function (): void {
    videoModerationCapability();
    frameExtractionAnswers();
    videoProviderAnswers(videoVerdictJson(true, 95, 'The workout is shown.'));

    [$challenge, $actor] = videoReviewedChallenge();
    submittingVideo($actor, $challenge);

    $body = theVideoModerationRequest();
    $messages = collect($body['messages']);
    $rawUser = $messages->firstWhere('role', 'user')['content'];

    // A multimodal user turn: the text part plus one image part per frame.
    $user = is_string($rawUser)
        ? $rawUser
        : (string) collect((array) $rawUser)->firstWhere('type', 'text')['text'];

    // The video system prompt — not the photo one, not the voice one — the
    // frame count stated in the user turn, the criteria fenced, and every
    // extracted frame riding along as its own image part. Counted from the
    // decoded parts: the JSON spelling of one image part repeats the key
    // twice (`"type":"image_url","image_url":{...}`).
    expect($messages->firstWhere('role', 'system')['content'])
        ->toBe(BuildProofModerationPrompt::VIDEO_SYSTEM_PROMPT)
        ->and($user)->toContain(VideoFrameSampler::FRAME_COUNT.' evenly-spaced stills')
        ->and($user)->toContain('<criteria>')
        ->and($user)->toContain('The video shows the morning workout, start to finish.')
        ->and(collect((array) $rawUser)->where('type', 'image_url')->count())
        ->toBe(VideoFrameSampler::FRAME_COUNT);

    $decision = AiApprovalDecision::query()->sole();

    expect($decision->review_path)->toBe(AiReviewPath::Frames)
        ->and($decision->transcript)->toBeNull();
});

/*
 * Verdicts apply with the same downstream effects as the image and voice
 * flows.
 */

it('applies a high-confidence video approval and matches manual approval downstream', function (): void {
    videoModerationCapability();
    frameExtractionAnswers();
    videoProviderAnswers(videoVerdictJson(true, 95, 'The workout is shown.'));

    [$challenge, $actor, $participant] = videoReviewedChallenge();

    $checkIn = submittingVideo($actor, $challenge);

    expect($checkIn->refresh()->status)->toBe(CheckInStatus::Approved)
        ->and($participant->refresh()->current_streak)->toBe(1)
        ->and($participant->refresh()->longest_streak)->toBe(1)
        ->and($checkIn->refresh()->reviewed_by)->toBeNull()
        ->and(AiApprovalDecision::query()->sole()->outcome)->toBe(AiDecisionOutcome::Applied);
});

it('applies a high-confidence video rejection to the resubmittable state', function (): void {
    videoModerationCapability();
    frameExtractionAnswers();
    videoProviderAnswers(videoVerdictJson(false, 90, 'Nothing is shown.'));

    [$challenge, $actor, $participant] = videoReviewedChallenge();

    $checkIn = submittingVideo($actor, $challenge);

    expect($checkIn->refresh()->status)->toBe(CheckInStatus::Rejected)
        ->and($participant->refresh()->current_streak)->toBe(0)
        ->and($checkIn->refresh()->status->allowsSubmission())->toBeTrue();

    // A better video restarts the cycle.
    videoProviderAnswers(videoVerdictJson(true, 95, 'The workout is shown.'));

    $resubmitted = submittingVideo($actor, $challenge);

    expect($resubmitted->refresh()->status)->toBe(CheckInStatus::Approved);
});

it('routes a below-threshold video verdict to the manual queue', function (): void {
    videoModerationCapability();
    frameExtractionAnswers();
    videoProviderAnswers(videoVerdictJson(true, 40, 'Too unclear to say.'));

    [$challenge, $actor] = videoReviewedChallenge();

    $checkIn = submittingVideo($actor, $challenge);

    expect($checkIn->refresh()->status)->toBe(CheckInStatus::Submitted)
        ->and(AiApprovalDecision::query()->sole()->outcome)->toBe(AiDecisionOutcome::FellBack);
});

it('falls back to the manual queue when the sampling itself fails', function (): void {
    videoModerationCapability();
    frameExtractionFails();
    videoProviderAnswers(videoVerdictJson(true, 95, 'Never reached.'));

    [$challenge, $actor] = videoReviewedChallenge();

    $checkIn = submittingVideo($actor, $challenge);

    // The chat leg never ran: an unwatchable video is a human's to look at.
    expect($checkIn->refresh()->status)->toBe(CheckInStatus::Submitted)
        ->and(AiApprovalDecision::query()->sole()->approved)->toBeNull();

    expect(collect(Http::recorded())
        ->contains(fn ($pair) => str_contains((string) $pair[0]->url(), 'chat/completions')))
        ->toBeFalse();
});

/*
 * The environment gate: without ffmpeg and a video-accepting provider, no
 * video is ever routed to AI review — and the admin toggle refuses to
 * pretend otherwise.
 */

it('never routes video to AI review when the environment cannot review it', function (): void {
    theEnvironmentHasFfmpeg(false);

    // The gate says yes — enabled before the host lost its ffmpeg, say. The
    // runtime re-check is what protects the submission.
    [$challenge, $actor] = videoReviewedChallenge();

    $checkIn = submittingVideo($actor, $challenge);

    expect($checkIn->refresh()->status)->toBe(CheckInStatus::Submitted)
        ->and(AiApprovalDecision::query()->count())->toBe(0);

    expect(collect(Http::recorded())
        ->contains(fn ($pair) => str_contains((string) $pair[0]->url(), 'video-moderation.example')))
        ->toBeFalse();
});

it('refuses to enable the video toggle the environment cannot honour', function (): void {
    theEnvironmentHasFfmpeg(false);
    videoModerationCapability();

    // Start from the registry default (off): the beforeEach override would
    // otherwise already say true, and the refusal under test is the update.
    app(Settings::class)->forget(SettingKey::AiApprovalAllowedVideo);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->from('/admin/settings/ai')
        ->put('/admin/settings/ai_approval_allowed_video', ['value' => true])
        ->assertInvalid('value');

    expect(app(Settings::class)->boolean(SettingKey::AiApprovalAllowedVideo))->toBeFalse();
});

it('reports the video capability honestly in the settings panel', function (): void {
    $admin = User::factory()->admin()->create();

    // No provider at all: the remedy an admin can act on.
    theEnvironmentHasFfmpeg(true);

    $this->actingAs($admin)->get('/admin/settings/ai')->assertInertia(
        fn ($page) => $page
            ->where('aiCapabilities.video.available', false)
            ->where('aiCapabilities.video.reason', 'no_provider'),
    );

    // A provider, but a host without the toolchain: the host's remedy.
    videoModerationCapability();
    theEnvironmentHasFfmpeg(false);

    $this->actingAs($admin)->get('/admin/settings/ai')->assertInertia(
        fn ($page) => $page
            ->where('aiCapabilities.video.available', false)
            ->where('aiCapabilities.video.reason', 'no_toolchain'),
    );

    // Both halves present: available.
    theEnvironmentHasFfmpeg(true);

    $this->actingAs($admin)->get('/admin/settings/ai')->assertInertia(
        fn ($page) => $page->where('aiCapabilities.video.available', true),
    );
});

/*
 * The session call site: a timed-session video step rides the same router.
 */

it('reviews a timed-session video step through the same router and completes on approval', function (): void {
    videoModerationCapability();
    frameExtractionAnswers();
    videoProviderAnswers(videoVerdictJson(true, 95, 'The workout is shown.'));

    Storage::disk('local')->put(VIDEO_PROOF_PATH, 'mp4-bytes');

    $challenge = Challenge::factory()
        ->active()
        ->timedSession()
        ->timeline(now()->subDay()->startOfDay()->toDateTimeString(), 'UTC', 30)
        ->provenBy(ProofType::VideoApproval)
        ->create([
            'approval_mode' => ApprovalMode::Ai,
            'approval_criteria' => 'The video shows the workout.',
            'proof_media_max_seconds' => 120,
            'proof_media_max_size_kb' => 4096,
        ]);

    app(MaterialiseChallengePeriods::class)->handle($challenge);

    ChallengeStep::factory()->for($challenge)->atOrder(1)->waiting(60)->video()->create();

    $participant = ChallengeParticipant::factory()->for($challenge)->create();

    /** @var ChallengePeriod $period */
    $period = $challenge->periods()->where('index', 1)->firstOrFail();

    $now = CarbonImmutable::now('UTC')->startOfSecond();

    $session = CheckInSession::factory()
        ->for($participant, 'participant')
        ->startedAt($now->subMinutes(10))
        ->create([
            'challenge_period_id' => $period->getKey(),
            'current_step_order' => 1,
        ]);

    $result = app(AdvanceCheckInStep::class)->handle($session, $challenge->steps()->firstOrFail(), [
        'proof_path' => VIDEO_PROOF_PATH,
        'video_seconds' => 20,
        'media_size_kb' => 300,
    ], $now);

    $checkIn = CheckIn::query()
        ->where('challenge_participant_id', $participant->getKey())
        ->where('challenge_period_id', $period->getKey())
        ->firstOrFail();

    // The same shape of completion a manual session produces: session done,
    // check-in settled, streak moved once, no human named.
    expect($result->status)->toBe(CheckInSessionStatus::Completed)
        ->and($checkIn->status)->toBe(CheckInStatus::Approved)
        ->and($participant->refresh()->current_streak)->toBe(1)
        ->and(AiApprovalDecision::query()->sole()->review_path)->toBe(AiReviewPath::Frames);
});
