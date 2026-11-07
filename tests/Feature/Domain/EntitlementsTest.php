<?php

use App\Actions\Entitlements\ConsumeEntitlement;
use App\Actions\Entitlements\GrantFreeBaseline;
use App\Actions\Entitlements\PurchaseEntitlement;
use App\Enums\CoinTransactionReason;
use App\Enums\EntitlementSource;
use App\Enums\EntitlementType;
use App\Enums\SettingKey;
use App\Exceptions\InsufficientCoinsException;
use App\Exceptions\NoEntitlementAvailableException;
use App\Models\Challenge;
use App\Models\Entitlement;
use App\Models\User;
use App\Services\CoinLedger;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->settings = app(Settings::class);
    $this->ledger = app(CoinLedger::class);
    $this->grant = app(GrantFreeBaseline::class);
    $this->purchase = app(PurchaseEntitlement::class);
    $this->consume = app(ConsumeEntitlement::class);
    $this->user = User::factory()->telegram()->create();
});

/**
 * Fund a user without going through a purchase, so a spend has coins to take.
 */
function fund(User $user, int $coins): void
{
    app(CoinLedger::class)->credit($user, $coins, CoinTransactionReason::AdminCredit, "float:{$user->id}");
}

describe('the free baseline', function () {
    it('grants the allowance the settings declare', function () {
        $granted = $this->grant->handle($this->user);

        expect($granted)->toHaveCount(2)
            ->and($this->consume->available($this->user, EntitlementType::CreateSlot))->toBe(1)
            ->and($this->consume->available($this->user, EntitlementType::JoinSlot))->toBe(1);
    });

    it('marks the grants as never having been billed', function () {
        $this->grant->handle($this->user);

        expect($this->user->entitlements()->pluck('source')->unique()->all())
            ->toBe([EntitlementSource::FreeBaseline])
            ->and($this->user->coinTransactions()->count())->toBe(0)
            ->and($this->ledger->balanceFor($this->user))->toBe(0);
    });

    it('is safe to run on every /start', function () {
        // The bot calls this on every `/start`, which for a returning user is most
        // of the traffic. It has to be a no-op there.
        $this->grant->handle($this->user);
        $second = $this->grant->handle($this->user);

        expect($second)->toHaveCount(0)
            ->and($this->user->entitlements()->count())->toBe(2);
    });

    it('counts a slot the user already spent towards their allowance', function () {
        // Having *had* the free challenge is what the allowance measures. Otherwise
        // `/start` would mint a fresh free slot after every join, forever.
        $this->grant->handle($this->user);
        $this->consume->handle($this->user, EntitlementType::JoinSlot, Challenge::factory()->create());

        $this->grant->handle($this->user);

        expect($this->user->entitlements()->where('type', EntitlementType::JoinSlot)->count())->toBe(1);
    });

    it('tops existing users up when an admin raises the allowance', function () {
        $this->grant->handle($this->user);

        $this->settings->set(SettingKey::FreeJoinSlots, 3);
        $added = $this->grant->handle($this->user);

        expect($added)->toHaveCount(2)
            ->and($this->consume->available($this->user, EntitlementType::JoinSlot))->toBe(3)
            ->and($this->consume->available($this->user, EntitlementType::CreateSlot))->toBe(1);
    });

    it('never revokes a slot when an admin lowers the allowance', function () {
        // A held slot may already be spent on a live challenge; un-granting it would
        // mean removing someone from a challenge they are mid-streak in.
        $this->settings->set(SettingKey::FreeJoinSlots, 3);
        $this->grant->handle($this->user, EntitlementType::JoinSlot);

        $this->settings->set(SettingKey::FreeJoinSlots, 1);
        $this->grant->handle($this->user, EntitlementType::JoinSlot);

        expect($this->consume->available($this->user, EntitlementType::JoinSlot))->toBe(3)
            ->and($this->grant->owed($this->user, EntitlementType::JoinSlot))->toBe(0);
    });

    it('grants nothing when the allowance is switched off', function () {
        $this->settings->set(SettingKey::FreeCreateSlots, 0);
        $this->settings->set(SettingKey::FreeJoinSlots, 0);

        expect($this->grant->handle($this->user))->toHaveCount(0)
            ->and($this->user->entitlements()->count())->toBe(0);
    });

    it('can be scoped to one type', function (EntitlementType $type) {
        $granted = $this->grant->handle($this->user, $type);

        expect($granted)->toHaveCount(1)
            ->and($granted->first()?->type)->toBe($type)
            ->and($this->user->entitlements()->count())->toBe(1);
    })->with([
        'create slot' => EntitlementType::CreateSlot,
        'join slot' => EntitlementType::JoinSlot,
    ]);

    it('reports what a user is still owed', function () {
        expect($this->grant->owed($this->user, EntitlementType::CreateSlot))->toBe(1);

        $this->grant->handle($this->user, EntitlementType::CreateSlot);

        expect($this->grant->owed($this->user, EntitlementType::CreateSlot))->toBe(0);
    });

    it('does not count a bought slot towards the free allowance', function () {
        // Paying for a slot must not use up the free one the platform owes them.
        fund($this->user, 500);
        $this->purchase->handle($this->user, EntitlementType::CreateSlot, 'buy:1');

        expect($this->grant->owed($this->user, EntitlementType::CreateSlot))->toBe(1)
            ->and($this->grant->handle($this->user, EntitlementType::CreateSlot))->toHaveCount(1)
            ->and($this->consume->available($this->user, EntitlementType::CreateSlot))->toBe(2);
    });

    it('keeps one user’s baseline out of another’s', function () {
        $other = User::factory()->telegram()->create();

        $this->grant->handle($this->user);

        expect($other->entitlements()->count())->toBe(0)
            ->and($this->grant->owed($other, EntitlementType::JoinSlot))->toBe(1);
    });
});

