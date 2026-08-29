<?php

namespace Database\Factories;

use App\Enums\AiCapabilityPurpose;
use App\Models\AiCapability;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiCapability>
 */
class AiCapabilityFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => 'test_capability_'.fake()->unique()->randomNumber(6),
            'label' => fake()->sentence(3),
            'purpose' => AiCapabilityPurpose::Text,
            'is_active' => false, // freshly created capabilities call nothing
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes): array => ['is_active' => true]);
    }

    public function purpose(AiCapabilityPurpose $purpose): static
    {
        return $this->state(fn (array $attributes): array => ['purpose' => $purpose]);
    }
}
