<?php

namespace Database\Factories;

use App\Models\TelegramUpdate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelegramUpdate>
 */
class TelegramUpdateFactory extends Factory
{
    /**
     * Define the model's default state: an unprocessed `/start` message.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'update_id' => fake()->unique()->numberBetween(1_000_000, 9_999_999),
            'payload' => $this->messagePayload('/start'),
            'processed_at' => null,
        ];
    }

    /**
     * Pin the update id, to test that a replay collides.
     */
    public function withUpdateId(int $updateId): static
    {
        return $this->state(fn (array $attributes): array => [
            'update_id' => $updateId,
        ]);
    }

    public function processed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'processed_at' => now(),
        ]);
    }

    public function message(string $text, ?int $fromTelegramId = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'payload' => $this->messagePayload($text, $fromTelegramId),
        ]);
    }

    public function callbackQuery(string $data, ?int $fromTelegramId = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'payload' => [
                'callback_query' => [
                    'id' => (string) fake()->numberBetween(1000, 9999),
                    'from' => ['id' => $fromTelegramId ?? fake()->numberBetween(1, 999_999)],
                    'data' => $data,
                ],
            ],
        ]);
    }

    public function preCheckoutQuery(string $invoicePayload, int $stars = 25): static
    {
        return $this->state(fn (array $attributes): array => [
            'payload' => [
                'pre_checkout_query' => [
                    'id' => (string) fake()->numberBetween(1000, 9999),
                    'from' => ['id' => fake()->numberBetween(1, 999_999)],
                    'currency' => 'XTR',
                    'total_amount' => $stars,
                    'invoice_payload' => $invoicePayload,
                ],
            ],
        ]);
    }

    /**
     * An update shape we do not handle, for asserting that `kind()` says so
     * rather than guessing.
     */
    public function unhandled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'payload' => ['poll_answer' => ['poll_id' => '1']],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function messagePayload(string $text, ?int $fromTelegramId = null): array
    {
        $telegramId = $fromTelegramId ?? fake()->numberBetween(1, 999_999);

        return [
            'message' => [
                'message_id' => fake()->numberBetween(1, 9999),
                'from' => [
                    'id' => $telegramId,
                    'is_bot' => false,
                    'first_name' => fake()->firstName(),
                ],
                'chat' => ['id' => $telegramId, 'type' => 'private'],
                'date' => 1_760_000_000,
                'text' => $text,
            ],
        ];
    }
}
