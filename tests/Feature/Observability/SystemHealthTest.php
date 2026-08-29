<?php

use App\Actions\Ai\ReviewProofWithAi;
use App\Actions\Observability\RecordExternalCall;
use App\Actions\Observability\SystemHealthSnapshot;
use App\Enums\ApprovalMode;
use App\Enums\ExternalCallOutcome;
use App\Enums\ExternalCallProvider;
use App\Enums\MessagingPlatform;
use App\Enums\ProofType;
use App\Enums\SettingKey;
use App\Messaging\Contracts\MessengerException;
use App\Messaging\PlatformRegistry;
use App\Models\AiCapability;
use App\Models\AiProviderAccount;
use App\Models\Challenge;
use App\Models\ChallengeParticipant;
use App\Models\ChallengePeriod;
use App\Models\CheckIn;
use App\Models\ExternalCallStat;
use App\Models\SchedulerHeartbeat;
use App\Models\User;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/*
 * The System Health page (§3.9): pull, not push. Every number it shows comes
 * from a source that exists independently of Telescope — the heartbeat row,
 * the queue tables, the external-call counters — and only the exceptions list
 * leans on Telescope, degrading to an explicit "no data" state.
 */

it('refuses a non-admin and admits an admin', function (): void {
    $this->get('/admin/system-health')->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())
        ->get('/admin/system-health')
        ->assertForbidden();

    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin/system-health')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Admin/SystemHealth')->has('health.scheduler'));
});

it('judges the heartbeat stale past the threshold, fresh inside it, and never-ran as stale', function (): void {
    $health = app(SystemHealthSnapshot::class);

    // Never ran: a fresh install and a dead cron look identical, and the
    // safe reading is the alarming one.
    expect($health->build()['scheduler']['healthy'])->toBeFalse();

    SchedulerHeartbeat::factory()->ranMinutesAgo(2)->create();
    expect($health->build()['scheduler']['healthy'])->toBeTrue();

    SchedulerHeartbeat::query()->delete();
    SchedulerHeartbeat::factory()->ranMinutesAgo(10)->create();
    expect($health->build()['scheduler']['healthy'])->toBeFalse();

    // The bar itself is a Setting, not a hardcode.
    app(Settings::class)->set(SettingKey::HeartbeatStalenessMinutes, 30);
    expect($health->build()['scheduler']['healthy'])->toBeTrue();
});

it('re-queues a retried failed job and discards a discarded one', function (): void {
    $admin = User::factory()->admin()->create();

    $insert = function (): string {
        $uuid = (string) Str::uuid();

        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\Test']),
            'exception' => "RuntimeException: boom\n\n#0 somewhere",
            'failed_at' => now()->toDateTimeString(),
        ]);

        return $uuid;
    };

    $retryable = $insert();
    $this->actingAs($admin)
        ->post("/admin/system-health/failed-jobs/{$retryable}/retry")
        ->assertRedirect(route('admin.system-health.index'));

    // Retry moves the row back onto the queue under its uuid's payload id.
    expect(DB::table('failed_jobs')->where('uuid', $retryable)->exists())->toBeFalse()
        ->and(DB::table('jobs')->count())->toBe(1);

    $discardable = $insert();
    $this->actingAs($admin)
        ->post("/admin/system-health/failed-jobs/{$discardable}/discard")
        ->assertRedirect(route('admin.system-health.index'));

    expect(DB::table('failed_jobs')->where('uuid', $discardable)->exists())->toBeFalse()
        ->and(DB::table('jobs')->count())->toBe(1);
});

it('counts an external call success and failure for every provider a surface touches', function (ExternalCallProvider $provider, ExternalCallOutcome $outcome): void {
    $record = app(RecordExternalCall::class);

    $record->handle($provider, $outcome);
    $record->handle($provider, $outcome);
    $record->handle($provider, ExternalCallOutcome::from($outcome->value === 'success' ? 'failure' : 'success'));

    $row = ExternalCallStat::query()
        ->where('provider', $provider)
        ->where('day', today()->toDateString())
        ->where('outcome', $outcome)
        ->sole();

    expect($row->count)->toBe(2);
})->with([
    'telegram' => [ExternalCallProvider::Telegram, ExternalCallOutcome::Success],
    'bale' => [ExternalCallProvider::Bale, ExternalCallOutcome::Success],
    'telegram stars' => [ExternalCallProvider::TelegramStars, ExternalCallOutcome::Failure],
    'bale pay' => [ExternalCallProvider::BalePay, ExternalCallOutcome::Failure],
    'ai provider' => [ExternalCallProvider::AiProvider, ExternalCallOutcome::Success],
]);

