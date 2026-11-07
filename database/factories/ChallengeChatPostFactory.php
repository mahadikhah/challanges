<?php

namespace Database\Factories;

use App\Enums\ChatPostKind;
use App\Models\ChallengeChat;
use App\Models\ChallengeChatPost;
use App\Models\ChallengeParticipant;
use App\Models\ChallengePeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChallengeChatPost>
 */
class ChallengeChatPostFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'challenge_chat_id' => ChallengeChat::factory(),
            'post_kind' => ChatPostKind::CheckInAnnouncement,
        ];
    }

    /**
     * A check-in announcement — the (chat, period, participant) idempotency key.
     */
    public function checkIn(): static
    {
        return $this->state(fn (): array => [
            'post_kind' => ChatPostKind::CheckInAnnouncement,
            'challenge_period_id' => ChallengePeriod::factory(),
            'challenge_participant_id' => ChallengeParticipant::factory(),
        ]);
    }

    /**
     * A daily leaderboard — the (chat, date) idempotency key.
     */
    public function leaderboard(): static
    {
        return $this->state(fn (): array => [
            'post_kind' => ChatPostKind::DailyLeaderboard,
            'post_date' => now()->toDateString(),
        ]);
    }
}
