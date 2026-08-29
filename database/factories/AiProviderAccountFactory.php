<?php

namespace Database\Factories;

use App\Enums\AiLimitPeriod;
use App\Models\AiCapability;
use App\Models\AiProviderAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiProviderAccount>
 */
class AiProviderAccountFactory extends Factory
{
    /**
     * Defaults describe a completely empty account: no driver, nothing filled
     * in. The states below build the shapes the tests actually need.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ai_capability_id' => AiCapability::factory(),
            'name' => fake()->company().' '.fake()->randomElement(['primary', 'standby', 'backup']),
            'driver' => null,
            'model' => null,
            'is_active' => true,
            'sort_order' => 0,
            'config' => null,
            'limit_period' => AiLimitPeriod::Monthly,
            'limit_timezone' => 'UTC',
        ];
    }

    /**
     * A valid driver with every required credential filled — callable.
     */
    public function configured(): static
    {
        return $this->state(fn (array $attributes): array => [
            'driver' => 'openai_compatible',
            'model' => fake()->randomElement(['primary-model', 'standby-model', 'cheap-model']),
            'config' => [
                'key' => 'test-key-'.fake()->randomNumber(6),
                'url' => 'https://'.fake()->slug(2).'.example/v1',
            ],
        ]);
    }

    /**
     * Driver chosen, a required credential missing — never enters the chain.
     */
    public function unconfigured(): static
    {
        return $this->state(fn (array $attributes): array => [
            'driver' => 'openai_compatible',
            'model' => 'some-model',
            // url is the one required credential for this driver — a missing
            // API key is legitimate (auth-free endpoints), a missing URL is not.
            'config' => ['key' => 'test-key-'.fake()->randomNumber(6)],
        ]);
    }

    /**
     * Serving out a cooldown, with a reason for the operator.
     */
    public function coolingDown(): static
    {
        return $this->state(fn (array $attributes): array => [
            'unavailable_until' => now()->addMinutes(30),
            'last_failure_reason' => 'RateLimitedException',
            'last_failed_at' => now(),
        ]);
    }

    /**
     * Convenience for tests placing the account at a concrete host.
     */
    public function atUrl(string $url, ?string $key = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'driver' => 'openai_compatible',
            'model' => $attributes['model'] ?? 'primary-model',
            'config' => ['key' => $key ?? 'test-key', 'url' => $url],
        ]);
    }
}
