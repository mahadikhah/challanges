<?php

namespace Database\Factories;

use App\Enums\ExternalCallOutcome;
use App\Enums\ExternalCallProvider;
use App\Models\ExternalCallStat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExternalCallStat>
 */
class ExternalCallStatFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => fake()->randomElement(ExternalCallProvider::cases()),
            'day' => today(),
            'outcome' => fake()->randomElement(ExternalCallOutcome::cases()),
            'count' => fake()->numberBetween(1, 100),
        ];
    }
}
