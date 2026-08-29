<?php

namespace App\Actions\Challenges;

use App\Actions\Entitlements\ConsumeEntitlement;
use App\Enums\EntitlementType;
use App\Enums\ParticipantStatus;
use App\Exceptions\ChallengeNotJoinableException;
use App\Exceptions\NoEntitlementAvailableException;
use App\Models\Challenge;
use App\Models\ChallengeParticipant;
use App\Models\User;
use App\Services\CoinLedger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Put a user into a challenge, spending one of their join-slots.
 *
 * The one place a `challenge_participants` row is written, so the announcement
 * channel's join button, an invite-only deep link, the Mini App and the admin panel
 * cannot disagree about what joining costs or when it is allowed.
 *
 * **Joining twice is not an error.** A callback button can be tapped twice before
 * the first reply lands, and Telegram redelivers updates. So the second call hands
 * back the participation that already exists rather than throwing — and, critically,
 * without spending a second slot. `wasRecentlyCreated` on the returned model is how
 * a caller tells "welcome aboard" from "you are already in this one".
 *
 * **The user row is locked before the participant is read, not after.** The unique
 * index on `(challenge_id, user_id)` would stop the duplicate row on its own, but it
 * would not stop the duplicate *spend*: two concurrent joins could both find no
 * participant, both consume a slot, and one would then fail on the index having
 * already burned a slot the user does not get back. Taking `CoinLedger::lockUser()`
 * first serialises the whole read-decide-write on that user — the same lock
 * `ConsumeEntitlement` takes internally, so nothing is locked twice.
 *
 * That ordering is also why the existence check comes *before* the spend rather
 * than relying on `ConsumeEntitlement`'s own idempotency: that idempotency is keyed
 * on a slot already recorded against this challenge, and a participant added by an
 * admin or a seeder has no such record — a returning user would then pay for a
 * participation they already had.
 *
 * **Late joiners are not penalised for history.** `joined_period_index` is the
 * period that is open right now, and `ChallengeParticipant::owesPeriod()` reads it,
 * so nothing before their arrival is ever counted against them. They still share
 * the one fixed timeline; they simply start owing from where they came in.
 */
class JoinChallenge
{
    public function __construct(
        private readonly ConsumeEntitlement $entitlements,
        private readonly CoinLedger $ledger,
    ) {}

    /**
     * Join, or return the participation that already exists.
     *
     * The creator may join their own challenge, and pays a join-slot for it like
     * anybody else. Creating does not enrol you — a creator who wants to compete
     * says so, and one who is only organising is not made to look like a
     * participant who never checks in.
     *
     * @throws ChallengeNotJoinableException when the challenge is closed, out of
     *                                       periods, or they have already left it
     * @throws NoEntitlementAvailableException when the user holds no join-slot
     */
    public function handle(User $user, Challenge $challenge): ChallengeParticipant
    {
        if (! $challenge->status->acceptsJoins()) {
            throw ChallengeNotJoinableException::closed($challenge);
        }

        // Resolved before the transaction: it is a read, and it decides whether
        // there is anything to join at all.
        $joinedPeriodIndex = $this->openPeriodIndex($challenge);

        return DB::transaction(function () use ($user, $challenge, $joinedPeriodIndex): ChallengeParticipant {
            $this->ledger->lockUser($user);

            $existing = $challenge->participants()->where('user_id', $user->getKey())->first();

            if ($existing !== null) {
                if ($existing->status->isTerminal()) {
                    throw ChallengeNotJoinableException::participationEnded($existing);
                }

                // Already in. No slot spent, and `wasRecentlyCreated` stays false
                // so the caller can say so rather than welcoming them twice.
                return $existing;
            }

            $this->entitlements->handle($user, EntitlementType::JoinSlot, $challenge);

            return ChallengeParticipant::query()->create([
                'challenge_id' => $challenge->getKey(),
                'user_id' => $user->getKey(),
                'joined_at' => CarbonImmutable::now(),
                'joined_period_index' => $joinedPeriodIndex,
                'status' => ParticipantStatus::Active,

                // Snapshotted from the challenge rather than read through it on
                // every check-in: a creator raising the allowance later must not
                // silently un-freeze periods that were already missed, and a
                // participant who buys an extra freeze needs somewhere personal
                // to put it.
                'freezes_total' => $challenge->default_freezes,
            ]);
        });
    }

    /**
     * The period index a joiner starts owing from.
     *
     * Three cases, and the third is a refusal rather than a number: the timeline
     * is open now (join at the current period), it has not opened yet (join at the
     * top and owe everything), or it is spent. A challenge can sit in that last
     * state legitimately — `status` is moved to `completed` by scheduled rollover,
     * not at the instant the final period closes — and enrolling somebody into it
     * would create a participant who can never check in once.
     *
     * @throws ChallengeNotJoinableException
     */
    private function openPeriodIndex(Challenge $challenge): int
    {
        $now = CarbonImmutable::now();

        $current = $challenge->periods()->containing($now)->first();

        if ($current !== null) {
            return $current->index;
        }

        $first = $challenge->periods()->first();

        if ($first !== null && $now->lessThan($first->starts_at)) {
            return $first->index;
        }

        // Also covers a challenge with no materialised timeline at all, which
        // `CreateChallenge` makes impossible but a bad import would not.
        throw ChallengeNotJoinableException::timelineExhausted($challenge);
    }
}
