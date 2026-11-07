<?php

use App\Enums\CoinTransactionReason;
use App\Exceptions\InsufficientCoinsException;
use App\Models\CoinTransaction;
use App\Models\StarPayment;
use App\Models\User;
use App\Services\CoinLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->ledger = app(CoinLedger::class);
    $this->user = User::factory()->telegram()->create();
});

describe('reading a balance', function () {
    it('reports zero for a user who has never moved a coin', function () {
        expect($this->ledger->balanceFor($this->user))->toBe(0)
            ->and($this->ledger->sum($this->user))->toBe(0);
    });

    it('reads the running total off the newest entry', function () {
        $this->ledger->credit($this->user, 100, CoinTransactionReason::StarsPurchase, 'k1');
        $this->ledger->debit($this->user, 25, CoinTransactionReason::JoinSlotPurchase, 'k2');

        expect($this->ledger->balanceFor($this->user))->toBe(75);
    });

    it('agrees with the sum of the entries', function () {
        $this->ledger->credit($this->user, 100, CoinTransactionReason::StarsPurchase, 'k1');
        $this->ledger->credit($this->user, 10, CoinTransactionReason::InviteCredit, 'k2');
        $this->ledger->debit($this->user, 50, CoinTransactionReason::CreateSlotPurchase, 'k3');

        expect($this->ledger->sum($this->user))->toBe(60)
            ->and($this->ledger->balanceFor($this->user))->toBe(60)
            ->and($this->ledger->drift($this->user))->toBe(0);
    });

    it('accepts a bare id, so a balance check need not hydrate the user', function () {
        $this->ledger->credit($this->user, 40, CoinTransactionReason::AdminCredit, 'k1');

        expect($this->ledger->balanceFor($this->user->id))->toBe(40);
    });

    it('keeps one user’s money out of another’s balance', function () {
        $other = User::factory()->telegram()->create();

        $this->ledger->credit($this->user, 100, CoinTransactionReason::StarsPurchase, 'mine');
        $this->ledger->credit($other, 500, CoinTransactionReason::StarsPurchase, 'theirs');

        expect($this->ledger->balanceFor($this->user))->toBe(100)
            ->and($this->ledger->balanceFor($other))->toBe(500);
    });

    it('names the drift when a row is written around the ledger', function () {
        // The running total is a cache. This is how we find out it has gone stale
        // rather than trusting it.
        $this->ledger->credit($this->user, 100, CoinTransactionReason::StarsPurchase, 'k1');

        CoinTransaction::factory()->for($this->user)->create([
            'amount' => 50,
            'reason' => CoinTransactionReason::AdminCredit,
            'balance_after' => 100, // wrong: should be 150
        ]);

        expect($this->ledger->balanceFor($this->user))->toBe(100)
            ->and($this->ledger->sum($this->user))->toBe(150)
            ->and($this->ledger->drift($this->user))->toBe(50);
    });

    it('answers whether a spend is affordable', function () {
        $this->ledger->credit($this->user, 50, CoinTransactionReason::StarsPurchase, 'k1');

        expect($this->ledger->canAfford($this->user, 50))->toBeTrue()
            ->and($this->ledger->canAfford($this->user, 51))->toBeFalse();
    });
});

