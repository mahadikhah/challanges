<?php

namespace Database\Factories;

use App\Enums\ReminderKind;
use App\Models\ChallengeParticipant;
use App\Models\ChallengePeriod;
use App\Models\ReminderDispatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReminderDispatch>
 */
class ReminderDispatchFactory extends Factory
{
    /**
     * Define the model's default state: a period-opened nudge, due now, unsent.
     *
     * Left to itself this builds a participant and a period belonging to two
     * *different* challenges. Use `on()` to tie both to the same timeline.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'challenge_participant_id' => ChallengeParticipant::factory(),
            'challenge_period_id' => ChallengePeriod::factory(),
            'kind' => ReminderKind::PeriodOpened,
            'scheduled_for' => now(),
            'sent_at' => null,
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

    public function ofKind(ReminderKind $kind): static
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => $kind,
        ]);
    }

    public function scheduledFor(string $scheduledFor): static
    {
        return $this->state(fn (array $attributes): array => [
            'scheduled_for' => $scheduledFor,
        ]);
    }

    /**
     * Not due yet — the dispatch sweep must leave it alone.
     */
    public function upcoming(): static
    {
        return $this->state(fn (array $attributes): array => [
            'scheduled_for' => now()->addHour(),
        ]);
    }

    public function sent(): static
    {
        return $this->state(fn (array $attributes): array => [
            'scheduled_for' => now()->subHour(),
            'sent_at' => now()->subHour(),
        ]);
    }

    /**
     * Claimed but never confirmed sent — a failed send.
     */
    public function stalled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'scheduled_for' => now()->subDay(),
            'sent_at' => null,
        ]);
    }
}
