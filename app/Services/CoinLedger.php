<?php

namespace App\Services;

use App\Enums\CoinTransactionReason;
use App\Exceptions\InsufficientCoinsException;
use App\Models\CoinTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;

/**
 * The one way to move coins.
 *
 * Coins are real money — users buy them with Telegram Stars — so this class is
 * deliberately the only writer of `coin_transactions`, and it is paranoid in
 * three specific ways.
 *
 * **The row lock.** Every write opens a transaction and takes `lockForUpdate()`
 * on the `users` row *before* reading the balance. The balance lives in
 * `coin_transactions`, not on the user, so the user row is being used purely as
 * a mutex: as long as every writer takes it first, the read-then-write is
 * serialised per user and two simultaneous debits cannot both see the same
 * pre-spend balance. Without it, a user who taps "buy freeze" twice could spend
 * the same coins twice — the classic lost update.
 *
 * **The idempotency key.** Required, never optional. Telegram retries any
 * webhook it does not get a 2xx for, so "credit these coins" arrives more than
 * once as a matter of routine. A replay finds the original entry inside the lock
 * and returns it unchanged; the unique index is the backstop if it somehow gets
 * past that.
 *
 * **The sign comes from the reason.** Callers pass a magnitude and a
 * `CoinTransactionReason`; the direction is derived. A caller cannot pass `-50`
 * to a credit or `+50` to a debit, because a caller never passes a sign at all.
 *
 * `balance_after` is written on every row, so reading a balance is one indexed
 * lookup rather than a growing `SUM`. It is a cache, not the truth: `sum()`
 * recomputes from the entries and `drift()` compares the two, which is how a
 * corrupt row gets found rather than trusted.
 */
class CoinLedger
{
    /**
     * A user's current balance, from the newest entry's running total.
     *
     * Covered by the `(user_id, id)` index, so this stays cheap as the ledger
     * grows. No entries means no money has ever moved, which is a balance of 0.
     */
    public function balanceFor(User|int $user): int
    {
        $balance = CoinTransaction::query()
            ->where('user_id', $this->idOf($user))
            ->orderByDesc('id')
            ->value('balance_after');

        return (int) ($balance ?? 0);
    }

    /**
     * The balance recomputed from every entry.
     *
     * The authoritative figure, and the slow one. Use `balanceFor()` for
     * decisions and this for checking that `balanceFor()` is still telling the
     * truth.
     */
    public function sum(User|int $user): int
    {
        return (int) CoinTransaction::query()
            ->where('user_id', $this->idOf($user))
            ->sum('amount');
    }

    /**
     * How far the cached running total has drifted from the entries themselves.
     *
     * Zero on a healthy ledger. Anything else means a row was written without
     * going through this class, and the number says by how much.
     */
    public function drift(User|int $user): int
    {
        return $this->sum($user) - $this->balanceFor($user);
    }

    /**
     * Whether a user could currently afford to spend `$amount`.
     *
     * Advisory only — it answers "should I offer this button?", not "may this
     * spend proceed?". Between this call and the spend, the balance can move, so
     * the authoritative check is the one `record()` makes inside the lock.
     */
    public function canAfford(User|int $user, int $amount): bool
    {
        return $this->balanceFor($user) >= $amount;
    }

    /**
     * The entry a given idempotency key already wrote, if any.
     */
    public function findByKey(string $idempotencyKey): ?CoinTransaction
    {
        return CoinTransaction::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    /**
     * Add coins.
     *
     * @throws LogicException when the reason is not a credit
     */
    public function credit(
        User $user,
        int $magnitude,
        CoinTransactionReason $reason,
        string $idempotencyKey,
        ?Model $reference = null,
    ): CoinTransaction {
        if (! $reason->isCredit()) {
            throw new LogicException("Reason {$reason->value} is a debit; use debit() for it.");
        }

        return $this->record($user, $magnitude, $reason, $idempotencyKey, $reference);
    }

    /**
     * Take coins away.
     *
     * @throws LogicException when the reason is not a debit
     * @throws InsufficientCoinsException when the balance will not cover it
     */
    public function debit(
        User $user,
        int $magnitude,
        CoinTransactionReason $reason,
        string $idempotencyKey,
        ?Model $reference = null,
    ): CoinTransaction {
        if (! $reason->isDebit()) {
            throw new LogicException("Reason {$reason->value} is a credit; use credit() for it.");
        }

        return $this->record($user, $magnitude, $reason, $idempotencyKey, $reference);
    }

    /**
     * Take the per-user economy mutex for the duration of the current
     * transaction.
     *
     * Selects the `users` row `FOR UPDATE` and discards it. The row is not read
     * for its data — the balance does not live there — so it is being used purely
     * as a mutex, and every other writer for this user blocking here is the whole
     * point.
     *
     * Public because a coin debit is rarely the whole story. Buying a slot
     * creates an `Entitlement` *and* debits for it, and those two writes have to
     * be serialised together — otherwise two replays of the same purchase can
     * both create a slot while only one of them pays, leaving a free slot behind.
     * Holding this lock across both writes closes that window, and because it is
     * the same row `record()` locks, every per-user economy operation queues
     * behind one mutex.
     *
     * @throws LogicException when called outside a transaction, where the lock
     *                        would be released immediately and buy nothing
     */
    public function lockUser(User|int $user): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('lockUser() outside a transaction holds nothing; wrap the caller in one.');
        }

