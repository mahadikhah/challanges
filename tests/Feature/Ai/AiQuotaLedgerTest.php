<?php

use App\Enums\AiLimitPeriod;
use App\Enums\AiReservationStatus;
use App\Enums\AiUsageOutcome;
use App\Enums\AiUsageQuality;
use App\Enums\AiUsageRecordStatus;
use App\Exceptions\AiUsageLimitExceededException;
use App\Models\AiGlobalUsageLimit;
use App\Models\AiProviderAccount;
use App\Models\AiUsageRecord;
use App\Models\AiUsageReservation;
use App\Services\Ai\AiOperationIdentity;
use App\Services\Ai\AiQuotaService;
use App\Services\Ai\AiUsageCostCalculator;
use App\Services\Ai\AiUsageNormalizer;
use App\Services\Ai\AiUsageRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function quotaAccount(array $attributes = []): AiProviderAccount
{
    return AiProviderAccount::factory()->configured()->create($attributes);
}

function quotaIdentity(string $operation = 'text_operation'): AiOperationIdentity
{
    return AiOperationIdentity::create($operation);
}

it('clamps the estimate total to at least one token so a zero estimate cannot pass every budget', function (): void {
    config(['ai_usage.estimates.zero_operation' => [
        'input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0,
    ]]);

    $estimate = app(AiQuotaService::class)->estimate('zero_operation');

    expect($estimate)->toBe(['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 1]);
});

it('reserves idempotently: the same identity, attempt, and account hand back the same lease', function (): void {
    $account = quotaAccount();
    $identity = quotaIdentity();

    $first = app(AiQuotaService::class)->reserve($identity, 0, $account);
    $second = app(AiQuotaService::class)->reserve($identity, 0, $account);

    expect($second->getKey())->toBe($first->getKey())
        ->and(AiUsageReservation::query()->count())->toBe(1);
});

it('refuses to reserve when the account estimate would exceed the account total limit, and reports why', function (): void {
    $account = quotaAccount(['total_token_limit' => 100, 'limit_period' => AiLimitPeriod::Daily]);
    config(['ai_usage.estimates.greedy_operation' => [
        'input_tokens' => 60, 'output_tokens' => 60, 'total_tokens' => 120,
    ]]);

    expect(fn () => app(AiQuotaService::class)->reserve(quotaIdentity('greedy_operation'), 0, $account))
        ->toThrow(AiUsageLimitExceededException::class)
        ->and(AiUsageReservation::query()->count())->toBe(0);
});

it('refuses when a global window leaves no room even though the account does', function (): void {
    $account = quotaAccount(['total_token_limit' => 1000]);

    AiGlobalUsageLimit::query()->create([
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDay(),
        'total_token_limit' => 10,
        'is_active' => true,
    ]);

    config(['ai_usage.estimates.text_operation' => [
        'input_tokens' => 50, 'output_tokens' => 0, 'total_tokens' => 50,
    ]]);

    expect(fn () => app(AiQuotaService::class)->reserve(quotaIdentity(), 0, $account))
        ->toThrow(AiUsageLimitExceededException::class);
});

it('ignores inactive and expired global windows', function (): void {
    $account = quotaAccount();

    AiGlobalUsageLimit::query()->create([ // expired an hour ago
        'starts_at' => now()->subDays(2), 'ends_at' => now()->subHour(),
        'total_token_limit' => 1, 'is_active' => true,
    ]);
    AiGlobalUsageLimit::query()->create([ // switched off
        'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(),
        'total_token_limit' => 1, 'is_active' => false,
    ]);

    expect(fn () => app(AiQuotaService::class)->reserve(quotaIdentity(), 0, $account))
        ->not->toThrow(AiUsageLimitExceededException::class);
});

it('allows exactly one claimant: the second claim returns null, the first owns the lease', function (): void {
    $quota = app(AiQuotaService::class);
    $reservation = $quota->reserve(quotaIdentity(), 0, quotaAccount());

    $claimed = $quota->claim($reservation);
    $rejected = $quota->claim($reservation);

    expect($claimed)->not->toBeNull()
        ->and($claimed->status)->toBe(AiReservationStatus::Started)
        ->and($rejected)->toBeNull()
        ->and($reservation->refresh()->status)->toBe(AiReservationStatus::Started);
});

it('reconciles consumed tokens to the reserved estimate when the provider reported nothing', function (): void {
    $quota = app(AiQuotaService::class);
    $identity = quotaIdentity();
    $reservation = $quota->reserve($identity, 0, quotaAccount(), estimate: ['input_tokens' => 50, 'output_tokens' => 20, 'total_tokens' => 70]);

    // No usage record at all — the "missing usage" case.
    $reconciled = $quota->reconcile($reservation, null);

    expect($reconciled->status)->toBe(AiReservationStatus::Reconciled)
        ->and($reconciled->consumed_total_tokens)->toBe(70)
        ->and($reconciled->released_total_tokens)->toBe(0)
        ->and($reconciled->usage_known)->toBeFalse();
});

it('reconciles to the reported usage when the record carries one, and releases the difference', function (): void {
    $quota = app(AiQuotaService::class);
    $identity = quotaIdentity();
    $reservation = $quota->reserve($identity, 0, quotaAccount(), estimate: ['input_tokens' => 100, 'output_tokens' => 100, 'total_tokens' => 200]);

    $record = AiUsageRecord::factory()->for($reservation->account, 'account')->create([
        'input_tokens' => 80, 'output_tokens' => 40, 'total_tokens' => 120,
        'usage_quality' => AiUsageQuality::Reported,
    ]);

    $reconciled = $quota->reconcile($reservation, $record);

    expect($reconciled->consumed_total_tokens)->toBe(120)
        ->and($reconciled->released_total_tokens)->toBe(80)
        ->and($reconciled->usage_known)->toBeTrue()
        ->and($reconciled->ai_usage_record_id)->toBe($record->getKey());
});

it('never double-changes: a reconciled reservation replaces its linked record in the consumption sum', function (): void {
    $quota = app(AiQuotaService::class);
    $identity = quotaIdentity();
    $account = quotaAccount();

    $reservation = $quota->reserve($identity, 0, $account, estimate: ['input_tokens' => 300, 'output_tokens' => 0, 'total_tokens' => 300]);
    $record = AiUsageRecord::factory()->for($account, 'account')->create([
        'input_tokens' => 100, 'output_tokens' => 0, 'total_tokens' => 100,
        'usage_quality' => AiUsageQuality::Reported,
    ]);

    $quota->reconcile($reservation, $record);

    // 100 (consumed reservation), NOT 100 + 100 (record counted as well).
    expect($quota->consumedBetween(now()->subHour(), now()->addHour(), $account->getKey()))
        ->total_tokens->toBe(100);
});

it('counts in-flight reservations at their estimate so concurrent jobs cannot each see an empty budget', function (): void {
    $quota = app(AiQuotaService::class);
    $account = quotaAccount();
    $identity = quotaIdentity();

    $first = $quota->reserve($identity, 0, $account, estimate: ['input_tokens' => 30, 'output_tokens' => 10, 'total_tokens' => 40]);
    $quota->claim($first);
    $quota->reserve($identity, 1, $account, estimate: ['input_tokens' => 30, 'output_tokens' => 10, 'total_tokens' => 40]);

    expect($quota->consumedBetween(now()->subHour(), now()->addHour(), $account->getKey()))
        ->total_tokens->toBe(80);
});

it('counts a released reservation for nothing — the tokens went back', function (): void {
    $quota = app(AiQuotaService::class);
    $account = quotaAccount();
    $reservation = $quota->reserve(quotaIdentity(), 0, $account, estimate: ['input_tokens' => 500, 'output_tokens' => 0, 'total_tokens' => 500]);

    $quota->release($reservation);

    expect($quota->consumedBetween(now()->subHour(), now()->addHour(), $account->getKey()))
        ->total_tokens->toBe(0)
        ->and($reservation->refresh()->status)->toBe(AiReservationStatus::Released);
});

it('uses a half-open window: a row landing exactly on the end boundary belongs to the next period', function (): void {
    $account = quotaAccount();

    $row = AiUsageRecord::factory()->for($account, 'account')->create([
        'input_tokens' => 10, 'output_tokens' => 0, 'total_tokens' => 10,
        'created_at' => now()->startOfDay(),
    ]);

    $quota = app(AiQuotaService::class);

    // Window [midnight, midnight+1h): the row AT midnight counts...
    expect($quota->consumedBetween(
        now()->startOfDay()->toImmutable(),
        now()->startOfDay()->copy()->addHour()->toImmutable(),
        $account->getKey(),
    ))->total_tokens->toBe(10)
        // ...but a window ending exactly AT midnight does not include it.
        ->and($quota->consumedBetween(
            now()->startOfDay()->subHour()->toImmutable(),
            now()->startOfDay()->toImmutable(),
            $account->getKey(),
        ))->total_tokens->toBe(0);

    unset($row);
});

it('computes the account window in the account timezone, not the server one', function (): void {
    // A daily window in Tehran (UTC+3:30): the day boundary is 20:30 UTC the
    // previous day. A spend made "yesterday" server-time can already sit in
    // today's Tehran window, and vice versa.
    $account = quotaAccount(['total_token_limit' => 100, 'limit_period' => AiLimitPeriod::Daily, 'limit_timezone' => 'Asia/Tehran']);

    // Place the spend 30 minutes after the start of TODAY'S Tehran day —
    // inside the window only when the boundary is computed in Tehran time.
    $tehranDayStart = now()->setTimezone('Asia/Tehran')->startOfDay();

    AiUsageRecord::factory()->for($account, 'account')->create([
        'input_tokens' => 60, 'output_tokens' => 0, 'total_tokens' => 60,
        'created_at' => $tehranDayStart->copy()->addMinutes(30)->utc(),
    ]);

    config(['ai_usage.estimates.text_operation' => [
        'input_tokens' => 50, 'output_tokens' => 0, 'total_tokens' => 50,
    ]]);

    // 60 already spent against a 100 daily cap; a 50 estimate must not fit.
    expect(fn () => app(AiQuotaService::class)->reserve(quotaIdentity(), 0, $account))
        ->toThrow(AiUsageLimitExceededException::class);
});

/*
 * Recorder + normalizer + cost calculator — same ledger, so same file.
 */

it('unwraps every usage shape providers actually send', function (mixed $usage, int $input, int $output, AiUsageQuality $quality): void {
    $normalized = app(AiUsageNormalizer::class)->normalize($usage, 'openai_compatible', 'text_operation');

    expect($normalized->inputTokens)->toBe($input)
        ->and($normalized->outputTokens)->toBe($output)
        ->and($normalized->quality)->toBe($quality);
})->with([
    'openai names' => [['input_tokens' => 10, 'output_tokens' => 5], 10, 5, AiUsageQuality::Reported],
    'chat-completions names' => [['usage' => ['prompt_tokens' => 7, 'completion_tokens' => 3]], 7, 3, AiUsageQuality::Reported],
    'gemini usageMetadata' => [['usageMetadata' => ['promptTokenCount' => 9, 'candidatesTokenCount' => 1]], 9, 1, AiUsageQuality::Reported],
    'only a total' => [['total_tokens' => 12], 0, 0, AiUsageQuality::Estimated],
    'nothing usable' => [['irrelevant' => 'x'], 0, 0, AiUsageQuality::Missing],
    'null' => [null, 0, 0, AiUsageQuality::Missing],
]);

it('flags a contradictory provider total and keeps both numbers instead of picking a winner', function (): void {
    $normalized = app(AiUsageNormalizer::class)->normalize(
        ['input_tokens' => 100, 'output_tokens' => 50, 'total_tokens' => 999],
        'openai_compatible',
        'text_operation',
    );

    expect($normalized->quality)->toBe(AiUsageQuality::Contradictory)
        ->and($normalized->totalTokens)->toBe(150)
        ->and($normalized->providerReportedTotalTokens)->toBe(999);
});

it('rejects garbage and negative token counts rather than coercing them', function (mixed $usage): void {
    expect(fn () => app(AiUsageNormalizer::class)->normalize($usage, 'openai_compatible', 'text_operation'))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'boolean' => [['input_tokens' => true]],
    'prose' => [['input_tokens' => 'about ten']],
    'negative' => [['input_tokens' => -5]],
]);

it('records a retry under the same idempotency key without duplicating the ledger row', function (): void {
    $recorder = app(AiUsageRecorder::class);
    $identity = quotaIdentity();
    $account = quotaAccount();

    $usage = ['model' => 'primary-model', 'usage' => ['input_tokens' => 120, 'output_tokens' => 40, 'total_tokens' => 160]];

    $first = $recorder->record($identity, 0, AiUsageOutcome::Completed, $usage, $account);
    $second = $recorder->record($identity, 0, AiUsageOutcome::Completed, $usage, $account);

    expect($second->getKey())->toBe($first->getKey())
        ->and(AiUsageRecord::query()->count())->toBe(1)
        ->and($first->status)->toBe(AiUsageRecordStatus::Succeeded)
        ->and($first->usage_quality)->toBe(AiUsageQuality::Reported);
});

it('drops everything outside the metadata allowlist, so no prompt text or credential can reach the ledger', function (): void {
    $record = app(AiUsageRecorder::class)->record(
        quotaIdentity(),
        0,
        AiUsageOutcome::ProviderFailed,
        ['input_tokens' => 1, 'prompt' => 'system prompt with secrets'],
        quotaAccount(),
        exception: new RuntimeException('provider exploded'),
    );

    $metadata = (array) $record->metadata;

    expect(array_keys($metadata))->toBe(['driver', 'model', 'exception_class'])
        ->and($metadata)->not->toHaveKey('prompt')
        ->and($metadata['exception_class'])->toBe(RuntimeException::class);
});

it('marks a completed call that reported no usage as no_usage, not a clean success', function (): void {
    $record = app(AiUsageRecorder::class)->record(
        quotaIdentity(),
        0,
        AiUsageOutcome::Completed,
        null, // provider said nothing
        quotaAccount(),
    );

    expect($record->status)->toBe(AiUsageRecordStatus::NoUsage)
        ->and($record->usage_quality)->toBe(AiUsageQuality::Missing);
});

it('prices with integer math, round half up, and snapshots the rates onto the record', function (): void {
    $account = quotaAccount([
        'input_token_price_per_million' => 1_500, // $1.50 / M
        'output_token_price_per_million' => 3_000,
    ]);

    $usage = app(AiUsageNormalizer::class)->normalize(
        ['input_tokens' => 1_234_567, 'output_tokens' => 0],
        'openai_compatible',
        'text_operation',
    );

    $cost = app(AiUsageCostCalculator::class)->calculate($usage, $account);

    // 1_234_567 * 1500 / 1e6 = 1851.85 minor → 1852 (half up).
    expect($cost['cost_minor'])->toBe(1852)
        ->and($cost['input_rate_per_million'])->toBe(1_500)
        ->and($cost['output_rate_per_million'])->toBe(3_000);
});

it('leaves the cost null when the account has no price for a dimension actually used', function (): void {
    $account = quotaAccount(['input_token_price_per_million' => 1_500]); // no output rate

    $usage = app(AiUsageNormalizer::class)->normalize(
        ['input_tokens' => 100, 'output_tokens' => 200],
        'openai_compatible',
        'text_operation',
    );

    $cost = app(AiUsageCostCalculator::class)->calculate($usage, $account);

    expect($cost['cost_minor'])->toBeNull(); // unknown cost, not a false zero
});
