<?php

namespace Database\Factories;

use App\Models\SchedulerHeartbeat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SchedulerHeartbeat>
 */
class SchedulerHeartbeatFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => SchedulerHeartbeat::SCHEDULER_KEY,
            'last_ran_at' => now(),
        ];
    }

    /**
     * Stamp the scheduler's heartbeat some time ago, for staleness tests.
     */
    public function ranMinutesAgo(int $minutes): static
    {
        return $this->state(fn (array $attributes): array => [
            'last_ran_at' => now()->subMinutes($minutes),
        ]);
    }
}
