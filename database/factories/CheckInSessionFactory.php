<?php

namespace Database\Factories;

use App\Enums\CheckInSessionStatus;
use App\Models\ChallengeParticipant;
use App\Models\ChallengePeriod;
use App\Models\CheckInSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CheckInSession>
 */
class CheckInSessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * A freshly started session, waiting on its first step.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'challenge_participant_id' => ChallengeParticipant::factory(),
            'challenge_period_id' => ChallengePeriod::factory(),
            'status' => CheckInSessionStatus::InProgress,
            'started_at' => now(),
            'completed_at' => null,
            'current_step_order' => 1,
        ];
    }

    /**
     * A session that saw its period end unfinished.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => CheckInSessionStatus::Expired,
            'current_step_order' => null,
        ]);
    }

    /**
     * A session that ran its steps and fed a settlement.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => CheckInSessionStatus::Completed,
            'completed_at' => now(),
            'current_step_order' => null,
        ]);
    }

    /**
     * Waiting on a step other than the first.
     */
    public function onStep(int $order): static
    {
        return $this->state(fn (array $attributes): array => [
            'current_step_order' => $order,
        ]);
    }

    /**
     * When the run began — the anchor every wait is measured from.
     */
    public function startedAt(\DateTimeInterface $startedAt): static
    {
        return $this->state(fn (array $attributes): array => [
            'started_at' => $startedAt,
        ]);
    }
}
