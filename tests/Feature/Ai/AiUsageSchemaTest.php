<?php

use App\Enums\AiCapabilityPurpose;
use App\Enums\AiReservationStatus;
use App\Enums\AiUsageRecordStatus;
use App\Models\AiCapability;
use App\Models\AiProviderAccount;
use App\Models\AiUsageRecord;
use App\Models\AiUsageReservation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // The three fixed rows arrive with the migration; RefreshDatabase
    // re-runs it, so this only asserts they are there.
    expect(AiCapability::query()->count())->toBe(3)
        ->and(AiCapability::query()->where('is_active', true)->exists())->toBeFalse();
});

it('seeds the three fixed capability rows, all switched off', function (): void {
    $keys = AiCapability::query()->pluck('key')->all();

    expect($keys)->toContain(AiCapability::KEY_CRITERIA_GENERATION)
        ->and($keys)->toContain(AiCapability::KEY_CRITERIA_SCREENING)
        ->and($keys)->toContain(AiCapability::KEY_PROOF_MODERATION)
        ->and(AiCapability::query()->where('key', AiCapability::KEY_PROOF_MODERATION)->first()->purpose)
        ->toBe(AiCapabilityPurpose::Vision);
});

it('refuses updates and deletes after a record reaches a terminal status', function (AiUsageRecordStatus $status): void {
    $record = AiUsageRecord::factory()->create(['status' => $status]);

    expect(fn () => $record->update(['duration_ms' => 999]))
        ->toThrow(LogicException::class)
        ->and(fn () => $record->delete())
        ->toThrow(LogicException::class);
})->with([
    'succeeded' => [AiUsageRecordStatus::Succeeded],
    'failed' => [AiUsageRecordStatus::Failed],
    'no_usage' => [AiUsageRecordStatus::NoUsage],
]);

it('lets a pending record update, or the guard would be untestably strict', function (): void {
    $record = AiUsageRecord::factory()->create(['status' => AiUsageRecordStatus::Pending]);

    expect($record->update(['duration_ms' => 42]))->toBeTrue();
});

it('rejects a duplicate idempotency key on both ledger tables', function (): void {
    $record = AiUsageRecord::factory()->create(['idempotency_key' => 'dupe']);

    expect(fn () => AiUsageRecord::factory()->create(['idempotency_key' => 'dupe']))
        ->toThrow(UniqueConstraintViolationException::class);

    $account = AiProviderAccount::factory()->create();

    AiUsageReservation::factory()->for($account, 'account')->create(['idempotency_key' => 'dupe-res']);

    expect(fn () => AiUsageReservation::factory()->for($account, 'account')->create(['idempotency_key' => 'dupe-res']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('keeps a failed or no-usage record alive with no account attached', function (AiUsageRecordStatus $status): void {
    $record = AiUsageRecord::factory()->create([
        'status' => $status,
        'ai_provider_account_id' => null,
        'ai_capability_id' => null,
    ]);

    expect($record->fresh())->not->toBeNull()
        ->and($record->ai_provider_account_id)->toBeNull()
        ->and($record->ai_capability_id)->toBeNull();
})->with([
    'failed' => [AiUsageRecordStatus::Failed],
    'no_usage' => [AiUsageRecordStatus::NoUsage],
]);

it('round-trips the casts: encrypted credentials, enums, datetimes', function (): void {
    $account = AiProviderAccount::factory()->configured()->create([
        'config' => ['key' => 'secret-value', 'url' => 'https://chain.example/v1'],
        'total_token_limit' => 12_345,
        'unavailable_until' => now()->addHour(),
    ]);
    $account->refresh();

    expect($account->config)->toBe(['key' => 'secret-value', 'url' => 'https://chain.example/v1'])
        ->and($account->total_token_limit)->toBe(12_345)
        ->and($account->isCoolingDown())->toBeTrue()
        // The raw column must not carry the plaintext credential.
        ->and(DB::table('ai_provider_accounts')->where('id', $account->getKey())->value('config'))
        ->not->toContain('secret-value');

    $reservation = AiUsageReservation::factory()->create(['status' => AiReservationStatus::Started]);
    $reservation->refresh();

    expect($reservation->status)->toBe(AiReservationStatus::Started);
});

it('resolves relations in both directions', function (): void {
    $capability = AiCapability::factory()->active()->has(AiProviderAccount::factory()->configured(), 'accounts')->create();
    $account = $capability->accounts()->first();

    $record = AiUsageRecord::factory()->for($account, 'account')->create();

    expect($record->account->is($account))->toBeTrue()
        ->and($account->capability->is($capability))->toBeTrue()
        ->and($account->usageRecords->pluck('id'))->toContain($record->getKey());

    $reservation = AiUsageReservation::factory()->for($account, 'account')->create();
    $recordTwo = AiUsageRecord::factory()->for($account, 'account')->create();

    $reservation->update(['ai_usage_record_id' => $recordTwo->getKey()]);
    $reservation->refresh();

    expect($reservation->usageRecord->is($recordTwo))->toBeTrue();
});
