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
 * What a board ranks is the challenge's own scoring design, not a choice made
 * here: a binary challenge is §2.6's streak-descending board exactly as it
 * always was, and a quantity challenge ranks by accumulated `total_score`,
 * with points and unit in the rows instead of a streak count. Nothing about
 * *why* somebody is where they are — no missed-period counts, no freeze
 * tallies — because a leaderboard is a celebration, not a sanction.
 */
class ComposeLeaderboard
{
    public function __construct(private readonly ChatBroadcaster $broadcaster) {}

    /**
     * The board as message lines, or null when there is nothing to show.
     *
     * Null rather than an empty string when nobody has a streak — or, on a
     * quantity challenge, a score — to post: an all-zero board pushed into a
     * chat daily is noise, and a chat asking for one on demand deserves a
     * "nobody is on the board yet" line instead of a wall of zeros.
     *
     * @return list<string|null>|null
     */
    public function handle(Challenge $challenge, int $topSize): ?array
    {
        $quantity = $challenge->scoring_type->isQuantity();
        $column = $quantity ? 'total_score' : 'current_streak';

        $leaders = ChallengeParticipant::query()
            ->where('challenge_id', $challenge->getKey())
            ->where('status', ParticipantStatus::Active)
            ->where($column, '>', 0)
            ->orderByDesc($column)
            ->orderBy('joined_at')
            ->with('user')
            ->limit(max(1, $topSize))
            ->get();

        if ($leaders->isEmpty()) {
            return null;
        }

        return [
            $this->broadcaster->line($quantity
                ? 'bot.chatpost.leaderboard.headline_scored'
                : 'bot.chatpost.leaderboard.headline', [
                    'title' => $challenge->title,
                ]),
            $this->rows($leaders, $challenge),
        ];
    }

    /**
     * @param  Collection<int, ChallengeParticipant>  $leaders
     */
    private function rows(Collection $leaders, Challenge $challenge): string
    {
        $quantity = $challenge->scoring_type->isQuantity();

        return $leaders
            ->values()
            ->map(fn (ChallengeParticipant $participant, int $rank): string => $this->broadcaster->line(
                $quantity ? 'bot.chatpost.leaderboard.row_scored' : 'bot.chatpost.leaderboard.row',
                $quantity ? [
                    'rank' => $rank + 1,
                    'name' => $participant->user->first_name ?? $participant->user->name,
                    'score' => $this->plainScore($participant->total_score),
                    'unit' => (string) $challenge->unit_label,
                ] : [
                    'rank' => $rank + 1,
                    'name' => $participant->user->first_name ?? $participant->user->name,
                    'streak' => $participant->current_streak,
                ],
            ))
            ->implode("\n");
    }

    /**
     * A stored two-decimal score shown without its trailing zeros — the total
     * is a sum of whole points, and "1200.00 pts" would only obscure that.
     */
    private function plainScore(?string $stored): string
    {
        if ($stored === null) {
            return '0';
        }

        $trimmed = rtrim(rtrim($stored, '0'), '.');

        return $trimmed === '' ? '0' : $trimmed;
    }
}
