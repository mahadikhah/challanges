<?php

namespace App\Services\Ai;

use App\Enums\AiUsageOutcome;
use App\Enums\AiUsageQuality;
use App\Enums\AiUsageRecordStatus;
use App\Models\AiProviderAccount;
use App\Models\AiUsageRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes the append-only ledger row for one attempt.
 *
 * Four rules: `firstOrCreate` on the idempotency key (the retry-safety
 * linchpin); never throw — accounting must not break the feature, but it must
 * be loud; status is derived from the outcome, not passed in; and metadata is
 * an ALLOWLIST, not a filter — only `provider`, `model` and `exception_class`
 * are copied, everything else is dropped before it can carry prompt text.
 */
class AiUsageRecorder
{
    /**
     * The stored metadata keys — an allowlist, not a filter.
     */
    private const METADATA_KEYS = ['driver', 'model', 'exception_class'];

    public function __construct(
        private readonly AiUsageAdapterRegistry $adapters,
        private readonly AiUsageNormalizer $normalizer,
        private readonly AiUsageCostCalculator $costCalculator,
    ) {}

    public function record(
        AiOperationIdentity $identity,
        int $attempt,
        AiUsageOutcome $outcome,
        mixed $responseOrUsage = null,
        ?AiProviderAccount $account = null,
        ?Model $subject = null,
        ?Throwable $exception = null,
    ): ?AiUsageRecord {
        $key = implode(':', [$identity->idempotencyKey(), 'attempt', $attempt]);

        try {
            $extracted = $this->adapters->extract($responseOrUsage);

            $driver = (string) ($account->driver ?? 'unknown');
            $model = $extracted['model'] ?? null;

            $normalized = $this->normalizer->normalize(
                usage: $extracted['usage'],
                driver: $driver,
                operation: $identity->operation,
                model: $model,
                rawMetadata: $extracted['metadata'],
                usageReported: $responseOrUsage !== null && $outcome !== AiUsageOutcome::ProviderFailed,
            );

            // A "completed" call that reported nothing is not a clean success.
            if ($normalized->quality === AiUsageQuality::Missing && $outcome === AiUsageOutcome::Completed) {
                $outcome = AiUsageOutcome::MissingUsage;
            }

            $cost = $this->costCalculator->calculate($normalized, $account);

            return DB::transaction(fn (): AiUsageRecord => AiUsageRecord::query()->firstOrCreate(
                ['idempotency_key' => $key],
                $this->attributes($identity, $attempt, $outcome, $normalized, $account, $subject, $cost, $exception),
            ));
        } catch (Throwable $accountingException) {
            Log::error('AI usage accounting failed', [
                'operation' => $identity->operation,
                'attempt' => $attempt,
                'outcome' => $outcome->value,
                'account_id' => $account?->getKey(),
                'reason' => $accountingException->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array{input_rate_per_million: int|null, output_rate_per_million: int|null, cost_minor: int|null}  $cost
     * @return array<string, mixed>
     */
    private function attributes(
        AiOperationIdentity $identity,
        int $attempt,
        AiUsageOutcome $outcome,
        AiUsage $usage,
        ?AiProviderAccount $account,
        ?Model $subject,
        array $cost,
        ?Throwable $exception,
    ): array {
        $status = match ($outcome) {
            AiUsageOutcome::Completed => AiUsageRecordStatus::Succeeded,
            AiUsageOutcome::MissingUsage => AiUsageRecordStatus::NoUsage,
            default => AiUsageRecordStatus::Failed,
        };

        return [
            'ai_capability_id' => $account?->ai_capability_id,
            'ai_provider_account_id' => $account?->getKey(),
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'operation' => $identity->operation,
            'outcome' => $outcome,
            'status' => $status,
            'job_identity' => $identity->jobClass,
            'run_id' => $identity->runId,
            'driver' => $usage->driver,
            'model' => $usage->model,
            'input_tokens' => $usage->inputTokens,
            'output_tokens' => $usage->outputTokens,
            'total_tokens' => $usage->totalTokens,
            'provider_reported_total_tokens' => $usage->providerReportedTotalTokens,
            'input_token_rate_per_million' => $cost['input_rate_per_million'],
            'output_token_rate_per_million' => $cost['output_rate_per_million'],
            'estimated_cost_minor' => $cost['cost_minor'],
            'usage_quality' => $usage->quality,
            'metadata' => $this->metadata($usage, $exception),
            'provider_called_at' => now(),
            'completed_at' => now(),
        ];
    }

    /**
     * An allowlist, not a filter: copy only the named keys, plus the
     * exception class. Everything else is dropped before it can carry prompt
     * text or credentials into the ledger.
     *
     * @return array<string, mixed>
     */
    private function metadata(AiUsage $usage, ?Throwable $exception): array
    {
        $metadata = [
            'driver' => $usage->driver,
            'model' => $usage->model,
        ];

        if ($exception !== null) {
            $metadata['exception_class'] = $exception::class;
        }

        // Only the allowlisted keys can ever reach the ledger.
        return array_intersect_key($metadata, array_flip(self::METADATA_KEYS));
    }
}
