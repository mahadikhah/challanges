<?php

namespace App\Actions\Entitlements;

use App\Enums\EntitlementSource;
use App\Enums\EntitlementType;
use App\Exceptions\InsufficientCoinsException;
use App\Models\CoinTransaction;
use App\Models\Entitlement;
use App\Models\User;
use App\Services\CoinLedger;
use App\Services\Settings;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Buy one extra slot with coins.
 *
 * The price comes from `Setting` and is read at purchase time, so an admin
 * changing it takes effect on the next purchase without a deploy. Nothing here
 * hardcodes a number.
 *
 * **The slot and the payment are one transaction.** The `Entitlement` row is
 * inserted first so the ledger entry can point its `reference` morph at it, then
 * the debit runs; if the balance will not cover it, `CoinLedger` throws and the
 * whole thing rolls back, slot included. There is no window in which a user holds
 * a slot they did not pay for, or has paid for one that does not exist.
 *
 * **Why the lock is taken here and not left to `CoinLedger`.** A replayed purchase
 * is the dangerous case. `CoinLedger` recognises a repeated idempotency key and
 * returns the original entry without moving coins — correct for a bare debit, but
 * if the slot were created before the mutex was held, two concurrent replays would
 * both insert a slot and only one of them would be paid for, leaving a free slot
 * behind. Holding the per-user mutex across the replay check *and* the insert
 * closes that. Re-taking the same lock inside `debit()` is a no-op, so there is no
 * deadlock.
 */
class PurchaseEntitlement
{
    public function __construct(
        private readonly Settings $settings,
        private readonly CoinLedger $ledger,
    ) {}

    /**
     * Debit the price and hand back the slot it bought.
     *
     * `$idempotencyKey` identifies *this purchase attempt*, not the user's intent
     * in general: a bot callback should derive it from the update id, so a
     * double-tap replays and a genuine second purchase does not.
     *
     * @throws InvalidArgumentException when the configured price is not positive
     * @throws InsufficientCoinsException when the balance will not cover it
     * @throws LogicException when the key already paid for a different slot
     */
    public function handle(User $user, EntitlementType $type, string $idempotencyKey): Entitlement
    {
        $price = $this->priceOf($type);

        if ($price < 1) {
            // A free extra slot is not a purchase — it is a change to the free
            // allowance, which is `GrantFreeBaseline`'s job. Silently writing a
            // `CoinPurchase` row for zero coins would misreport it as paid for.
            throw new InvalidArgumentException(
                "{$type->value} is priced at {$price} coins; grant it as a baseline slot instead of selling it.",
            );
        }

        return DB::transaction(function () use ($user, $type, $price, $idempotencyKey): Entitlement {
            $this->ledger->lockUser($user);

            // The coins already moved under this key, so the slot the original
            // purchase created is the one to return — not a second one.
            if (($paid = $this->ledger->findByKey($idempotencyKey)) !== null) {
                return $this->slotPaidFor($paid, $user, $type);
            }

            $slot = $user->entitlements()->create([
                'type' => $type,
                'source' => EntitlementSource::CoinPurchase,
            ]);

            $this->ledger->debit($user, $price, $type->purchaseReason(), $idempotencyKey, $slot);

            return $slot;
        });
    }

    /**
     * The current coin price of one slot of this type.
     *
     * Public so a caller can quote the price in a confirmation prompt before
     * charging for it — the same read the purchase will make.
     */
    public function priceOf(EntitlementType $type): int
    {
        return $this->settings->integer($type->priceSetting());
    }

    /**
     * The slot a replayed payment already bought.
     *
     * A purchase key that paid for something else — a different slot type, another
     * user's slot, or a Stars top-up — is a caller bug in how the key was derived.
     * Handing back an unrelated entitlement would report success for a slot that
     * was never sold, so this refuses.
     *
     * @throws LogicException when the key did not pay for this user's slot of this type
     */
    private function slotPaidFor(CoinTransaction $payment, User $user, EntitlementType $type): Entitlement
    {
        $slot = $payment->reference;

        if (! $slot instanceof Entitlement || $slot->user_id !== $user->getKey() || $slot->type !== $type) {
            throw new LogicException(
                "Idempotency key {$payment->idempotency_key} already paid for something other than a {$type->value} for user {$user->getKey()}.",
            );
        }

        return $slot;
    }
}
