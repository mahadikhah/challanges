<?php

namespace App\Services;

use App\Enums\ParticipantStatus;
use App\Models\ChallengeParticipant;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * The four numbers the greeting shows about one person.
 *
 * In the root `app/Services/` namespace rather than `Services/Telegram/`
 * because none of this is a bot fact: they are platform facts, and a dashboard
 * elsewhere — the Mini App is the obvious next caller — wants the same figures
 * without reaching into the bot's namespace to get them.
 *
 * Everything here is reused, not recomputed. The coin figure is `CoinLedger`'s
 * (there is no balance column to read), and the challenge sets are relations
 * `User` already declares; a second query for any of it would be a second place
 * for the rule to drift.
 */
class UserStats
{
    /**
     * How stale the platform-wide figure is allowed to be, in seconds.
     *
     * Deliberately not a `SettingKey`. That table holds rates and prices an
     * admin tunes and can see the effect of; this is a cache lifetime, and a
     * case there would drag an admin-facing label into both locales to satisfy
     * `SettingsPanelTest` for a number nobody would ever want to change. Five
     * minutes is chosen against the cost of the query, not against taste — see
     * `activePeople()`.
     *
     * If this scan ever turns up in a slow-query log, the answer is a
     * `status`-leading index on `challenge_participants`, not a longer TTL.
     */
    private const int PLATFORM_CACHE_SECONDS = 300;

    private const string PLATFORM_CACHE_KEY = 'stats:active-people';

    public function __construct(private readonly CoinLedger $ledger) {}

    /**
     * Everything the greeting says about somebody.
     *
     * Never cached, any of it: a user who has just created a challenge has to
     * see that count move, and a dashboard reporting yesterday's numbers is one
     * nobody trusts. Three queries for a brand-new arrival, four once they are
     * in something, plus the platform figure below.
     *
     * @return array{joined: int, created: int, coins: int, people: int, platform: int}
     */
    public function for(User $user): array
    {
        $created = $this->challengeIds($user->createdChallenges()->pluck('id')->all());
        $joined = $this->challengeIds($user->participations()->active()->pluck('challenge_id')->all());

        return [
            'joined' => count($joined),
            'created' => count($created),
            'coins' => $this->ledger->balanceFor($user),
            'people' => $this->peopleIn([...$joined, ...$created]),
            'platform' => $this->activePeople(),
        ];
    }

    /**
     * How many people are active anywhere on the platform.
     *
     * The one expensive number in this class, and public for exactly that
     * reason: a caller can see at the call site that it is the one paying for a
     * scan. Both indexes on `challenge_participants` put `status` second —
     * `(user_id, status)` and `(challenge_id, status)` — so a bare `status`
     * filter has no leading column and reads the table. That runs on `/start`,
     * the busiest path in the product, which is why it is cached rather than
     * counted.
     */
    public function activePeople(): int
    {
        return (int) Cache::remember(
            self::PLATFORM_CACHE_KEY,
            self::PLATFORM_CACHE_SECONDS,
            static fn (): int => ChallengeParticipant::query()
                ->where('status', ParticipantStatus::Active)
                ->distinct()
                ->count('user_id'),
        );
    }

    /**
     * How many distinct people are active across these challenges.
     *
     * Skipped outright when the list is empty, which is the brand-new-user path
     * and the most common arrival at `/start` there is: `whereIn` over nothing
     * is a query that can only ever answer zero.
     *
     * @param  list<int>  $challengeIds
     */
    private function peopleIn(array $challengeIds): int
    {
        if ($challengeIds === []) {
            return 0;
        }

        return ChallengeParticipant::query()
            ->whereIn('challenge_id', $challengeIds)
            ->where('status', ParticipantStatus::Active)
            ->distinct()
            ->count('user_id');
    }

    /**
     * The ids as integers, deduplicated.
     *
     * A creator who joined their own challenge appears in both sets, and
     * counting them twice would overstate how many people the reader is
     * involved with.
     *
     * @param  array<int, mixed>  $ids
     * @return list<int>
     */
    private function challengeIds(array $ids): array
    {
        return array_values(array_unique(array_map(intval(...), $ids)));
    }
}
