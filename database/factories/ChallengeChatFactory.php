<?php

namespace Database\Factories;

use App\Enums\TelegramChatType;
use App\Models\Challenge;
use App\Models\ChallengeChat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChallengeChat>
 */
class ChallengeChatFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The unverified state by default: a discovered chat that neither admin
     * check has passed yet, because the checks are `VerifyChallengeChat`'s to
     * make, not the factory's to assume.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'challenge_id' => Challenge::factory(),
            'telegram_chat_id' => $this->faker->randomNumber(9),
            'chat_type' => $this->faker->randomElement(TelegramChatType::cases()),
            'title' => $this->faker->words(3, true),
            'is_active' => false,
            'share_proof_media' => false,
            'post_checkin_announcements' => false,
            'post_daily_leaderboard' => false,
        ];
    }

    /**
     * A chat both admin checks have passed — the only state posts are sent to.
     */
    public function verified(): static
    {
        return $this->state(fn (): array => [
            'bot_admin_verified_at' => now(),
            'creator_admin_verified_at' => now(),
            'is_active' => true,
        ]);
    }

    /**
     * A channel rather than a group — the one branch the posting rules take.
     */
    public function channel(): static
    {
        return $this->state(fn (): array => [
            'chat_type' => TelegramChatType::Channel,
        ]);
    }
}
