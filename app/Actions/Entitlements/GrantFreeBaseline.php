<?php

namespace App\Actions\Entitlements;

use App\Enums\EntitlementSource;
use App\Enums\EntitlementType;
use App\Models\Entitlement;
use App\Models\User;
use App\Services\CoinLedger;
use App\Services\Settings;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Give a user the free slots the platform promises them.
 *
 * The baseline is "one challenge created and one joined, free" — but the actual
 * numbers come from `Setting`, because every rate in this economy is
 * admin-configurable. Nothing here hardcodes 1.
 *
 * **This tops up rather than grants.** It counts the `FreeBaseline` rows the user
 * already holds and only inserts the difference, which makes it safe to call on
 * every `/start` and means an admin raising `FreeCreateSlots` from 1 to 2 lifts
 * existing users on their next call rather than only new sign-ups.
 *
 * **It never takes a slot back.** Lowering the allowance stops future top-ups; it
 * does not revoke what a user is holding, because that slot may already be spent
 * on a live challenge, and un-spending it would mean removing them from it.
 *
 * The count-then-insert is a read-then-write with no unique index behind it, so
 * two `/start` updates arriving together would otherwise both see zero and both
 * insert. It runs under the same per-user mutex as every coin movement.
 */
class GrantFreeBaseline
{
    public function __construct(
        private readonly Settings $settings,
        private readonly CoinLedger $ledger,
    ) {}

    /**
     * Top the user up to their free allowance, and return only what was added.
     *
     * An empty collection means they already had everything they are owed, which
     * is the normal outcome for every call after the first.
     *
     * @return Collection<int, Entitlement>
     */
    public function handle(User $user, ?EntitlementType $type = null): Collection
    {
        $types = $type !== null ? [$type] : EntitlementType::cases();

        return DB::transaction(function () use ($user, $types): Collection {
            $this->ledger->lockUser($user);

            /** @var Collection<int, Entitlement> $granted */
            $granted = new Collection;

            foreach ($types as $slot) {
                $granted = $granted->merge($this->topUp($user, $slot));
            }

            return $granted;
        });
    }

    /**
     * How many free slots of a type the user is still owed.
     *
     * Negative would mean they hold more than the allowance — an admin having
     * lowered it — so this floors at zero rather than implying a clawback.
     */
    public function owed(User $user, EntitlementType $type): int
    {
        $allowance = $this->settings->integer($type->freeAllowanceSetting());

        $held = $user->entitlements()
            ->where('type', $type)
            ->where('source', EntitlementSource::FreeBaseline)
            ->count();

        return max(0, $allowance - $held);
    }

    /**
     * Insert the missing free slots of one type.
     *
     * Consumed rows count towards the allowance. A user who has spent their free
     * challenge has still *had* it, so this must not quietly hand them a new one
     * every time they run `/start`.
     *
     * @return list<Entitlement>
     */
    private function topUp(User $user, EntitlementType $type): array
    {
        // Counted once, before any insert: the loop is adding the very rows
        // `owed()` counts, so re-asking inside the condition would be reading its
        // own writes.
        $missing = $this->owed($user, $type);
        $granted = [];

        for ($i = 0; $i < $missing; $i++) {
            $granted[] = $user->entitlements()->create([
                'type' => $type,
                'source' => EntitlementSource::FreeBaseline,
            ]);
        }

        return $granted;
    }
}