it('counts messenger outcomes where they happen — through the registry\'s counting skin', function (): void {
    // One fake, answer switched in place: a second Http::fake() merges with
    // first-match-wins, so the refusal below must reuse this stub.
    test()->telegramOk = true;

    Http::fake([
        '*sendMessage*' => fn () => test()->telegramOk
            ? Http::response(['ok' => true, 'result' => ['message_id' => 1]])
            : Http::response(['ok' => false, 'error_code' => 500, 'description' => 'Internal'], 500),
    ]);

    app(PlatformRegistry::class)->for(MessagingPlatform::Telegram)->sendMessage(1, 'hello');

    // A refused call counts as a failure and the refusal itself survives the
    // counting — the decorator must be transparent, not a swallow.
    test()->telegramOk = false;

    try {
        app(PlatformRegistry::class)->for(MessagingPlatform::Telegram)->sendMessage(1, 'hello');
        $this->fail('The refusal should have survived the counting skin.');
    } catch (MessengerException) {
    }

    expect(ExternalCallStat::query()->where('provider', 'telegram')->where('outcome', 'success')->sole()->count)->toBe(1)
        ->and(ExternalCallStat::query()->where('provider', 'telegram')->where('outcome', 'failure')->sole()->count)->toBe(1);
});

it('counts an AI review success and failure from the review chain', function (): void {
    config(['services.telegram.bot_token' => '123456:TEST-TOKEN']);

    $settings = app(Settings::class);
    $settings->set(SettingKey::AiApprovalGloballyEnabled, true);
    $settings->set(SettingKey::AiApprovalAllowedImage, true);

    Storage::disk('local')->put('check-in-proofs/health/run.jpg', "\xFF\xD8\xFF\xE0".'jpeg-bytes');

    $challenge = Challenge::factory()
        ->active()
        ->provenBy(ProofType::ImageApproval)
        ->create(['approval_mode' => ApprovalMode::Ai]);

    ChallengePeriod::factory()->for($challenge)->atIndex(0)->create([
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHour(),
    ]);

    $participant = ChallengeParticipant::factory()->for($challenge)
        ->for(User::factory()->telegram()->create(['locale' => 'en']))
        ->create();

    $capability = AiCapability::query()->where('key', AiCapability::KEY_PROOF_MODERATION)->firstOrFail();
    $capability->update(['is_active' => true]);

    AiProviderAccount::factory()->configured()->atUrl('https://moderation.example/v1')->create([
        'ai_capability_id' => $capability->getKey(),
        'sort_order' => 0,
    ]);

    $submission = CheckIn::factory()->on($participant, $challenge->periods()->sole())
        ->create(['proof_path' => 'check-in-proofs/health/run.jpg']);

    // One answered review, then one where every provider is unreachable —
    // the same fake serves both, answer switched in place (a second
    // Http::fake() would merge first-match-wins and keep serving the first).
    test()->verdictContent = (string) json_encode(['approved' => true, 'confidence' => 95, 'reason' => 'Runner.']);
    test()->moderationOk = true;

    Http::fake([
        '*sendMessage*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        'https://moderation.example/*' => fn () => test()->moderationOk
            ? Http::response([
                'model' => 'vision-model',
                'choices' => [['message' => ['role' => 'assistant', 'content' => test()->verdictContent]]],
                'usage' => ['prompt_tokens' => 400, 'completion_tokens' => 30],
            ])
            : Http::response(status: 500),
    ]);

    app(ReviewProofWithAi::class)->review($submission, $challenge);

    $answered = CheckIn::factory()->on($participant, ChallengePeriod::factory()->for($challenge)->atIndex(1)->create())
        ->create(['proof_path' => 'check-in-proofs/health/run.jpg']);

    test()->moderationOk = false;

    app(ReviewProofWithAi::class)->review($answered, $challenge);

    expect(ExternalCallStat::query()->where('provider', 'ai_provider')->where('outcome', 'success')->sole()->count)->toBe(1)
        ->and(ExternalCallStat::query()->where('provider', 'ai_provider')->where('outcome', 'failure')->sole()->count)->toBe(1);
});

it('degrades the exceptions panel to an explicit no-data state when Telescope has nothing', function (): void {
    config(['telescope.enabled' => false]);

    $health = app(SystemHealthSnapshot::class)->build();

    expect($health['exceptions'])->toBe(['available' => false, 'rows' => []]);

    // Enabled but empty: available, honestly empty — not a broken-looking table.
    config(['telescope.enabled' => true]);

    $health = app(SystemHealthSnapshot::class)->build();

    expect($health['exceptions']['available'])->toBeTrue()
        ->and($health['exceptions']['rows'])->toBe([]);
});

it('lists every provider in the counters, zeros included, over a rolling window', function (): void {
    ExternalCallStat::factory()->create([
        'provider' => ExternalCallProvider::Bale,
        'outcome' => ExternalCallOutcome::Failure,
        'count' => 7,
    ]);

    // Outside the 7-day window: must not count.
    ExternalCallStat::factory()->create([
        'provider' => ExternalCallProvider::Telegram,
        'outcome' => ExternalCallOutcome::Failure,
        'count' => 99,
        'day' => today()->subDays(10),
    ]);

    $providers = collect(app(SystemHealthSnapshot::class)->providers(7))->keyBy('provider');

    expect($providers)->toHaveCount(count(ExternalCallProvider::cases()))
        ->and($providers['bale']['failure'])->toBe(7)
        ->and($providers['bale']['success'])->toBe(0)
        ->and($providers['telegram']['failure'])->toBe(0)
        ->and($providers['telegram_stars']['success'])->toBe(0);
});
