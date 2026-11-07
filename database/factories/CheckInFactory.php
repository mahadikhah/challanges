<?php

namespace Database\Factories;

use App\Enums\CheckInStatus;
use App\Models\ChallengeParticipant;
use App\Models\ChallengePeriod;
use App\Models\CheckIn;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CheckIn>
 */
class CheckInFactory extends Factory
{
    /**
     * Define the model's default state: nothing submitted yet.
     *
     * Left to itself this builds a participant and a period belonging to two
     * *different* challenges, which is not a state the app can produce. Use
     * `on()` to tie both to the same timeline.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'challenge_participant_id' => ChallengeParticipant::factory(),
            'challenge_period_id' => ChallengePeriod::factory(),
            'status' => CheckInStatus::Pending,
            'expected_phrase' => null,
            'submitted_text' => null,
            'proof_path' => null,
            'submitted_at' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ];
    }

    /**
     * Attach both sides at once, so the row describes a coherent obligation.
     */
    public function on(ChallengeParticipant $participant, ChallengePeriod $period): static
    {
        return $this->state(fn (array $attributes): array => [
            'challenge_participant_id' => $participant->id,
            'challenge_period_id' => $period->id,
        ]);
    }

    /**
     * A photo waiting on the creator.
     */
    public function submitted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => CheckInStatus::Submitted,
            'proof_path' => 'proofs/'.fake()->uuid().'.jpg',
            'submitted_at' => now(),
        ]);
    }

    public function approved(?User $reviewer = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => CheckInStatus::Approved,
            'submitted_at' => $attributes['submitted_at'] ?? now(),
            'reviewed_by' => $reviewer?->id,
            'reviewed_at' => $reviewer === null ? null : now(),
        ]);
    }

    public function rejected(?User $reviewer = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => CheckInStatus::Rejected,
            'proof_path' => 'proofs/'.fake()->uuid().'.jpg',
            'submitted_at' => now(),
            'reviewed_by' => $reviewer?->id,
            'reviewed_at' => now(),
        ]);
    }

    public function missed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => CheckInStatus::Missed,
        ]);
    }

    public function frozen(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => CheckInStatus::Frozen,
        ]);
    }

    /**
     * Issue a phrase for this participant and period.
     */
    public function withPhrase(string $phrase): static
    {
        return $this->state(fn (array $attributes): array => [
            'expected_phrase' => $phrase,
        ]);
    }
}
