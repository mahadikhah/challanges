<?php

use App\Actions\Payments\RefundStarsPayment;
use App\Enums\CoinTransactionReason;
use App\Enums\PaymentProvider;
use App\Enums\SettingKey;
use App\Enums\StarPaymentStatus;
use App\Jobs\Telegram\ProcessTelegramUpdate;
use App\Models\CoinTransaction;
use App\Models\StarPayment;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\CoinLedger;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
 * The Bale Pay purchase flow end to end, the mirror of StarsPurchaseTest on
 * the second rail. The mechanics the bale-payments skill pins down all get
 * asserted on the wire rather than trusted from the code:
 *
 * - an invoice is *sent into the chat* (`sendInvoice`), priced in Rial, with
 *   the wallet `provider_token` — and no `currency` parameter at all;
 * - `pre_checkout_query` arrives with `IRR` and the Rial total, and is
 *   answered against the row's own price;
 * - `successful_payment` alone proves nothing: `inquireTransaction` is asked
 *   what actually became of the charge, and *its* status and amount are what
 *   get credited ("trust the inquiry, not the update");
 * - a duplicate delivery re-inquires nothing and credits nothing twice;
 * - there is no refund path, and the levers all say so rather than guess.
 *
 * Every Bale Pay call is faked; nothing here may reach tapi.bale.ai for real.
 */

beforeEach(function () {
    Http::preventStrayRequests();

    config([
        'services.bale.bot_token' => '620001:BALE-TEST-TOKEN',
        'services.bale.provider_token' => 'WALLET-TEST-1111111111111111',
    ]);

    $this->settings = app(Settings::class);
    $this->settings->set(SettingKey::RequiredChannelBale, '@balechannel');
    $this->settings->set(SettingKey::StarsPackages, [
        ['stars' => 50, 'coins' => 50, 'rial' => 50_000],
        ['stars' => 100, 'coins' => 110, 'rial' => 100_000],
    ]);

    $this->ledger = app(CoinLedger::class);
});

/**
 * Bale's answers around a purchase, with the transaction inquiry's status as
 * the one moving part.
 *
 * Registered once per test for the append-order reason the Stars file
 * documents: `Http::fake()` appends and the first matching pattern wins.
 */
function baleServesTheShop(string $membership = 'member', string $transactionStatus = 'paid', ?int $transactionAmount = 100_000): void
{
    Http::fake([
        '*getChatMember*' => Http::response(['ok' => true, 'result' => ['status' => $membership]]),
        '*answerCallbackQuery*' => Http::response(['ok' => true, 'result' => true]),
        '*sendMessage*' => Http::response(['ok' => true, 'result' => ['message_id' => 31]]),
        '*sendInvoice*' => Http::response(['ok' => true, 'result' => ['message_id' => 41]]),
        '*answerPreCheckoutQuery*' => Http::response(['ok' => true, 'result' => true]),
        '*inquireTransaction*' => Http::response(['ok' => true, 'result' => [
            'id' => 'charge-inquiry',
            'status' => $transactionStatus,
            'userID' => 888,
            'amount' => $transactionAmount,
        ]]),
    ]);
}

/**
 * The payer, whether or not an update has introduced them yet — a Bale user,
 * so every rail decision the flow makes picks Bale Pay.
 */
function theBalePayer(): User
{
    return User::query()->where('platform_user_id', 888)->first()
        ?? User::factory()->telegram(888)->bale()->preferring('en')->create();
}

/**
 * Put one message through the whole inbound path, on Bale.
 */
function asksBale(string $text = '/shop'): void
{
    $update = TelegramUpdate::factory()
        ->bale()
        ->messageFrom(['id' => 888, 'first_name' => 'Parisa', 'language_code' => 'en'], $text)
        ->create();

    dispatch_sync(new ProcessTelegramUpdate($update));
}

/**
 * Put one shop-button tap through the whole inbound path, on Bale.
 */
function tapsBalePackage(string $data): void
{
    $update = TelegramUpdate::factory()
        ->bale()
        ->callbackQueryFrom(['id' => 888, 'first_name' => 'Parisa', 'language_code' => 'en'], $data)
        ->create();

    dispatch_sync(new ProcessTelegramUpdate($update));
}

