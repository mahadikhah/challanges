<?php

use App\Actions\Payments\SweepAbandonedStarPayments;
use App\Enums\CoinTransactionReason;
use App\Enums\InviteStatus;
use App\Enums\StarPaymentStatus;
use App\Models\Invite;
use App\Models\StarPayment;
use App\Models\User;
use App\Services\CoinLedger;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/*
 * The two read-mostly audit tables and the refund lever. The refund itself is
 * `RefundStarsPayment`'s behaviour (tested in the Payments suite); what these
 * tests pin is that the panel reaches for that action — Telegram first, coins
 * clawed back through the ledger — and reports a refusal as a toast rather
 * than a 500.
 */

beforeEach(function (): void {
    // Deliberately NOT setting a bot token here: reading an audit page must
    // not need one. Only the refund touches Telegram, and there is a test
    // below that says exactly that about the token-less GET.
});

it('refuses a non-admin on the payments and invites routes', function (string $method, string $uri): void {
    $this->actingAs(User::factory()->create())->{$method}($uri)->assertForbidden();
})->with([
    'payments list' => ['get', '/admin/payments'],
    'refund' => ['post', '/admin/payments/1/refund'],
    'invites list' => ['get', '/admin/invites'],
]);

it('lists star payments newest first with their refundability', function (): void {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->telegram()->create(['first_name' => 'Sahar']);

    $fresh = StarPayment::factory()->for($user)->paid('charge-fresh')->buying(110, 100)->create();
    StarPayment::factory()->for($user)->create(); // the factory default: pending
    StarPayment::factory()->for($user)->refunded()->create();

    $this->actingAs($admin)->get('/admin/payments')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('Admin/Payments')
            ->has('payments', 3)
            // simplePaginate is newest-first by the query, and the factories
            // above ran in that order.
            ->where('payments.0.status.value', 'refunded')
            ->where('payments.0.refundable', false)
            ->where('payments.1.status.value', 'pending')
            ->where('payments.1.refundable', false)
            ->where('payments.2.status.value', 'paid')
            ->where('payments.2.refundable', true)
            ->where('payments.2.user', 'Sahar')
            ->where('payments.2.telegram_payment_charge_id', 'charge-fresh')
            ->where('payments.2.stars_amount', 100)
            ->where('payments.2.coin_amount', 110),
    );

    expect($fresh->getKey())->toBeInt();
});

it('refunds a paid purchase through the shared action', function (): void {
    config(['services.telegram.bot_token' => '123456:TEST-TOKEN']);
    Http::fake(['*refundStarPayment*' => Http::response(['ok' => true, 'result' => true])]);

    $admin = User::factory()->admin()->create();
    $user = User::factory()->telegram()->create();

    $payment = StarPayment::factory()->for($user)->paid('charge-refund')->buying(110, 100)->create();
    app(CoinLedger::class)->credit(
        $user,
        110,
        CoinTransactionReason::StarsPurchase,
        $payment->creditIdempotencyKey(),
        $payment,
    );

    $this->actingAs($admin)
        ->from('/admin/payments')
        ->post("/admin/payments/{$payment->getKey()}/refund")
        ->assertRedirect('/admin/payments')
        ->assertSessionHas(SessionKey::FLASH_DATA, [
            'toast' => [
                'type' => 'success',
                'message' => __('admin.payments.refunded'),
            ],
        ]);

    expect($payment->refresh()->status)->toBe(StarPaymentStatus::Refunded)
        ->and(app(CoinLedger::class)->balanceFor($user))->toBe(0)
        // The wire got the one refund call the action makes.
        ->and(Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'refundStarPayment')))
        ->toHaveCount(1);
});

it('reports an unrefundable payment as a toast, not a 500', function (): void {
    config(['services.telegram.bot_token' => '123456:TEST-TOKEN']);
    Http::fake(['*refundStarPayment*' => Http::response(['ok' => true, 'result' => true])]);

    $admin = User::factory()->admin()->create();
    $payment = StarPayment::factory()->create(); // pending by default

    $this->actingAs($admin)
        ->from('/admin/payments')
        ->post("/admin/payments/{$payment->getKey()}/refund")
        ->assertRedirect('/admin/payments')
        ->assertSessionHas(SessionKey::FLASH_DATA, [
            'toast' => [
                'type' => 'error',
                'message' => __('admin.payments.refund_refused'),
            ],
        ]);

    expect($payment->refresh()->status)->toBe(StarPaymentStatus::Pending)
        ->and(Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'refundStarPayment')))
        ->toBeEmpty();
});

