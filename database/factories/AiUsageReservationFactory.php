<?php

namespace Database\Factories;

use App\Enums\AiReservationStatus;
use App\Models\AiUsageReservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiUsageReservation>
 */
class AiUsageReservationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $total = fake()->numberBetween(100, 4_000);

        return [
            'operation' => 'test_operation',
            'idempotency_key' => 'ai:test:'.fake()->unique()->uuid().':attempt:1',
            'run_id' => fake()->uuid(),
            'reserved_input_tokens' => (int) ($total * 0.75),
            'reserved_output_tokens' => $total - (int) ($total * 0.75),
            'reserved_total_tokens' => $total,
            'status' => AiReservationStatus::Queued,
            'usage_known' => false,
        ];
    }

    public function started(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AiReservationStatus::Started,
            'started_at' => now(),
        ]);
    }

    public function reconciled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AiReservationStatus::Reconciled,
            'usage_known' => true,
            'started_at' => now()->subMinutes(5),
            'completed_at' => now()->subMinutes(4),
            'reconciled_at' => now(),
            'terminal_at' => now(),
            'consumed_input_tokens' => (int) (($attributes['reserved_input_tokens'] ?? 0) * 0.9),
            'consumed_output_tokens' => (int) (($attributes['reserved_output_tokens'] ?? 0) * 0.9),
            'consumed_total_tokens' => (int) (($attributes['reserved_total_tokens'] ?? 0) * 0.9),
        ]);
    }

    public function released(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AiReservationStatus::Released,
            'usage_known' => false,
            'failed_at' => now(),
            'released_at' => now(),
            'terminal_at' => now(),
            'consumed_input_tokens' => 0,
            'consumed_output_tokens' => 0,
            'consumed_total_tokens' => 0,
            'released_input_tokens' => $attributes['reserved_input_tokens'] ?? 0,
            'released_output_tokens' => $attributes['reserved_output_tokens'] ?? 0,
            'released_total_tokens' => $attributes['reserved_total_tokens'] ?? 0,
        ]);
    }
}
