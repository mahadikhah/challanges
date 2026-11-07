<?php

namespace Database\Factories;

use App\Enums\ConversationState;
use App\Models\BotConversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BotConversation>
 */
class BotConversationFactory extends Factory
{
    /**
     * Define the model's default state: the first step of the create-challenge
     * wizard, live for another hour.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->telegram(),
            'state' => ConversationState::AwaitingChallengeTitle,
            'payload' => null,
            'expires_at' => now()->addHour(),
        ];
    }

    /**
     * Sit at a given step, optionally with answers already gathered.
     *
     * @param  array<string, mixed>  $answers
     */
    public function at(ConversationState $state, array $answers = []): static
    {
        return $this->state(fn (array $attributes): array => [
            'state' => $state,
            'payload' => $answers === [] ? $attributes['payload'] ?? null : $answers,
        ]);
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    public function withAnswers(array $answers): static
    {
        return $this->state(fn (array $attributes): array => [
            'payload' => $answers,
        ]);
    }

    /**
     * Abandoned — the next message from this user must not be fed into it.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'expires_at' => now()->subMinute(),
        ]);
    }

    /**
     * A flow that should never time out.
     */
    public function everlasting(): static
    {
        return $this->state(fn (array $attributes): array => [
            'expires_at' => null,
        ]);
    }
}
