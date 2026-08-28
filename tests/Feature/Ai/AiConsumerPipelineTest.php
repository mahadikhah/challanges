<?php

use App\Actions\Ai\RunAiProviderChainAction;
use App\Enums\AiReservationStatus;
use App\Enums\AiUsageOutcome;
use App\Models\AiCapability;
use App\Models\AiProviderAccount;
use App\Models\AiUsageRecord;
use App\Models\AiUsageReservation;
use App\Services\Ai\AiOperationIdentity;
use App\Services\Ai\AiTextClient;
use App\Services\Ai\AiTextResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const HOST = 'https://sole.example/v1';

function soleAccount(AiCapability $capability): AiProviderAccount
{
    return AiProviderAccount::factory()->configured()->atUrl(HOST)->create([
        'ai_capability_id' => $capability->getKey(),
        'sort_order' => 0,
    ]);
}

/**
 * The chain action fires on every queue attempt; the queue guarantees
 * at-least-once delivery, so a duplicate delivery of a settled run is a
 * normal event, not an error state.
 */
function runSoleOperation(AiCapability $capability, AiOperationIdentity $identity): string
{
    return app(RunAiProviderChainAction::class)(
        $capability->key,
        $identity,
        fn (string $connection, ?string $model): AiTextResult => app(AiTextClient::class)
            ->prompt($connection, $model, 'Say something useful.'),
    )->text;
}

beforeEach(function (): void {
    Http::preventStrayRequests();

    $this->capability = AiCapability::factory()->active()->create(['key' => 'sole_capability_'.uniqid()]);
    $this->account = soleAccount($this->capability);
});

it('settles a happy-path run: one reservation reconciled, one ledger row, the account credited', function (): void {
    Http::fake([HOST.'/*' => Http::response([
        'model' => 'primary-model',
        'choices' => [['message' => ['role' => 'assistant', 'content' => 'sole answer']]],
        'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 40],
    ])]);

    $text = runSoleOperation($this->capability, AiOperationIdentity::create('text_operation'));

    $reservation = AiUsageReservation::query()->sole();
    $record = AiUsageRecord::query()->sole();

    expect($text)->toBe('sole answer')
        ->and($reservation->status)->toBe(AiReservationStatus::Reconciled)
        ->and($reservation->consumed_total_tokens)->toBe(160)
        ->and($reservation->usage_known)->toBeTrue()
        ->and($record->ai_usage_record_id ?? $record)->not->toBeNull()
        ->and($reservation->ai_usage_record_id)->toBe($record->getKey())
        ->and($record->input_tokens)->toBe(120)
        ->and($this->account->refresh()->last_succeeded_at)->not->toBeNull();
});

it('refuses a re-delivery of a settled run instead of silently re-spending it', function (): void {
    Http::fake([HOST.'/*' => Http::response([
        'model' => 'primary-model',
        'choices' => [['message' => ['role' => 'assistant', 'content' => 'sole answer']]],
        'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 40],
    ])]);

    $identity = AiOperationIdentity::create('text_operation');

    runSoleOperation($this->capability, $identity);

    expect(fn () => runSoleOperation($this->capability, $identity))
        ->toThrow(RuntimeException::class);

    Http::assertSentCount(1); // the second delivery made no provider call

    expect(AiUsageReservation::query()->count())->toBe(1)
        ->and(AiUsageRecord::query()->count())->toBe(1);
});

it('releases the lease and records the failure when the provider call throws, so a retry starts clean', function (): void {
    Http::fake([HOST.'/*' => Http::response(['error' => ['message' => 'provider on fire']], 500)]);

    $identity = AiOperationIdentity::create('text_operation');

    expect(fn () => runSoleOperation($this->capability, $identity))
        ->toThrow(RequestException::class);

    $reservation = AiUsageReservation::query()->sole();
    $record = AiUsageRecord::query()->sole();

    expect($reservation->status)->toBe(AiReservationStatus::Released)
        ->and($reservation->released_total_tokens)->toBe($reservation->reserved_total_tokens)
        ->and($record->outcome)->toBe(AiUsageOutcome::ProviderFailed)
        ->and($record->metadata['exception_class'])->not->toBeEmpty();
});