        User::query()->whereKey($this->idOf($user))->lockForUpdate()->first();
    }

    /**
     * Write one ledger entry, or return the one this key already wrote.
     *
     * `$magnitude` is always positive; the reason decides the direction. Prefer
     * `credit()`/`debit()`, which additionally assert that the reason matches the
     * direction the caller believed they were moving coins in.
     *
     * @throws InvalidArgumentException when the magnitude is not positive
     * @throws InsufficientCoinsException when a debit would overdraw
     */
    public function record(
        User $user,
        int $magnitude,
        CoinTransactionReason $reason,
        string $idempotencyKey,
        ?Model $reference = null,
    ): CoinTransaction {
        if ($magnitude <= 0) {
            // A zero-coin entry is a no-op that still claims an idempotency key,
            // and a negative one is a caller trying to choose the sign.
            throw new InvalidArgumentException("A ledger entry needs a positive magnitude, got {$magnitude}.");
        }

        return DB::transaction(function () use ($user, $magnitude, $reason, $idempotencyKey, $reference): CoinTransaction {
            $this->lockUser($user);

            // Inside the lock, so a replay racing the original waits for it and
            // then finds it here rather than writing a second entry.
            if (($replay = $this->findByKey($idempotencyKey)) !== null) {
                return $this->assertBelongsTo($replay, $user);
            }

            $amount = $magnitude * $reason->sign();
            $balanceBefore = $this->balanceFor($user);
            $balanceAfter = $balanceBefore + $amount;

            if ($reason->isDebit() && $balanceAfter < 0 && ! $reason->allowsOverdraft()) {
                throw new InsufficientCoinsException($balanceBefore, $magnitude);
            }

            return $this->write($user, $amount, $reason, $balanceAfter, $idempotencyKey, $reference);
        });
    }

    /**
     * Confirm a replayed key belongs to the user it is being replayed for.
     *
     * A key shared across two users is a caller bug — usually an idempotency key
     * built from something that is not actually unique per user. Handing back the
     * first user's entry would silently credit the wrong person and report
     * success, so this refuses instead.
     *
     * @throws LogicException when the key belongs to a different user
     */
    private function assertBelongsTo(CoinTransaction $entry, User $user): CoinTransaction
    {
        if ($entry->user_id !== $this->idOf($user)) {
            throw new LogicException(
                "Idempotency key {$entry->idempotency_key} already belongs to user {$entry->user_id}.",
            );
        }

        return $entry;
    }

    /**
     * Insert the entry, treating a unique-key collision as a replay.
     *
     * The lock should already have caught a replay. This is the backstop for the
     * case it cannot cover — the same key racing for two *different* users, which
     * takes two different row locks and so is not serialised by either.
     *
     * @throws UniqueConstraintViolationException when the key belongs to another user
     */
    private function write(
        User $user,
        int $amount,
        CoinTransactionReason $reason,
        int $balanceAfter,
        string $idempotencyKey,
        ?Model $reference,
    ): CoinTransaction {
        try {
            $entry = CoinTransaction::query()->create([
                'user_id' => $this->idOf($user),
                'amount' => $amount,
                'reason' => $reason,
                'balance_after' => $balanceAfter,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'idempotency_key' => $idempotencyKey,
            ]);
        } catch (UniqueConstraintViolationException $collision) {
            $existing = $this->findByKey($idempotencyKey);

            if ($existing === null || $existing->user_id !== $user->getKey()) {
                throw $collision;
            }

            return $existing;
        }

        // Every coin movement's line in the file log — this is real money's
        // paper trail, and unlike the ledger row it survives the database being
        // the thing that is wrong. Replays return above and never log, so the
        // line count reconciles against the ledger exactly.
        Log::info('A coin ledger entry was written.', [
            'user_id' => $entry->user_id,
            'amount' => $entry->amount,
            'reason' => $entry->reason->value,
            'balance_after' => $entry->balance_after,
            'idempotency_key' => $entry->idempotency_key,
            'reference_type' => $entry->reference_type,
            'reference_id' => $entry->reference_id,
        ]);

        return $entry;
    }

    private function idOf(User|int $user): int
    {
        return $user instanceof User ? (int) $user->getKey() : $user;
    }
}
