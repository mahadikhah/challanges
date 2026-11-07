<?php

namespace App\Actions\Coins;

use App\Enums\CoinTransactionReason;
use App\Exceptions\InsufficientCoinsException;
use App\Models\CoinTransaction;
use App\Models\User;
use App\Services\CoinLedger;
use Illuminate\Support\Str;

/**
 * An admin moving coins by hand — the support-desk lever.
 *
 * The panel never writes to the ledger itself: this action picks the reason
 * (`AdminCredit` or `AdminDebit`) so a caller cannot reach for any other, and
 * hands the rest to `CoinLedger`, which takes the user's row lock and enforces
 * the idempotency key. The reason decides the direction, and a debit is allowed
 * to overdraw by design — a manual clawback that a balance could veto is a
 * clawback that cannot happen.
 *
 * The key is a fresh UUID per call. Telegram-driven flows replay the *same*
 * update, so their keys are derived from Telegram's own ids; an admin form
 * submission is a new human decision every time, so there is nothing external
 * to key on. The reference morph points at the admin who acted, which is the
 * whole audit trail the panel needs: the ledger row names its own author.
 */
class AdjustUserCoins
{
    public function __construct(private readonly CoinLedger $ledger) {}

    public function credit(User $admin, User $user, int $magnitude): CoinTransaction
    {
        return $this->adjust($admin, $user, $magnitude, CoinTransactionReason::AdminCredit);
    }

    /**
     * @throws InsufficientCoinsException when the balance will not cover it
     */
    public function debit(User $admin, User $user, int $magnitude): CoinTransaction
    {
        return $this->adjust($admin, $user, $magnitude, CoinTransactionReason::AdminDebit);
    }

    private function adjust(User $admin, User $user, int $magnitude, CoinTransactionReason $reason): CoinTransaction
    {
        return $this->ledger->record(
            $user,
            $magnitude,
            $reason,
            'admin_adjustment:'.Str::uuid()->toString(),
            $admin,
        );
    }
}
