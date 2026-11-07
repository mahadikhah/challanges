<?php

namespace Database\Factories;

use App\Enums\EntitlementSource;
use App\Enums\EntitlementType;
use App\Models\Challenge;
use App\Models\Entitlement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Entitlement>
 */
class EntitlementFactory extends Factory
{
    /**
     * Define the model's default state: an unspent free join-slot.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->telegram(),
            'type' => EntitlementType::JoinSlot,
            'source' => EntitlementSource::FreeBaseline,
            'consumed_at' => null,
            'challenge_id' => null,
        ];
    }

    public function ofType(EntitlementType $type): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => $type,
        ]);
    }

    public function createSlot(): static
    {
        return $this->ofType(EntitlementType::CreateSlot);
    }

    public function joinSlot(): static
    {
        return $this->ofType(EntitlementType::JoinSlot);
    }

    public function from(EntitlementSource $source): static
    {
        return $this->state(fn (array $attributes): array => [
            'source' => $source,
        ]);
    }

    public function purchased(): static
    {
        return $this->from(EntitlementSource::CoinPurchase);
    }

    public function granted(): static
    {
        return $this->from(EntitlementSource::AdminGrant);
    }

    /**
     * Already spent, optionally naming the challenge it went on.
     */
    public function consumed(?Challenge $challenge = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'consumed_at' => now(),
            'challenge_id' => $challenge?->id,
        ]);
    }
}
