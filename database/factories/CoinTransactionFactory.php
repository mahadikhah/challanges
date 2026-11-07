<?php

namespace Database\Factories;

use App\Enums\CoinTransactionReason;
use App\Models\CoinTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<CoinTransaction>
 */
class CoinTransactionFactory extends Factory
{
    /**
     * Define the model's default state: a small admin credit.
     *
     * Real entries are written by `CoinLedger`, which is the only thing that can
     * keep `balance_after` honest. This factory exists to *read* against — for
     * anything that asserts on a running balance, build the entries through the
     * ledger instead of here.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $amount = fake()->numberBetween(1, 50);

        return [
            'user_id' => User::factory()->telegram(),
            'amount' => $amount,
            'reason' => CoinTransactionReason::AdminCredit,
            'balance_after' => $amount,
            'reference_type' => null,
            'reference_id' => null,
            'idempotency_key' => 'test:'.fake()->unique()->uuid(),
        ];
    }

    /**
     * Set the reason, taking the sign from it so the row stays consistent.
     */
    public function because(CoinTransactionReason $reason, ?int $magnitude = null): static
    {
        return $this->state(function (array $attributes) use ($reason, $magnitude): array {
            $size = $magnitude ?? abs((int) ($attributes['amount'] ?? 10));

            return [
                'reason' => $reason,
                'amount' => $size * $reason->sign(),
            ];
        });
    }

    /**
     * Pin the running balance this entry left behind.
     */
    public function leavingBalance(int $balanceAfter): static
    {
        return $this->state(fn (array $attributes): array => [
            'balance_after' => $balanceAfter,
        ]);
    }

    /**
     * Point the entry at whatever caused it.
     */
    public function about(Model $reference): static
    {
        return $this->state(fn (array $attributes): array => [
            'reference_type' => $reference->getMorphClass(),
            'reference_id' => $reference->getKey(),
        ]);
    }

    /**
     * Force a specific idempotency key, to test that a replay collides.
     */
    public function keyedBy(string $idempotencyKey): static
    {
        return $this->state(fn (array $attributes): array => [
            'idempotency_key' => $idempotencyKey,
        ]);
    }
}