/**
 * Put a pre-checkout query through the whole inbound path, on Bale. IRR and
 * the Rial total are what the rail sends.
 */
function asksBalePreCheckout(string $invoicePayload, int $rial, int $fromBaleId = 888): void
{
    $update = TelegramUpdate::factory()
        ->bale()
        ->preCheckoutQuery($invoicePayload, $rial, $fromBaleId, 'IRR')
        ->create();

    dispatch_sync(new ProcessTelegramUpdate($update));
}

/**
 * Put a completed payment through the whole inbound path, on Bale. Every call
 * mints a fresh `update_id`, so a second call is a genuinely separate
 * delivery — the replay this file exists to hold.
 */
function paysTheBaleInvoice(string $invoicePayload, string $chargeId, int $rial, int $fromBaleId = 888): void
{
    $update = TelegramUpdate::factory()
        ->bale()
        ->successfulPaymentFrom(
            ['id' => $fromBaleId, 'first_name' => 'Parisa', 'language_code' => 'en'],
            $invoicePayload,
            $chargeId,
            $rial,
            'IRR',
        )
        ->create();

    dispatch_sync(new ProcessTelegramUpdate($update));
}

/**
 * The one `sendInvoice` the bot made, decoded.
 *
 * @return array<string, string>
 */
function theBaleInvoiceRequest(): array
{
    $calls = Http::recorded(
        fn (Request $request): bool => str_contains($request->url(), 'sendInvoice'),
    )->values();

    expect($calls)->toHaveCount(1);

    $sent = [];
    parse_str($calls[0][0]->body(), $sent);

    /** @var array<string, string> $sent */
    return $sent;
}

/**
 * The one `answerPreCheckoutQuery` the bot made, decoded.
 *
 * @return array<string, string>
 */
function theBalePreCheckoutAnswer(): array
{
    $calls = Http::recorded(
        fn (Request $request): bool => str_contains($request->url(), 'answerPreCheckoutQuery'),
    )->values();

    expect($calls)->toHaveCount(1);

    $sent = [];
    parse_str($calls[0][0]->body(), $sent);

    /** @var array<string, string> $sent */
    return $sent;
}

/**
 * How many `inquireTransaction` calls the bot made.
 */
function baleInquiryCount(): int
{
    return Http::recorded(
        fn (Request $request): bool => str_contains($request->url(), 'inquireTransaction'),
    )->count();
}

describe('a package tap', function () {
    it('records a Rial-priced purchase and sends the invoice into the payer’s chat', function () {
        baleServesTheShop();
        asksBale('/shop');
        tapsBalePackage('sp:1');

        $payment = StarPayment::query()->sole();

        expect($payment->provider)->toBe(PaymentProvider::BalePay)
            ->and($payment->status)->toBe(StarPaymentStatus::Pending)
            ->and($payment->stars_amount)->toBeNull()
            ->and($payment->rial_amount)->toBe(100_000)
            ->and($payment->coin_amount)->toBe(110)
            ->and($payment->telegram_payment_charge_id)->toBeNull();

        $request = theBaleInvoiceRequest();
        $prices = json_decode($request['prices'], true, 512, JSON_THROW_ON_ERROR);

        expect($request['chat_id'])->toBe('888')
            ->and($request['payload'])->toBe($payment->invoice_payload)

            // The wallet token from @botfather — a different secret from the
            // bot token the client carries.
            ->and($request['provider_token'])->toBe('WALLET-TEST-1111111111111111')

            // Rial, and no currency parameter at all: the rail has exactly one.
            ->and($request)->not->toHaveKey('currency')
            ->and($prices)->toBe([['label' => $request['title'], 'amount' => 100_000]])

            // The invoice message is the reply: no follow-up text lands in the
            // chat after it, because there is no link to hand back.
            ->and(botMessages())->toHaveCount(1);
    });

    it('refuses a package that carries no Rial price', function () {
        baleServesTheShop();
        $this->settings->set(SettingKey::StarsPackages, [
            ['stars' => 50, 'coins' => 50, 'rial' => 50_000],
            ['stars' => 100, 'coins' => 110],
        ]);
        asksBale('/shop');
        tapsBalePackage('sp:1');

        expect(latestBotMessage(2)['text'])->toBe(botCopy('bot.fallback.stale_button'))
            ->and(StarPayment::query()->count())->toBe(0);
    });
});

