<?php

namespace App\Actions\Entitlements;

use App\Enums\EntitlementSource;
use App\Enums\EntitlementType;
use App\Exceptions\NoEntitlementAvailableException;
use App\Models\Challenge;
use App\Models\Entitlement;
use App\Models\User;
use App\Services\CoinLedger;
use Illuminate\Support\Facades\DB;

/**
 * Spend one slot on a challenge.
 *
 * Every create and every join goes through here, so the "one free challenge
 * created, one joined" rule lives in exactly one place and the bot, the Mini App
 * and the admin panel cannot disagree about it.
 *
 * **Consumption is recorded against the challenge, not just timestamped.** That is
 * what makes it idempotent: a user who is already in a challenge has already spent
 * a slot on it, so a double-tapped join button finds that row and returns it
 * rather than eating a second slot. It also means the spend survives the challenge
 * being deleted — `challenge_id` nulls out, `consumed_at` does not, so the slot
 * stays spent. Deleting a challenge is not a refund route.
 *
 * **Free slots are spent before paid ones.** A `FreeBaseline` slot is never billed
 * and never refunded, so it has no residual value to preserve; a `CoinPurchase`
 * slot cost real money. Spending the free one first leaves the user holding the
 * one that is worth something.
 */
class ConsumeEntitlement
{
    public function __construct(private readonly CoinLedger $ledger) {}

    /**
     * Claim a slot for this challenge, or refuse because there is none.
     *
     * @throws NoEntitlementAvailableException when the user holds no unconsumed slot of this type
     */
    public function handle(User $user, EntitlementType $type, Challenge $challenge): Entitlement
    {
        return DB::transaction(function () use ($user, $type, $challenge): Entitlement {
            $this->ledger->lockUser($user);

            if (($already = $this->spentOn($user, $type, $challenge)) !== null) {
                return $already;
            }

            $slot = $this->nextToSpend($user, $type);

            if ($slot === null) {
                throw new NoEntitlementAvailableException($type);
            }

            $slot->update([
                'consumed_at' => now(),
                'challenge_id' => $challenge->getKey(),
            ]);

            return $slot;
        });
    }

    /**
     * How many unconsumed slots of a type the user is holding.
     *
     * Advisory, like a balance check: it answers "should I offer this button?".
     * The authoritative answer is whether `handle()` finds one inside the lock.
     */
    public function available(User $user, EntitlementType $type): int
    {
        return $user->entitlements()->available($type)->count();
    }

    /**
     * The slot already spent on this challenge, if there is one.
     *
     * Scoped by `challenge_id` and `type` together — a user who created a challenge
     * *and* joined it has spent two different slots on the same challenge, and
     * neither should satisfy a claim for the other.
     */
    private function spentOn(User $user, EntitlementType $type, Challenge $challenge): ?Entitlement
    {
        return $user->entitlements()
            ->where('type', $type)
            ->where('challenge_id', $challenge->getKey())
            ->whereNotNull('consumed_at')
            ->first();
    }

    /**
     * The slot to spend next: free baseline first, then the oldest paid one.
     */
    private function nextToSpend(User $user, EntitlementType $type): ?Entitlement
    {
        return $user->entitlements()
            ->available($type)
            ->orderByRaw('CASE WHEN source = ? THEN 0 ELSE 1 END', [EntitlementSource::FreeBaseline->value])
            ->orderBy('id')
            ->first();
    }
}
