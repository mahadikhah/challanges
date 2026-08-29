<?php

use App\Actions\Ai\ApplyAiVerdict;
use App\Actions\CheckIns\SettleCheckIn;
use App\Actions\Payments\RefundStarsPayment;
use App\Enums\ApprovalMode;
use App\Enums\CheckInStatus;
use App\Enums\CoinTransactionReason;
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
use App\Models\StarPayment;
use App\Models\User;
use App\Services\CoinLedger;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
 * The file-log conventions (addendum-4 §2.10): the moves a human will come
 * back for — a settlement, a coin movement, an AI decision, a refund — each
 * leave one info line carrying the identifiers a search in Log Viewer needs,
 * a diverted verdict leaves a warning, and a provider refusal leaves an
 * error. Spot-checks, not an exhaustive map.
 */

it('logs a settlement with the identifiers that make it findable', function (): void {
    Log::spy();

    $challenge = Challenge::factory()->active()->create();
    ChallengePeriod::factory()->for($challenge)->atIndex(0)->create([
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHour(),
    ]);
    $participant = ChallengeParticipant::factory()->for($challenge)
        ->for(User::factory()->telegram()->create())
        ->create();

    $checkIn = CheckIn::factory()->on($participant, $challenge->periods()->sole())->create();

    app(SettleCheckIn::class)->approve($checkIn);

    expect($checkIn->refresh()->status)->toBe(CheckInStatus::Approved);

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context): bool => $message === 'A check-in was settled.'
            && $context['check_in_id'] === $checkIn->getKey()
            && $context['challenge_id'] === $challenge->getKey()
            && $context['participant_id'] === $participant->getKey()
            && $context['status'] === 'approved')
        ->once();
});

it('logs every coin movement with its ledger identity, and never a replay', function (): void {
    Log::spy();

    $user = User::factory()->create();

    app(CoinLedger::class)->credit(
        $user,
        50,
        CoinTransactionReason::StarsPurchase,
        'stars:charge-test-1',
    );

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context): bool => $message === 'A coin ledger entry was written.'
            && $context['user_id'] === $user->getKey()
            && $context['amount'] === 50
            && $context['reason'] === 'stars_purchase'
            && $context['balance_after'] === 50
            && $context['idempotency_key'] === 'stars:charge-test-1')
        ->once();

    // A replay finds the original entry and returns it — the line count
    // reconciles against the ledger, so the second call logs nothing.
    app(CoinLedger::class)->credit(
        $user,
        50,
        CoinTransactionReason::StarsPurchase,
        'stars:charge-test-1',
    );

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context): bool => $message === 'A coin ledger entry was written.'
            && $context['idempotency_key'] === 'stars:charge-test-1')
        ->once();
});

it('logs an AI verdict that settled a proof, and warns on a below-threshold one', function (): void {
    config(['services.telegram.bot_token' => '123456:TEST-TOKEN']);

    $settings = app(Settings::class);
    $settings->set(SettingKey::AiApprovalGloballyEnabled, true);
    $settings->set(SettingKey::AiApprovalAllowedImage, true);

    Storage::disk('local')->put('check-in-proofs/logging/run.jpg', "\xFF\xD8\xFF\xE0".'jpeg-bytes');

    $challenge = Challenge::factory()
        ->active()
        ->provenBy(ProofType::ImageApproval)
        ->create(['approval_mode' => ApprovalMode::Ai]);

    foreach ([0, 1] as $index) {
        ChallengePeriod::factory()->for($challenge)->atIndex($index)->create([
            'starts_at' => now()->subHours(2 - $index),
            'ends_at' => now()->addHours(2 - $index),
        ]);
    }

    $participant = ChallengeParticipant::factory()->for($challenge)
        ->for(User::factory()->telegram()->create(['locale' => 'en']))
        ->create();

    $capability = AiCapability::query()
        ->where('key', AiCapability::KEY_PROOF_MODERATION)
        ->firstOrFail();
    $capability->update(['is_active' => true]);

    AiProviderAccount::factory()->configured()->atUrl('https://moderation.example/v1')->create([
        'ai_capability_id' => $capability->getKey(),
        'sort_order' => 0,
    ]);

    // The verdict content is read at request time from the test instance:
    // a second Http::fake() merges stubs with first-match-wins, so a
    // re-faked URL would silently keep serving the first verdict.
    test()->verdictContent = (string) json_encode([
        'approved' => true,
        'confidence' => 40,
        'reason' => 'Blurry.',
    ]);

    Http::fake([
        '*sendMessage*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        'https://moderation.example/*' => fn () => Http::response([
            'model' => 'vision-model',
            'choices' => [['message' => ['role' => 'assistant', 'content' => test()->verdictContent]]],
            'usage' => ['prompt_tokens' => 400, 'completion_tokens' => 30],
        ]),
    ]);

    Log::spy();

    // Below the 80% threshold: the verdict exists but diverts to the manual
    // queue, which is the fallback a warning belongs on. (The relation
    // already orders by index, so first()/last() on the loaded collection
    // pick the earliest and latest periods without fighting that order.)
    $periods = $challenge->periods()->orderBy('index')->get();

    $diverted = CheckIn::factory()->on($participant, $periods->first())
        ->create(['proof_path' => 'check-in-proofs/logging/run.jpg', 'status' => CheckInStatus::Submitted]);

    app(ApplyAiVerdict::class)->handle($diverted);

    expect($diverted->refresh()->status)->toBe(CheckInStatus::Submitted);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'An AI verdict fell below the confidence threshold and went to the manual queue.'
            && $context['check_in_id'] === $diverted->getKey()
            && $context['confidence'] === 40.0)
        ->once();

    // A settling verdict on the next period: no second warning fires, and
    // the info line names what the model decided.
    test()->verdictContent = (string) json_encode([
        'approved' => true,
        'confidence' => 95,
        'reason' => 'Runner visible outdoors.',
    ]);

    $settled = CheckIn::factory()->on($participant, $periods->last())
        ->create(['proof_path' => 'check-in-proofs/logging/run.jpg', 'status' => CheckInStatus::Submitted]);

    app(ApplyAiVerdict::class)->handle($settled);

    expect($settled->refresh()->status)->toBe(CheckInStatus::Approved);

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context): bool => $message === 'An AI verdict settled a proof.'
            && $context['check_in_id'] === $settled->getKey()
            && $context['approved'] === true
            && $context['confidence'] === 95.0)
        ->once();

    // The approval also travelled the shared settlement machinery, whose own
    // info line fired alongside — naming it keeps Mockery's call routing from
    // counting the settlement against the verdict line above.
    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context): bool => $message === 'A check-in was settled.'
            && $context['check_in_id'] === $settled->getKey()
            && $context['status'] === 'approved')
        ->once();
});

it('logs a provider\'s refund refusal as an error, with the charge it refused', function (): void {
    Log::spy();

    $payment = StarPayment::factory()->paid()->create();

    $this->mock(PlatformRegistry::class)
        ->shouldReceive('for')
        ->andThrow(new MessengerException('The platform refused the refund.'));

    try {
        app(RefundStarsPayment::class)->handle($payment);
    } catch (MessengerException) {
        // The refusal propagates so the row stays retryable; the log is the
        // file-side trace of the attempt.
    }

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context): bool => $message === 'A payment refund was refused by the platform.'
            && $context['star_payment_id'] === $payment->getKey()
            && $context['charge_id'] === $payment->telegram_payment_charge_id)
        ->once();
});
