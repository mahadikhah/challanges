<?php

use App\Actions\Payments\RefundStarsPayment;
use App\Enums\CoinTransactionReason;
use App\Enums\StarPaymentStatus;
use App\Models\CoinTransaction;
use App\Models\StarPayment;
use App\Services\CoinLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Telegram\Bot\Exceptions\TelegramSDKException;

uses(RefreshDatabase::class);

/*
 * `RefundStarsPayment` — the one way a purchase unwinds. Telegram first, then
 * the row, then the clawback, so a refusal from either side leaves the state
 * that can be retried rather than half a refund.
 *
 * There is no bot surface here yet: the admin panel will call this action, and
 * its behaviour belongs to the action, not to whichever surface eventually
 * reaches for it.
 */

beforeEach(function () {
    Http::preventStrayRequests();

    config(['services.telegram.bot_token' => '123456:TEST-TOKEN']);

    $this->ledger = app(CoinLedger::class);
});

/**
 * A purchase exactly as `CompleteStarsPayment` leaves one: paid, charged, and
 * credited to the ledger under the credit's idempotency key.
 */
function aBoughtTopUp(int $coins = 110, int $stars = 100): StarPayment
{
    $payment = StarPayment::factory()
        ->buying($coins, $stars)
        ->paid('charge-refund')
        ->create();

    app(CoinLedger::class)->credit(
        $payment->user,
        $payment->coin_amount,
        CoinTransactionReason::StarsPurchase,
        $payment->creditIdempotencyKey(),
        $payment,
    );

    return $payment;
}

/**
 * The one `refundStarPayment` the bot made, decoded.
 *
 * @return array<string, string>
 */
function theRefundRequest(): array
{
    $calls = Http::recorded(
        fn (Request $request): bool => str_contains($request->url(), 'refundStarPayment'),
    )->values();

    expect($calls)->toHaveCount(1);

    $sent = [];
    parse_str($calls[0][0]->body(), $sent);

    /** @var array<string, string> $sent */
    return $sent;
}

it('returns the stars and claws the coins back', function () {
    Http::fake(['*refundStarPayment*' => Http::response(['ok' => true, 'result' => true])]);

    $payment = aBoughtTopUp();

    $refunded = app(RefundStarsPayment::class)->handle($payment);

    expect($refunded->status)->toBe(StarPaymentStatus::Refunded)
        ->and($refunded->refunded_at)->not->toBeNull()

        // The wire: Telegram gets the payer's id and the charge of its own
        // money back — nothing else identifies a refund.
        ->and(theRefundRequest())->toBe([
            'user_id' => (string) $payment->user->telegram_id,
            'telegram_payment_charge_id' => 'charge-refund',
        ])

        // The ledger: one entry out for the whole purchase, keyed on the
        // refund's own key — deliberately not the credit's, so a refund and a
        // replay of the credit can never collide.
        ->and($this->ledger->balanceFor($payment->user))->toBe(0)
        ->and($this->ledger->drift($payment->user))->toBe(0);

    $clawback = CoinTransaction::query()->where('reason', CoinTransactionReason::StarsRefund)->sole();

    expect($clawback->amount)->toBe(-110)
        ->and($clawback->idempotency_key)->toBe($payment->refundIdempotencyKey())
        ->and($clawback->reference->is($payment))->toBeTrue();
});

it('may take the balance negative', function () {
    Http::fake(['*refundStarPayment*' => Http::response(['ok' => true, 'result' => true])]);

    $payment = aBoughtTopUp();

    // The coins were spent between purchase and refund — on what is not this
    // action's business. The user has had both the goods and the Stars back,
    // and the ledger says so in the negative until it is cleared.
    $this->ledger->debit(
        $payment->user,
        80,
        CoinTransactionReason::FreezePurchase,
        'test:spent-freezes',
    );

    app(RefundStarsPayment::class)->handle($payment);

    expect($this->ledger->balanceFor($payment->user))->toBe(-80)
        ->and($this->ledger->drift($payment->user))->toBe(0);
});

it('refuses a payment that is not a paid one', function (string $state) {
    Http::fake(['*refundStarPayment*' => Http::response(['ok' => true, 'result' => true])]);

    $payment = StarPayment::factory()->{$state}()->buying(110, 100)->create();

    app(RefundStarsPayment::class)->handle($payment);
})->with([
    'still pending' => ['pending'],
    'already refunded' => ['refunded'],
    'failed' => ['failed'],
])->throws(LogicException::class);

it('calls no refund the ledger would then have to explain', function () {
    Http::fake(['*refundStarPayment*' => Http::response(['ok' => true, 'result' => true])]);

    $payment = StarPayment::factory()->buying(110, 100)->create();

    try {
        app(RefundStarsPayment::class)->handle($payment);
    } catch (LogicException) {
        // Expected — asserted separately; here we only care about the side
        // effects the refusal must not have had.
    }

    expect(CoinTransaction::query()->count())->toBe(0)
        ->and(Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'refundStarPayment')))
        ->toBeEmpty();
});

it('leaves the row paid when Telegram refuses the refund', function () {
    // Telegram's own refusal shape: `ok: false`, which the SDK raises as an
    // exception rather than returning.
    Http::fake(['*refundStarPayment*' => Http::response([
        'ok' => false,
        'error_code' => 400,
        'description' => 'CHARGE_ALREADY_REFUNDED',
    ], 400)]);

    $payment = aBoughtTopUp();

    app(RefundStarsPayment::class)->handle($payment);
})->throws(TelegramSDKException::class);

it('writes nothing when Telegram refuses the refund', function () {
    Http::fake(['*refundStarPayment*' => Http::response([
        'ok' => false,
        'error_code' => 400,
        'description' => 'CHARGE_ALREADY_REFUNDED',
    ], 400)]);

    $payment = aBoughtTopUp();

    try {
        app(RefundStarsPayment::class)->handle($payment);
    } catch (TelegramSDKException) {
        // Expected — the refusal itself is the other test's business.
    }

    // Telegram-first ordering: the row is still `Paid` — the whole refund is
    // retryable — and not one coin has moved.
    expect($payment->refresh()->status)->toBe(StarPaymentStatus::Paid)
        ->and($payment->refunded_at)->toBeNull()
        ->and($this->ledger->balanceFor($payment->user))->toBe(110)
        ->and(CoinTransaction::query()->where('reason', CoinTransactionReason::StarsRefund)->count())->toBe(0);
});
