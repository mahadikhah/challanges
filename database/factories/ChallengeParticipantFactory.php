<?php

namespace Database\Factories;

use App\Enums\ParticipantStatus;
use App\Models\Challenge;
use App\Models\ChallengeParticipant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChallengeParticipant>
 */
class ChallengeParticipantFactory extends Factory
{
    /**
     * Define the model's default state: an active participant who joined at the
     * start with one freeze in hand and no history yet.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'challenge_id' => Challenge::factory()->active(),
            'user_id' => User::factory()->telegram(),
            'joined_at' => now(),
            'joined_period_index' => 0,
            'status' => ParticipantStatus::Active,
            'current_streak' => 0,
            'longest_streak' => 0,
            'freezes_total' => 1,
            'freezes_used' => 0,
            'streak_resets_count' => 0,
        ];
    }

    /**
     * A late joiner, whose obligations begin at `$periodIndex`.
     */
    public function joinedAtPeriod(int $periodIndex): static
    {
        return $this->state(fn (array $attributes): array => [
            'joined_period_index' => $periodIndex,
        ]);
    }

    /**
     * `longest_streak` defaults to the current one, since it can never be lower.
     */
    public function withStreak(int $current, ?int $longest = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'current_streak' => $current,
            'longest_streak' => max($current, $longest ?? $current),
        ]);
    }

    public function withFreezes(int $total, int $used = 0): static
    {
        return $this->state(fn (array $attributes): array => [
            'freezes_total' => $total,
            'freezes_used' => $used,
        ]);
    }

    /**
     * Every freeze already spent — the state in which a miss costs the streak.
     */
    public function withoutFreezes(): static
    {
        return $this->state(fn (array $attributes): array => [
            'freezes_total' => 0,
            'freezes_used' => 0,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ParticipantStatus::Completed,
        ]);
    }

    public function left(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ParticipantStatus::Left,
        ]);
    }

    public function removed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ParticipantStatus::Removed,
        ]);
    }
}