describe('pre-checkout', function () {
    it('approves a query whose IRR total matches the row’s own price', function () {
        baleServesTheShop();

        $payment = StarPayment::factory()
            ->for(theBalePayer())
            ->bale()
            ->buyingRial(110, 100_000)
            ->create();

        asksBalePreCheckout($payment->invoice_payload, 100_000);

        $answer = theBalePreCheckoutAnswer();

        expect($answer['ok'])->toBe('1')
            ->and($answer)->not->toHaveKey('error_message');
    });

    it('declines when the amount disagrees with the row, and the row survives', function () {
        baleServesTheShop();

        $payment = StarPayment::factory()
            ->for(theBalePayer())
            ->bale()
            ->buyingRial(110, 100_000)
            ->create();

        asksBalePreCheckout($payment->invoice_payload, 1);

        $answer = theBalePreCheckoutAnswer();

        expect($answer['ok'])->toBe('0')
            ->and($answer['error_message'])->toBe(botCopy('bot.shop.pre_checkout_error'))
            ->and($payment->refresh()->status)->toBe(StarPaymentStatus::Pending);
    });
});

describe('successful_payment', function () {
    it('credits from the inquiry’s answer, keyed on the Bale charge id', function () {
        baleServesTheShop(transactionAmount: 100_000);

        $payment = StarPayment::factory()
            ->for(theBalePayer())
            ->bale()
            ->buyingRial(110, 100_000)
            ->create();

        paysTheBaleInvoice($payment->invoice_payload, 'bale-charge-1', 100_000);

        $transaction = CoinTransaction::query()->sole();
        $paid = $payment->refresh();

        expect($paid)
            ->status->toBe(StarPaymentStatus::Paid)
            ->telegram_payment_charge_id->toBe('bale-charge-1')
            ->paid_at->not->toBeNull()
            ->and($this->ledger->balanceFor(theBalePayer()))->toBe(110)
            ->and($this->ledger->drift(theBalePayer()))->toBe(0)
            ->and($transaction->reason)->toBe(CoinTransactionReason::BalePayPurchase)
            ->and($transaction->amount)->toBe(110)
            ->and($transaction->idempotency_key)->toBe($paid->creditIdempotencyKey())
            ->and($transaction->idempotency_key)->toBe('bale_payment:credit:bale-charge-1')
            ->and($transaction->reference->is($paid))->toBeTrue()
            ->and(baleInquiryCount())->toBe(1)
            ->and(soleBotMessage()['text'])->toBe(botCopy('bot.shop.credited', [
                'coins' => 110,
                'balance' => 110,
            ]));
    });

    it('credits once however many times the same payment is delivered', function () {
        baleServesTheShop(transactionAmount: 100_000);

        $payment = StarPayment::factory()
            ->for(theBalePayer())
            ->bale()
            ->buyingRial(110, 100_000)
            ->create();

        paysTheBaleInvoice($payment->invoice_payload, 'bale-charge-replay', 100_000);
        paysTheBaleInvoice($payment->invoice_payload, 'bale-charge-replay', 100_000);
        paysTheBaleInvoice($payment->invoice_payload, 'bale-charge-replay', 100_000);

        // The first delivery inquires and credits; the replays find the row
        // already paid, re-ask nothing about a spent transaction, and credit
        // nothing again — three layers: the locked row, the status transition,
        // the charge-id-derived idempotency key.
        expect(CoinTransaction::query()->count())->toBe(1)
            ->and($this->ledger->balanceFor(theBalePayer()))->toBe(110)
            ->and($this->ledger->drift(theBalePayer()))->toBe(0)
            ->and(baleInquiryCount())->toBe(1);
    });

    it('marks the row failed and credits nothing when the inquiry says failed', function () {
        baleServesTheShop(transactionStatus: 'failed', transactionAmount: 100_000);

        $payment = StarPayment::factory()
            ->for(theBalePayer())
            ->bale()
            ->buyingRial(110, 100_000)
            ->create();

        paysTheBaleInvoice($payment->invoice_payload, 'bale-charge-failed', 100_000);

        expect($payment->refresh()->status)->toBe(StarPaymentStatus::Failed)
            ->and(CoinTransaction::query()->count())->toBe(0)
            ->and(soleBotMessage()['text'])->toBe(botCopy('bot.shop.not_credited'));
    });

    it('marks the row failed and credits nothing when the inquiry says rejected', function () {
        baleServesTheShop(transactionStatus: 'rejected', transactionAmount: 100_000);

        $payment = StarPayment::factory()
            ->for(theBalePayer())
            ->bale()
            ->buyingRial(110, 100_000)
            ->create();

        paysTheBaleInvoice($payment->invoice_payload, 'bale-charge-rejected', 100_000);

        expect($payment->refresh()->status)->toBe(StarPaymentStatus::Failed)
            ->and(CoinTransaction::query()->count())->toBe(0);
    });

    it('keeps the row pending and tells the payer to wait when the inquiry says pending', function () {
        baleServesTheShop(transactionStatus: 'pending', transactionAmount: 100_000);

        $payment = StarPayment::factory()
            ->for(theBalePayer())
            ->bale()
            ->buyingRial(110, 100_000)
            ->create();

        paysTheBaleInvoice($payment->invoice_payload, 'bale-charge-pending', 100_000);

        // Money may still land; never credit short of "paid", and never tell
        // the payer their money failed to match when it merely has not settled.
        expect($payment->refresh()->status)->toBe(StarPaymentStatus::Pending)
            ->and(CoinTransaction::query()->count())->toBe(0)
            ->and(soleBotMessage()['text'])->toBe(botCopy('bot.shop.pending_confirmation'))
            ->and(baleInquiryCount())->toBe(1);
    });

    it('marks the row failed and credits nothing when the inquiry’s amount disagrees', function () {
        baleServesTheShop(transactionAmount: 1);

        $payment = StarPayment::factory()
            ->for(theBalePayer())
            ->bale()
            ->buyingRial(110, 100_000)
            ->create();

        paysTheBaleInvoice($payment->invoice_payload, 'bale-charge-wrong', 100_000);

        expect($payment->refresh()->status)->toBe(StarPaymentStatus::Failed)
            ->and(CoinTransaction::query()->count())->toBe(0)
            ->and(soleBotMessage()['text'])->toBe(botCopy('bot.shop.not_credited'));
    });

    it('does not let one payer settle somebody else’s invoice', function () {
        baleServesTheShop(transactionAmount: 100_000);
        theBalePayer();

        $other = User::factory()->telegram(999_000_1)->bale()->create();
        $payment = StarPayment::factory()
            ->for($other)
            ->bale()
            ->buyingRial(110, 100_000)
            ->create();

        paysTheBaleInvoice($payment->invoice_payload, 'bale-charge-crossed', 100_000);

        // The invoice is scoped to whoever it was issued to, and the inquiry
        // about the crossed charge was never even worth asking.
        expect($payment->refresh()->status)->toBe(StarPaymentStatus::Pending)
            ->and(CoinTransaction::query()->count())->toBe(0)
            ->and(baleInquiryCount())->toBe(0)
            ->and(soleBotMessage()['text'])->toBe(botCopy('bot.shop.not_credited'));
    });
});

describe('refunds', function () {
    it('offers no refund lever on a Bale purchase, on any layer', function () {
        $paid = StarPayment::factory()
            ->for(theBalePayer())
            ->bale()
            ->buyingRial(110, 100_000)
            ->paid('bale-charge-1')
            ->create();

        // The bale-payments skill documents no refund endpoint on Bale's rail,
        // so every layer refuses: the row hides the lever, and the platform
        // says what the rail never offered.
        expect($paid->isRefundable())->toBeFalse()
            ->and(fn () => app(RefundStarsPayment::class)->handle($paid))
            ->toThrow(LogicException::class);
    });
});