describe('buying an extra slot', function () {
    it('debits the price and hands back an unspent slot', function () {
        fund($this->user, 100);

        $slot = $this->purchase->handle($this->user, EntitlementType::JoinSlot, 'buy:1');

        expect($slot->source)->toBe(EntitlementSource::CoinPurchase)
            ->and($slot->type)->toBe(EntitlementType::JoinSlot)
            ->and($slot->isAvailable())->toBeTrue()
            ->and($slot->wasPaidFor())->toBeTrue()
            ->and($this->ledger->balanceFor($this->user))->toBe(75);
    });

    it('reads the price from settings rather than a constant', function () {
        fund($this->user, 100);
        $this->settings->set(SettingKey::JoinSlotCoinPrice, 40);

        $this->purchase->handle($this->user, EntitlementType::JoinSlot, 'buy:1');

        expect($this->purchase->priceOf(EntitlementType::JoinSlot))->toBe(40)
            ->and($this->ledger->balanceFor($this->user))->toBe(60);
    });

    it('charges each type its own price, under its own reason', function (EntitlementType $type, SettingKey $priceKey, CoinTransactionReason $reason) {
        fund($this->user, 500);
        $this->settings->set($priceKey, 33);

        $slot = $this->purchase->handle($this->user, $type, 'buy:1');
        $entry = $this->user->coinTransactions()->where('reason', $reason)->sole();

        expect($entry->amount)->toBe(-33)
            ->and($entry->reference?->getKey())->toBe($slot->getKey());
    })->with([
        'create slot' => [EntitlementType::CreateSlot, SettingKey::CreateSlotCoinPrice, CoinTransactionReason::CreateSlotPurchase],
        'join slot' => [EntitlementType::JoinSlot, SettingKey::JoinSlotCoinPrice, CoinTransactionReason::JoinSlotPurchase],
    ]);

    it('points the ledger entry at the slot it paid for', function () {
        // The morph is what makes a purchase auditable in both directions, and it is
        // how a replay finds the slot the original bought.
        fund($this->user, 100);

        $slot = $this->purchase->handle($this->user, EntitlementType::JoinSlot, 'buy:1');
        $entry = $this->user->coinTransactions()->where('reason', CoinTransactionReason::JoinSlotPurchase)->sole();

        expect($entry->reference)->toBeInstanceOf(Entitlement::class)
            ->and($entry->reference?->getKey())->toBe($slot->getKey());
    });

    it('refuses a purchase the balance will not cover', function () {
        fund($this->user, 10);

        expect(fn () => $this->purchase->handle($this->user, EntitlementType::JoinSlot, 'buy:1'))
            ->toThrow(InsufficientCoinsException::class);
    });

    it('leaves no slot behind when it cannot be paid for', function () {
        // The slot row is inserted before the debit so the ledger entry can
        // reference it, which means the rollback is the only thing standing between
        // a refused purchase and a free slot.
        expect(fn () => $this->purchase->handle($this->user, EntitlementType::CreateSlot, 'buy:1'))
            ->toThrow(InsufficientCoinsException::class);

        expect($this->user->entitlements()->count())->toBe(0)
            ->and($this->user->coinTransactions()->count())->toBe(0)
            ->and($this->ledger->balanceFor($this->user))->toBe(0);
    });

    it('returns the original slot when the purchase is replayed', function () {
        // A double-tapped "buy" button. One slot, charged once.
        fund($this->user, 100);

        $first = $this->purchase->handle($this->user, EntitlementType::JoinSlot, 'buy:1');
        $second = $this->purchase->handle($this->user, EntitlementType::JoinSlot, 'buy:1');

        expect($second->getKey())->toBe($first->getKey())
            ->and($this->user->entitlements()->count())->toBe(1)
            ->and($this->ledger->balanceFor($this->user))->toBe(75);
    });

    it('sells a second slot under a fresh key', function () {
        fund($this->user, 100);

        $this->purchase->handle($this->user, EntitlementType::JoinSlot, 'buy:1');
        $this->purchase->handle($this->user, EntitlementType::JoinSlot, 'buy:2');

        expect($this->consume->available($this->user, EntitlementType::JoinSlot))->toBe(2)
            ->and($this->ledger->balanceFor($this->user))->toBe(50);
    });

    it('refuses a key that already paid for a different slot type', function () {
        // Reusing a key across types would hand back a join slot to someone buying a
        // create slot, and report it as a successful purchase.
        fund($this->user, 500);
        $this->purchase->handle($this->user, EntitlementType::JoinSlot, 'buy:1');

        expect(fn () => $this->purchase->handle($this->user, EntitlementType::CreateSlot, 'buy:1'))
            ->toThrow(LogicException::class);

        expect($this->user->entitlements()->count())->toBe(1);
    });

    it('refuses a key that paid for something that is not a slot at all', function () {
        fund($this->user, 500);

        expect(fn () => $this->purchase->handle($this->user, EntitlementType::JoinSlot, "float:{$this->user->id}"))
            ->toThrow(LogicException::class);

        expect($this->user->entitlements()->count())->toBe(0);
    });

    it('refuses to sell a slot that is priced at nothing', function (int $price) {
        // A zero price is an allowance change, not a sale; writing a `CoinPurchase`
        // row for it would misreport the slot as paid for.
        $this->settings->set(SettingKey::JoinSlotCoinPrice, $price);

        expect(fn () => $this->purchase->handle($this->user, EntitlementType::JoinSlot, 'buy:1'))
            ->toThrow(InvalidArgumentException::class);

        expect($this->user->entitlements()->count())->toBe(0);
    })->with([
        'free' => 0,
        'negative' => -5,
    ]);

    it('quotes the price before charging for it', function () {
        $this->settings->set(SettingKey::CreateSlotCoinPrice, 77);

        expect($this->purchase->priceOf(EntitlementType::CreateSlot))->toBe(77)
            ->and($this->user->coinTransactions()->count())->toBe(0);
    });
});

