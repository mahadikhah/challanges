<?php

use App\Enums\CoinTransactionReason;
use App\Models\CoinTransaction;
use App\Models\User;
use App\Services\CoinLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/*
 * The support surface: who is on the platform, what their ledger says, and the
 * one lever — moving coins by hand. The tests care that the balance comes from
 * the ledger (never a bare column write), that adjustments leave an audit
 * trail naming the admin who acted, and that the same `CoinLedger` rules —
 * lock, idempotency key, insufficient-funds refusal — apply to the panel
 * exactly as they do to the bot.
 */

function anAdminOperator(): User
{
    return User::factory()->admin()->create();
}

/**
 * A Telegram user as support would find them, optionally with coins already on
 * the ledger.
 */
function aTelegramMember(int $coins = 0, ?int $telegramId = null, ?string $username = null): User
{
    $user = User::factory()->telegram()->create([
        'first_name' => 'Sahar',
        'telegram_username' => $username ?? fake()->unique()->userName(),
        'telegram_id' => $telegramId ?? fake()->unique()->randomNumber(9, true),
    ]);

    if ($coins > 0) {
        app(CoinLedger::class)->credit(
            $user,
            $coins,
            CoinTransactionReason::StarsPurchase,
            "test:seed-{$user->getKey()}",
        );
    }

    return $user;
}

it('refuses a non-admin on the users routes', function (string $method, string $uri): void {
    $this->actingAs(User::factory()->create())->{$method}($uri)->assertForbidden();
})->with([
    'list' => ['get', '/admin/users'],
    'show' => ['get', '/admin/users/1'],
    'adjust' => ['post', '/admin/users/1/coins'],
]);

it('lists users newest first with their ledger balance', function (): void {
    $older = aTelegramMember(40);
    $newer = aTelegramMember(70);
    $admin = anAdminOperator();

    $this->actingAs($admin)->get('/admin/users')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('Admin/Users')
            ->has('users', 3)
            ->where('users.0.id', $admin->getKey())
            ->where('users.0.balance', 0)
            ->where('users.1.id', $newer->getKey())
            ->where('users.1.name', 'Sahar')
            ->where('users.1.balance', 70)
            ->where('users.1.telegram_id', $newer->telegram_id)
            ->where('users.1.telegram_username', $newer->telegram_username)
            ->where('users.1.is_admin', false)
            ->where('users.2.id', $older->getKey())
            ->where('users.2.balance', 40)
    );
});

it('finds users by telegram id, username, and name', function (string $query): void {
    aTelegramMember(0, 555000111, 'sahar');
    User::factory()->telegram()->create(['first_name' => 'Nasim']);

    $this->actingAs(anAdminOperator())->get("/admin/users?q={$query}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('users', 1));
})->with([
    'telegram id' => ['555000111'],
    'username' => ['sahar'],
    'first name' => ['Sahar'],
]);

it('shows one user with their balance, drift, and statement', function (): void {
    $user = aTelegramMember(0, 555000111, 'sahar');

    // A statement worth reading: 200 in, 50 out.
    $ledger = app(CoinLedger::class);
    $ledger->credit($user, 200, CoinTransactionReason::AdminCredit, 'test:seed-credit');
    $ledger->debit($user, 50, CoinTransactionReason::AdminDebit, 'test:seed-debit');

    $this->actingAs(anAdminOperator())->get("/admin/users/{$user->getKey()}")
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Admin/Users/Show')
                ->where('user.name', 'Sahar')
                ->where('user.telegram_id', 555000111)
                ->where('balance', 150)
                ->where('drift', 0)
                ->has('transactions', 2)
                ->where('transactions.0.reason.value', 'admin_debit')
                ->where('transactions.0.reason.label', 'Removed by an admin')
                ->where('transactions.0.amount', -50)
                ->where('transactions.0.balance_after', 150)
                ->where('transactions.1.amount', 200)
                ->where('transactions.1.balance_after', 200),
        );
});

it('credits coins through the ledger, naming the admin who acted', function (): void {
    $admin = anAdminOperator();
    $user = aTelegramMember(40);

    $this->actingAs($admin)
        ->from("/admin/users/{$user->getKey()}")
        ->post("/admin/users/{$user->getKey()}/coins", [
            'amount' => 25,
            'direction' => 'credit',
        ])
        ->assertRedirect("/admin/users/{$user->getKey()}");

    $entry = CoinTransaction::query()->where('reason', CoinTransactionReason::AdminCredit)->sole();

    expect($entry->amount)->toBe(25)
        ->and($entry->balance_after)->toBe(65)
        // The reference is the actor — the audit trail the panel keeps.
        ->and($entry->reference->is($admin))->toBeTrue()
        ->and($entry->idempotency_key)->toStartWith('admin_adjustment:')
        // The key is random, so it can never collide with another call's —
        // but it still must have been *present* and unique.
        ->and(CoinTransaction::query()->where('idempotency_key', $entry->idempotency_key)->count())->toBe(1);
});

it('debits coins through the ledger', function (): void {
    $user = aTelegramMember(100);

    $this->actingAs(anAdminOperator())
        ->from("/admin/users/{$user->getKey()}")
        ->post("/admin/users/{$user->getKey()}/coins", [
            'amount' => 30,
            'direction' => 'debit',
        ])
        ->assertRedirect("/admin/users/{$user->getKey()}");

    $entry = CoinTransaction::query()->where('reason', CoinTransactionReason::AdminDebit)->sole();

    expect($entry->amount)->toBe(-30)
        ->and($entry->balance_after)->toBe(70)
        ->and(app(CoinLedger::class)->balanceFor($user))->toBe(70);
});

it('lets an admin debit take the balance negative, per the clawback rule', function (): void {
    // A manual removal is a clawback, and a clawback a balance could veto is
    // one that cannot happen — the domain already decided this (`AdminDebit`
    // allows overdraft), so the panel offers no refusal path for it either.
    $user = aTelegramMember(0);

    $this->actingAs(anAdminOperator())
        ->from("/admin/users/{$user->getKey()}")
        ->post("/admin/users/{$user->getKey()}/coins", [
            'amount' => 10,
            'direction' => 'debit',
        ])
        ->assertRedirect("/admin/users/{$user->getKey()}")
        ->assertSessionHas(SessionKey::FLASH_DATA, [
            'toast' => [
                'type' => 'success',
                'message' => __('admin.users.adjusted'),
            ],
        ]);

    expect(app(CoinLedger::class)->balanceFor($user))->toBe(-10)
        ->and(CoinTransaction::query()->count())->toBe(1);
});

it('rejects a non-positive amount and an unknown direction', function (array $payload, string $field): void {
    $user = aTelegramMember(50);

    $this->actingAs(anAdminOperator())
        ->from("/admin/users/{$user->getKey()}")
        ->post("/admin/users/{$user->getKey()}/coins", $payload)
        ->assertInvalid($field);

    expect(CoinTransaction::query()->count())->toBe(1); // only the seed credit
})->with([
    'zero amount' => [['amount' => 0, 'direction' => 'credit'], 'amount'],
    'negative amount' => [['amount' => -5, 'direction' => 'credit'], 'amount'],
    'missing amount' => [['direction' => 'credit'], 'amount'],
    'unknown direction' => [['amount' => 5, 'direction' => 'sideways'], 'direction'],
]);