it('lists invites with their crediting state', function (): void {
    $admin = User::factory()->admin()->create();

    $inviter = User::factory()->telegram()->create(['first_name' => 'Nasim']);
    $newcomer = User::factory()->telegram()->create(['first_name' => 'Sahar']);
    $existing = User::factory()->telegram()->create(['first_name' => 'Parisa']);

    $credited = Invite::factory()->for($inviter, 'inviter')->creditedFor($newcomer)->create();
    $claimed = Invite::factory()->for($inviter, 'inviter')->claimedBy($existing)->create();
    Invite::factory()->for($inviter, 'inviter')->create(); // never used

    $this->actingAs($admin)->get('/admin/invites')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('Admin/Invites')
            ->has('invites', 3)
            ->where('invites.0.status.value', InviteStatus::Pending->value)
            ->where('invites.0.invited', null)
            ->where('invites.1.status.value', InviteStatus::Claimed->value)
            ->where('invites.1.invited', 'Parisa')
            ->where('invites.1.credited_at', null)
            ->where('invites.2.status.value', InviteStatus::Credited->value)
            ->where('invites.2.inviter', 'Nasim')
            ->where('invites.2.invited', 'Sahar')
            ->where('invites.2.credited_at', $credited->credited_at->toIso8601String()),
    );
});

it('reads the audit pages without a bot token being set', function (): void {
    // A read has nothing to send, so an unset `TELEGRAM_BOT_TOKEN` — a box
    // that has never spoken to Telegram — must not stop the page rendering.
    // The Bot API binding refuses to build without a token, so this pins the
    // injection shape: read routes never resolve it.
    config(['services.telegram.bot_token' => '']);

    $admin = User::factory()->admin()->create();
    StarPayment::factory()->paid('charge-readable')->create();
    Invite::factory()->create();

    $this->actingAs($admin)->get('/admin/payments')->assertOk();
    $this->actingAs($admin)->get('/admin/invites')->assertOk();
    $this->actingAs($admin)->get('/admin/users')->assertOk();
    $this->actingAs($admin)->get('/admin/reviews')->assertOk();
});

it('sweeps only pending payments past the abandonment window', function (): void {
    $user = User::factory()->telegram()->create();

    $stale = anAgedPayment($user, now()->subDay()->subHour());
    $fresh = anAgedPayment($user, now()->subHour());
    $oldButPaid = anAgedPayment($user, now()->subWeek(), 'charge-old', 'paid');

    $swept = app(SweepAbandonedStarPayments::class)->handle();

    expect($swept)->toBe(1)
        ->and($stale->refresh()->status)->toBe(StarPaymentStatus::Failed)
        ->and($fresh->refresh()->status)->toBe(StarPaymentStatus::Pending)
        ->and($oldButPaid->refresh()->status)->toBe(StarPaymentStatus::Paid);
});

it('sweeps nothing when the window has not passed', function (): void {
    anAgedPayment(null, now()->subHours(23));

    expect(app(SweepAbandonedStarPayments::class)->handle())->toBe(0);
});

/**
 * A pending payment backdated to the given moment. `created_at` is not
 * fillable on the model, so the age is forced after the row exists.
 */
function anAgedPayment(?User $user, CarbonInterface $createdAt, ?string $chargeId = null, string $state = 'pending'): StarPayment
{
    // The factory's default state is already pending, so only the paid and
    // refunded states are named.
    $factory = $state === 'pending'
        ? StarPayment::factory()
        : StarPayment::factory()->{$state}($chargeId ?? 'charge-'.fake()->unique()->uuid());

    if ($user !== null) {
        $factory = $factory->for($user);
    }

    $payment = $factory->create();
    $payment->forceFill(['created_at' => $createdAt])->save();

    return $payment;
}