describe('writing an entry', function () {
    it('derives the sign from the reason rather than the caller', function () {
        $credit = $this->ledger->credit($this->user, 100, CoinTransactionReason::StarsPurchase, 'c');
        $debit = $this->ledger->debit($this->user, 15, CoinTransactionReason::FreezePurchase, 'd');

        expect($credit->amount)->toBe(100)
            ->and($debit->amount)->toBe(-15)
            ->and($debit->hasConsistentSign())->toBeTrue();
    });

    it('stamps the balance the entry left behind', function () {
        $first = $this->ledger->credit($this->user, 100, CoinTransactionReason::StarsPurchase, 'k1');
        $second = $this->ledger->debit($this->user, 30, CoinTransactionReason::JoinSlotPurchase, 'k2');

        expect($first->balance_after)->toBe(100)
            ->and($second->balance_after)->toBe(70);
    });

    it('records what caused the entry', function () {
        $payment = StarPayment::factory()->for($this->user)->paid()->create();

        $entry = $this->ledger->credit(
            $this->user,
            $payment->coin_amount,
            CoinTransactionReason::StarsPurchase,
            $payment->creditIdempotencyKey(),
            $payment,
        );

        expect($entry->reference)->toBeInstanceOf(StarPayment::class)
            ->and($entry->reference?->getKey())->toBe($payment->id);
    });

    it('refuses a magnitude that is not positive', function (int $magnitude) {
        // Zero would burn an idempotency key on a no-op; negative is a caller
        // trying to choose the direction.
        expect(fn () => $this->ledger->credit($this->user, $magnitude, CoinTransactionReason::AdminCredit, 'k'))
            ->toThrow(InvalidArgumentException::class);

        expect($this->user->coinTransactions()->count())->toBe(0);
    })->with([
        'zero' => 0,
        'negative' => -10,
    ]);

    it('refuses a credit reason passed to debit, and the reverse', function () {
        expect(fn () => $this->ledger->debit($this->user, 10, CoinTransactionReason::AdminCredit, 'a'))
            ->toThrow(LogicException::class)
            ->and(fn () => $this->ledger->credit($this->user, 10, CoinTransactionReason::AdminDebit, 'b'))
            ->toThrow(LogicException::class);

        expect($this->user->coinTransactions()->count())->toBe(0);
    });

    it('writes an entry for every reason, with the direction the reason demands', function (CoinTransactionReason $reason) {
        // Start funded so a debit has something to take.
        $this->ledger->credit($this->user, 1_000, CoinTransactionReason::AdminCredit, 'float');

        $entry = $this->ledger->record($this->user, 20, $reason, "r:{$reason->value}");

        expect($entry->hasConsistentSign())->toBeTrue()
            ->and($entry->amount)->toBe(20 * $reason->sign())
            ->and($entry->balance_after)->toBe(1_000 + (20 * $reason->sign()));
    })->with(CoinTransactionReason::cases());
});

describe('refusing to overdraw', function () {
    it('rejects a spend the balance will not cover', function () {
        $this->ledger->credit($this->user, 20, CoinTransactionReason::StarsPurchase, 'k1');

        expect(fn () => $this->ledger->debit($this->user, 50, CoinTransactionReason::FreezePurchase, 'k2'))
            ->toThrow(InsufficientCoinsException::class);
    });

    it('leaves no entry behind when it refuses', function () {
        expect(fn () => $this->ledger->debit($this->user, 50, CoinTransactionReason::CreateSlotPurchase, 'k'))
            ->toThrow(InsufficientCoinsException::class);

        expect($this->user->coinTransactions()->count())->toBe(0)
            ->and($this->ledger->balanceFor($this->user))->toBe(0);
    });

    it('reports the shortfall, so a reply can say how many coins are missing', function () {
        $this->ledger->credit($this->user, 20, CoinTransactionReason::StarsPurchase, 'k1');

        try {
            $this->ledger->debit($this->user, 50, CoinTransactionReason::FreezePurchase, 'k2');
            $this->fail('Expected the debit to be refused.');
        } catch (InsufficientCoinsException $refusal) {
            expect($refusal->balance)->toBe(20)
                ->and($refusal->required)->toBe(50)
                ->and($refusal->shortfall())->toBe(30);
        }
    });

    it('allows a spend that lands exactly on zero', function () {
        $this->ledger->credit($this->user, 50, CoinTransactionReason::StarsPurchase, 'k1');
        $spend = $this->ledger->debit($this->user, 50, CoinTransactionReason::CreateSlotPurchase, 'k2');

        expect($spend->balance_after)->toBe(0);
    });

    it('lets a Stars refund claw back coins that are already spent', function () {
        // Real money went back to the user, so the reversal has to complete. The
        // negative balance is the correct outcome: they kept the goods.
        $this->ledger->credit($this->user, 100, CoinTransactionReason::StarsPurchase, 'buy');
        $this->ledger->debit($this->user, 100, CoinTransactionReason::CreateSlotPurchase, 'spend');

        $clawback = $this->ledger->debit($this->user, 100, CoinTransactionReason::StarsRefund, 'refund');

        expect($clawback->balance_after)->toBe(-100)
            ->and($this->ledger->balanceFor($this->user))->toBe(-100);
    });

    it('lets an admin correct a balance downwards past zero', function () {
        $correction = $this->ledger->debit($this->user, 10, CoinTransactionReason::AdminDebit, 'fix');

        expect($correction->balance_after)->toBe(-10);
    });

    it('blocks further spending while the balance is negative', function () {
        $this->ledger->debit($this->user, 10, CoinTransactionReason::AdminDebit, 'fix');

        expect(fn () => $this->ledger->debit($this->user, 1, CoinTransactionReason::FreezePurchase, 'k'))
            ->toThrow(InsufficientCoinsException::class);
    });

    it('still accepts a credit while the balance is negative', function () {
        // A credit can never be refused; it moves a debt towards zero.
        $this->ledger->debit($this->user, 100, CoinTransactionReason::AdminDebit, 'fix');

        $earned = $this->ledger->credit($this->user, 10, CoinTransactionReason::InviteCredit, 'invite');

        expect($earned->balance_after)->toBe(-90);
    });

    it('permits overdraft only for a clawback or an admin correction', function (CoinTransactionReason $reason, bool $allowed) {
        expect($reason->allowsOverdraft())->toBe($allowed);
    })->with([
        'stars refund' => [CoinTransactionReason::StarsRefund, true],
        'admin debit' => [CoinTransactionReason::AdminDebit, true],
        'create slot' => [CoinTransactionReason::CreateSlotPurchase, false],
        'join slot' => [CoinTransactionReason::JoinSlotPurchase, false],
        'freeze' => [CoinTransactionReason::FreezePurchase, false],
    ]);
});

