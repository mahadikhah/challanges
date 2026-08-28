<?php

namespace Database\Factories;

use App\Enums\AiUsageOutcome;
use App\Enums\AiUsageQuality;
use App\Enums\AiUsageRecordStatus;
use App\Models\AiUsageRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiUsageRecord>
 */
class AiUsageRecordFactory extends Factory
{
    /**
     * Defaults describe a small, cleanly-reported success — the shape most
     * budget tests start from.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $input = fake()->numberBetween(100, 5_000);
        $output = fake()->numberBetween(50, 2_000);

        return [
            'operation' => 'test_operation',
            'outcome' => AiUsageOutcome::Completed,
            'idempotency_key' => 'ai:test:'.fake()->unique()->uuid(),
            'run_id' => fake()->uuid(),
            'driver' => 'openai_compatible',
            'model' => 'primary-model',
            'input_tokens' => $input,
            'output_tokens' => $output,
            'total_tokens' => $input + $output,
            'status' => AiUsageRecordStatus::Succeeded,
            'usage_quality' => AiUsageQuality::Reported,
            'duration_ms' => fake()->numberBetween(50, 5_000),
            'provider_called_at' => now(),
            'completed_at' => now(),
        ];
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'outcome' => AiUsageOutcome::ProviderFailed,
            'status' => AiUsageRecordStatus::Failed,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'total_tokens' => 0,
            'usage_quality' => null,
            'completed_at' => now(),
        ]);
    }

    public function noUsage(): static
    {
        return $this->state(fn (array $attributes): array => [
            'outcome' => AiUsageOutcome::MissingUsage,
            'status' => AiUsageRecordStatus::NoUsage,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'total_tokens' => 0,
            'usage_quality' => AiUsageQuality::Missing,
            'completed_at' => now(),
        ]);
    }

    /**
     * Snapshot prices onto the row, with a cost computed from them.
     */
    public function withCost(int $inputRate, int $outputRate, ?int $costMinor = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'input_token_rate_per_million' => $inputRate,
            'output_token_rate_per_million' => $outputRate,
            'estimated_cost_minor' => $costMinor ?? (int) ceil((
                ($attributes['input_tokens'] ?? 0) * $inputRate
                + ($attributes['output_tokens'] ?? 0) * $outputRate
            ) / 1_000_000),
        ]);
    }

    public function forOperation(string $operation): static
    {
        return $this->state(fn (array $attributes): array => [
            'operation' => $operation,
            'idempotency_key' => 'ai:'.$operation.':'.fake()->unique()->uuid(),
        ]);
    }
}
