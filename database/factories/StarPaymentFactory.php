<?php

namespace Database\Factories;

use App\Enums\PaymentProvider;
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
     * Define the model's default state: a Telegram Stars invoice created but
     * not yet paid, which is where every purchase starts and where most of
     * them stay.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $stars = fake()->randomElement([25, 50, 100, 250]);

        return [
            'user_id' => User::factory()->telegram(),
            'provider' => PaymentProvider::TelegramStars,
            'telegram_payment_charge_id' => null,
            'invoice_payload' => 'coins:'.Str::lower(Str::random(16)),
            'stars_amount' => $stars,
            'rial_amount' => null,
            'coin_amount' => $stars,
            'status' => StarPaymentStatus::Pending,
            'payload' => null,
            'paid_at' => null,
            'refunded_at' => null,
        ];
    }

    /**
     * A Bale Pay purchase instead: Rial-priced, Stars column empty.
     *
     * The user stays whoever the caller set with `for()`; a stand-in Bale user
     * is drawn only when nobody was named.
     */
    public function bale(): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => $attributes['user_id'] ?? User::factory()->telegram()->bale(),
            'provider' => PaymentProvider::BalePay,
            'stars_amount' => null,
            'rial_amount' => $attributes['rial_amount'] ?? fake()->randomElement([100_000, 250_000, 500_000]),
        ]);
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
     * Price a Bale purchase: how many Rial buy how many coins.
     */
    public function buyingRial(int $coins, int $rial): static
    {
        return $this->state(fn (array $attributes): array => [
            'coin_amount' => $coins,
            'rial_amount' => $rial,
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
