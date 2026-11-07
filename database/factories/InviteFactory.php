<?php

namespace Database\Factories;

use App\Enums\InviteStatus;
use App\Models\Invite;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Invite>
 */
class InviteFactory extends Factory
{
    /**
     * Define the model's default state: a freshly minted, unclaimed code.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'inviter_id' => User::factory()->telegram(),
            'code' => Str::lower(Str::random(12)),
            'invited_user_id' => null,
            'credited_at' => null,
            'status' => InviteStatus::Pending,
        ];
    }

    public function withCode(string $code): static
    {
        return $this->state(fn (array $attributes): array => [
            'code' => $code,
        ]);
    }

    /**
     * Used by someone who already had an account: attributed, but unpaid.
     */
    public function claimedBy(User $user): static
    {
        return $this->state(fn (array $attributes): array => [
            'invited_user_id' => $user->id,
            'status' => InviteStatus::Claimed,
            'credited_at' => null,
        ]);
    }

    /**
     * Used by a brand-new user, and the inviter was paid.
     */
    public function creditedFor(User $user): static
    {
        return $this->state(fn (array $attributes): array => [
            'invited_user_id' => $user->id,
            'status' => InviteStatus::Credited,
            'credited_at' => now(),
        ]);
    }
}
