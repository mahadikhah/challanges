<?php

namespace Database\Factories;

use App\Models\Challenge;
use App\Models\ChallengePeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChallengePeriod>
 */
class ChallengePeriodFactory extends Factory
{
    /**
     * Define the model's default state: period 0, the day that is open now.
     *
     * `(challenge_id, index)` is unique, so building several periods for one
     * challenge means varying the index — use `atIndex()`, or a `sequence()`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'challenge_id' => Challenge::factory()->active(),
            ...$this->window(0),
        ];
    }

    /**
     * Move this period to a given position on a daily timeline.
     *
     * Deliberately does not touch `challenge_id`, so it composes with `for()`.
     */
    public function atIndex(int $index): static
    {
        return $this->state(fn (array $attributes): array => $this->window($index));
    }

    /**
     * A period that has already elapsed and is waiting to be settled.
     */
    public function ended(): static
    {
        return $this->state(fn (array $attributes): array => [
            'starts_at' => now()->subDays(2)->startOfDay(),
            'ends_at' => now()->subDay()->startOfDay(),
            'rolled_over_at' => null,
        ]);
    }

    /**
     * Already settled. Rollover skips these.
     */
    public function rolledOver(): static
    {
        return $this->state(fn (array $attributes): array => [
            'starts_at' => now()->subDays(2)->startOfDay(),
            'ends_at' => now()->subDay()->startOfDay(),
            'rolled_over_at' => now()->subDay(),
        ]);
    }

    /**
     * A half-open daily window `$index` days into the timeline.
     *
     * @return array<string, mixed>
     */
    private function window(int $index): array
    {
        $timelineStart = now()->toImmutable()->startOfDay();

        return [
            'index' => $index,
            'starts_at' => $timelineStart->addDays($index),
            'ends_at' => $timelineStart->addDays($index + 1),
            'rolled_over_at' => null,
        ];
    }
}
