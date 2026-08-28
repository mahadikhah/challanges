<?php

namespace Database\Factories;

use App\Enums\ChallengeStatus;
use App\Enums\ChallengeVisibility;
use App\Enums\FlowType;
use App\Enums\PeriodType;
use App\Enums\ProofType;
use App\Models\Challenge;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Challenge>
 */
class ChallengeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * A scheduled, invite-only, one-tap daily challenge in UTC — the least
     * surprising thing to assert against. Period-math tests must set `timezone`
     * explicitly rather than lean on this default, or a UTC assumption will hide
     * inside them.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'creator_id' => User::factory()->telegram(),
            'title' => fake()->words(3, true),
            'description' => fake()->sentence(),

            // Unique per row rather than a fixed string: the column is unique, so a
            // constant would make the second challenge in any test fail on the index.
            'join_token' => fake()->unique()->regexify('[a-z2-9]{12}'),

            'period_type' => PeriodType::Daily,
            'custom_period_days' => null,
            'starts_at' => now()->addDay()->startOfDay(),
            'total_periods' => fake()->numberBetween(7, 30),
            'timezone' => 'UTC',
            'visibility' => ChallengeVisibility::InviteOnly,
            'proof_type' => ProofType::Button,
            'flow_type' => FlowType::Simple,
            'proof_is_public' => false,
            'default_freezes' => 1,
            'status' => ChallengeStatus::Scheduled,
            'announced_at' => null,
        ];
    }

    /**
     * Already running: the timeline opened yesterday.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ChallengeStatus::Active,
            'starts_at' => now()->subDay()->startOfDay(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ChallengeStatus::Completed,
            'starts_at' => now()->subMonth()->startOfDay(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ChallengeStatus::Cancelled,
        ]);
    }

    /**
     * Discoverable, and therefore due an announcement-channel post.
     *
     * Named `publiclyVisible` because `public` is a reserved word.
     */
    public function publiclyVisible(): static
    {
        return $this->state(fn (array $attributes): array => [
            'visibility' => ChallengeVisibility::Public,
        ]);
    }

    public function announced(): static
    {
        return $this->state(fn (array $attributes): array => [
            'visibility' => ChallengeVisibility::Public,
            'announced_at' => now(),
        ]);
    }

    /**
     * Set the proof mechanic, optionally publishing proofs to participants.
     */
    public function provenBy(ProofType $proofType, bool $public = false): static
    {
        return $this->state(fn (array $attributes): array => [
            'proof_type' => $proofType,
            'proof_is_public' => $public,
        ]);
    }

    /**
     * Set the cadence. `custom` needs a day count, so one is always written
     * rather than left null for the caller to discover.
     */
    public function every(PeriodType $periodType, ?int $customDays = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'period_type' => $periodType,
            'custom_period_days' => $periodType->requiresCustomDays() ? ($customDays ?? 3) : null,
        ]);
    }

    /**
     * Pin the timeline: when it opens, in whose timezone, and for how long.
     */
    public function timeline(string $startsAt, string $timezone, int $totalPeriods = 10): static
    {
        return $this->state(fn (array $attributes): array => [
            'starts_at' => $startsAt,
            'timezone' => $timezone,
            'total_periods' => $totalPeriods,
        ]);
    }

    public function withFreezes(int $freezes): static
    {
        return $this->state(fn (array $attributes): array => [
            'default_freezes' => $freezes,
        ]);
    }

    /**
     * Pin the join token, for a test that has to build the deep link by hand.
     */
    public function withJoinToken(string $token): static
    {
        return $this->state(fn (array $attributes): array => [
            'join_token' => $token,
        ]);
    }

    /**
     * Check-ins arrive as gated step sessions rather than one submission.
     */
    public function timedSession(): static
    {
        return $this->state(fn (array $attributes): array => [
            'flow_type' => FlowType::TimedSession,
        ]);
    }
}