describe('spending a slot', function () {
    it('ties the slot to the challenge it was spent on', function () {
        $challenge = Challenge::factory()->create();
        $this->grant->handle($this->user, EntitlementType::JoinSlot);

        $spent = $this->consume->handle($this->user, EntitlementType::JoinSlot, $challenge);

        expect($spent->isConsumed())->toBeTrue()
            ->and($spent->challenge_id)->toBe($challenge->id)
            ->and($this->consume->available($this->user, EntitlementType::JoinSlot))->toBe(0);
    });

    it('refuses when the user holds no slot, naming the type so a price can be quoted', function () {
        try {
            $this->consume->handle($this->user, EntitlementType::CreateSlot, Challenge::factory()->create());
            $this->fail('Expected the spend to be refused.');
        } catch (NoEntitlementAvailableException $refusal) {
            expect($refusal->type)->toBe(EntitlementType::CreateSlot);
        }
    });

    it('will not spend a slot of the wrong type', function () {
        $this->grant->handle($this->user, EntitlementType::JoinSlot);

        expect(fn () => $this->consume->handle($this->user, EntitlementType::CreateSlot, Challenge::factory()->create()))
            ->toThrow(NoEntitlementAvailableException::class);

        expect($this->consume->available($this->user, EntitlementType::JoinSlot))->toBe(1);
    });

    it('spends the free slot before a paid one', function () {
        // A free slot is never billed and never refunded, so it has no residual
        // value; the bought one does. Spend the worthless one first.
        fund($this->user, 100);
        $this->grant->handle($this->user, EntitlementType::JoinSlot);
        $bought = $this->purchase->handle($this->user, EntitlementType::JoinSlot, 'buy:1');

        $spent = $this->consume->handle($this->user, EntitlementType::JoinSlot, Challenge::factory()->create());

        expect($spent->source)->toBe(EntitlementSource::FreeBaseline)
            ->and($bought->refresh()->isAvailable())->toBeTrue();
    });

    it('spends the oldest paid slot once the free one is gone', function () {
        fund($this->user, 100);
        $first = $this->purchase->handle($this->user, EntitlementType::JoinSlot, 'buy:1');
        $this->purchase->handle($this->user, EntitlementType::JoinSlot, 'buy:2');

        $spent = $this->consume->handle($this->user, EntitlementType::JoinSlot, Challenge::factory()->create());

        expect($spent->getKey())->toBe($first->getKey());
    });

    it('spends one slot however many times the same join is submitted', function () {
        // A double-tapped join button, or a retried webhook. The second call must
        // find the slot already spent on this challenge rather than eat another.
        $challenge = Challenge::factory()->create();
        $this->settings->set(SettingKey::FreeJoinSlots, 2);
        $this->grant->handle($this->user, EntitlementType::JoinSlot);

        $first = $this->consume->handle($this->user, EntitlementType::JoinSlot, $challenge);
        $second = $this->consume->handle($this->user, EntitlementType::JoinSlot, $challenge);

        expect($second->getKey())->toBe($first->getKey())
            ->and($this->consume->available($this->user, EntitlementType::JoinSlot))->toBe(1);
    });

    it('spends a second slot on a different challenge', function () {
        $this->settings->set(SettingKey::FreeJoinSlots, 2);
        $this->grant->handle($this->user, EntitlementType::JoinSlot);

        $first = $this->consume->handle($this->user, EntitlementType::JoinSlot, Challenge::factory()->create());
        $second = $this->consume->handle($this->user, EntitlementType::JoinSlot, Challenge::factory()->create());

        expect($second->getKey())->not->toBe($first->getKey())
            ->and($this->consume->available($this->user, EntitlementType::JoinSlot))->toBe(0);
    });

    it('meters creating and joining the same challenge separately', function () {
        // A creator who also takes part spends both slots on one challenge, and
        // neither satisfies a claim for the other.
        $challenge = Challenge::factory()->create();
        $this->grant->handle($this->user);

        $created = $this->consume->handle($this->user, EntitlementType::CreateSlot, $challenge);
        $joined = $this->consume->handle($this->user, EntitlementType::JoinSlot, $challenge);

        expect($created->getKey())->not->toBe($joined->getKey())
            ->and($created->type)->toBe(EntitlementType::CreateSlot)
            ->and($joined->type)->toBe(EntitlementType::JoinSlot);
    });

    it('never reaches for another user’s slot', function () {
        $other = User::factory()->telegram()->create();
        $this->grant->handle($other);

        expect(fn () => $this->consume->handle($this->user, EntitlementType::JoinSlot, Challenge::factory()->create()))
            ->toThrow(NoEntitlementAvailableException::class);

        expect($this->consume->available($other, EntitlementType::JoinSlot))->toBe(1);
    });

    it('keeps the slot spent when the challenge is deleted', function () {
        // Deleting a challenge is not a refund route. `challenge_id` nulls out,
        // `consumed_at` does not.
        $challenge = Challenge::factory()->create();
        $this->grant->handle($this->user, EntitlementType::JoinSlot);
        $spent = $this->consume->handle($this->user, EntitlementType::JoinSlot, $challenge);

        $challenge->delete();

        expect($spent->refresh()->challenge_id)->toBeNull()
            ->and($spent->isConsumed())->toBeTrue()
            ->and($this->consume->available($this->user, EntitlementType::JoinSlot))->toBe(0);
    });

    it('does not treat an unconsumed slot already pointing at a challenge as spent', function () {
        // Defensive: `consumed_at` is what marks a spend, not `challenge_id`.
        $challenge = Challenge::factory()->create();
        Entitlement::factory()->for($this->user)->joinSlot()->create(['challenge_id' => $challenge->id]);

        $spent = $this->consume->handle($this->user, EntitlementType::JoinSlot, $challenge);

        expect($spent->isConsumed())->toBeTrue()
            ->and($this->user->entitlements()->count())->toBe(1);
    });
});

describe('the per-user mutex', function () {
    it('is held by every economy action, so they queue behind one another', function () {
        // Not a race test — that lives in CoinLedgerConcurrencyTest. This just pins
        // the requirement that each action opens a transaction before touching the
        // user, which is what makes the lock worth taking.
        fund($this->user, 100);

        DB::transaction(function () {
            $this->ledger->lockUser($this->user);

            $this->grant->handle($this->user);
            $this->purchase->handle($this->user, EntitlementType::JoinSlot, 'buy:1');
            $this->consume->handle($this->user, EntitlementType::JoinSlot, Challenge::factory()->create());
        });

        expect($this->consume->available($this->user, EntitlementType::JoinSlot))->toBe(1)
            ->and($this->ledger->balanceFor($this->user))->toBe(75);
    });
});
