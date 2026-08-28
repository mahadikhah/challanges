<?php

namespace App\Actions\Challenges;

use App\Enums\ParticipantStatus;
use App\Models\Challenge;
use App\Models\ChallengeParticipant;
use App\Services\Telegram\ChatBroadcaster;
use Illuminate\Support\Collection;

/**
 * Compose a leaderboard message for a challenge's linked chat.
 *
 * One place cuts the board, so the daily scheduled post and the on-demand
 * `/leaderboard` command cannot disagree about what a leaderboard is — the
 * same property every other surface-sharing Action exists for.
 *
 * The shape is fixed by §2.6: streak-descending, top N (a `Setting`), names
 * and streak counts only. Nothing about *why* somebody's streak is what it is
 * — no missed-period counts, no freeze tallies — because a leaderboard is a
 * celebration, not a sanction.
 */
class ComposeLeaderboard
{
    public function __construct(private readonly ChatBroadcaster $broadcaster) {}

    /**
     * The board as message lines, or null when there is nothing to show.
     *
     * Null rather than an empty string when every streak is zero: posting an
     * all-zeros board into a chat daily is noise, and a chat asking for one
     * on demand deserves a "nobody is on the board yet" line instead of a
     * wall of zeros.
     *
     * @return list<string|null>|null
     */
    public function handle(Challenge $challenge, int $topSize): ?array
    {
        $leaders = ChallengeParticipant::query()
            ->where('challenge_id', $challenge->getKey())
            ->where('status', ParticipantStatus::Active)
            ->where('current_streak', '>', 0)
            ->orderByDesc('current_streak')
            ->orderBy('joined_at')
            ->with('user')
            ->limit(max(1, $topSize))
            ->get();

        if ($leaders->isEmpty()) {
            return null;
        }

        return [
            $this->broadcaster->line('bot.chatpost.leaderboard.headline', [
                'title' => $challenge->title,
            ]),
            $this->rows($leaders),
        ];
    }

    /**
     * @param  Collection<int, ChallengeParticipant>  $leaders
     */
    private function rows(Collection $leaders): string
    {
        return $leaders
            ->values()
            ->map(fn (ChallengeParticipant $participant, int $rank): string => $this->broadcaster->line(
                'bot.chatpost.leaderboard.row',
                [
                    'rank' => $rank + 1,
                    'name' => $participant->user->first_name ?? $participant->user->name,
                    'streak' => $participant->current_streak,
                ],
            ))
            ->implode("\n");
    }
}
