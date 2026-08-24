<?php

namespace Database\Factories;

use App\Enums\StarPaymentStatus;
use App\Models\StarPayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StarPayment>
 */
class StarPaymentFactory extends Factory
{
    /**
     * Define the model's default state: an invoice created but not yet paid,
     * which is where every purchase starts and where most of them stay.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $stars = fake()->randomElement([25, 50, 100, 250]);

        return [
            'user_id' => User::factory()->telegram(),
            'telegram_payment_charge_id' => null,
            'invoice_payload' => 'coins:'.Str::lower(Str::random(16)),
            'stars_amount' => $stars,
            'coin_amount' => $stars,
            'status' => StarPaymentStatus::Pending,
            'payload' => null,
            'paid_at' => null,
            'refunded_at' => null,
        ];
    }

    /**
     * Price the purchase: how many Stars buy how many coins.
     */
    public function buying(int $coins, ?int $stars = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'coin_amount' => $coins,
            'stars_amount' => $stars ?? $coins,
        ]);
    }

    /**
     * Paid, carrying the charge id that crediting is keyed on.
     */
    public function paid(?string $chargeId = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'telegram_payment_charge_id' => $chargeId ?? 'charge_'.fake()->unique()->uuid(),
            'status' => StarPaymentStatus::Paid,
            'paid_at' => now(),
            'payload' => [
                'successful_payment' => [
                    'currency' => 'XTR',
                    'total_amount' => $attributes['stars_amount'] ?? 25,
                    'invoice_payload' => $attributes['invoice_payload'] ?? 'coins:test',
                ],
            ],
        ]);
    }

    /**
     * Paid, then reversed.
     */
    public function refunded(): static
    {
        return $this->paid()->state(fn (array $attributes): array => [
            'status' => StarPaymentStatus::Refunded,
            'refunded_at' => now(),
        ]);
    }

    /**
     * Declined at pre-checkout, or swept up after being abandoned.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => StarPaymentStatus::Failed,
        ]);
    }

    public function withPayload(string $invoicePayload): static
    {
        return $this->state(fn (array $attributes): array => [
            'invoice_payload' => $invoicePayload,
        ]);
    }
}