describe('idempotency', function () {
    it('returns the original entry when the same key is used again', function () {
        // Telegram retries anything it does not get a 2xx for, so this is the
        // routine case, not the exotic one.
        $first = $this->ledger->credit($this->user, 100, CoinTransactionReason::StarsPurchase, 'charge_1');
        $second = $this->ledger->credit($this->user, 100, CoinTransactionReason::StarsPurchase, 'charge_1');

        expect($second->id)->toBe($first->id)
            ->and($this->user->coinTransactions()->count())->toBe(1)
            ->and($this->ledger->balanceFor($this->user))->toBe(100);
    });

    it('ignores the replayed amount rather than topping the balance up', function () {
        $this->ledger->credit($this->user, 100, CoinTransactionReason::StarsPurchase, 'charge_1');
        $replay = $this->ledger->credit($this->user, 999, CoinTransactionReason::StarsPurchase, 'charge_1');

        expect($replay->amount)->toBe(100)
            ->and($this->ledger->balanceFor($this->user))->toBe(100);
    });

    it('does not let a replayed debit spend twice', function () {
        $this->ledger->credit($this->user, 100, CoinTransactionReason::StarsPurchase, 'buy');

        $this->ledger->debit($this->user, 50, CoinTransactionReason::CreateSlotPurchase, 'slot_1');
        $this->ledger->debit($this->user, 50, CoinTransactionReason::CreateSlotPurchase, 'slot_1');

        expect($this->ledger->balanceFor($this->user))->toBe(50)
            ->and($this->user->coinTransactions()->count())->toBe(2);
    });

    it('treats a refund of a credited purchase as a separate key, so it can write', function () {
        $payment = StarPayment::factory()->for($this->user)->paid('charge_abc')->create();

        $this->ledger->credit(
            $this->user,
            $payment->coin_amount,
            CoinTransactionReason::StarsPurchase,
            $payment->creditIdempotencyKey(),
            $payment,
        );
        $this->ledger->debit(
            $this->user,
            $payment->coin_amount,
            CoinTransactionReason::StarsRefund,
            $payment->refundIdempotencyKey(),
            $payment,
        );

        expect($this->ledger->balanceFor($this->user))->toBe(0)
            ->and($this->user->coinTransactions()->count())->toBe(2);
    });

    it('finds the entry a key already wrote', function () {
        $entry = $this->ledger->credit($this->user, 10, CoinTransactionReason::InviteCredit, 'invite:7');

        expect($this->ledger->findByKey('invite:7')?->id)->toBe($entry->id)
            ->and($this->ledger->findByKey('invite:8'))->toBeNull();
    });

    it('refuses to hand one user’s entry to another user’s key collision', function () {
        // A key reused across users is a caller bug, not a replay; returning the
        // first user's transaction would credit the wrong person and report success.
        $other = User::factory()->telegram()->create();
        $this->ledger->credit($this->user, 100, CoinTransactionReason::StarsPurchase, 'shared');

        expect(fn () => $this->ledger->credit($other, 100, CoinTransactionReason::StarsPurchase, 'shared'))
            ->toThrow(LogicException::class);

        expect($this->ledger->balanceFor($other))->toBe(0)
            ->and($other->coinTransactions()->count())->toBe(0)
            ->and($this->user->coinTransactions()->count())->toBe(1);
    });
});
