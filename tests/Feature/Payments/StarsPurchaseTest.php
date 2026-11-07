<?php

use App\Actions\Payments\CreateStarsInvoice;
use App\Enums\CoinTransactionReason;
use App\Enums\EntitlementType;
use App\Enums\SettingKey;
use App\Enums\StarPaymentStatus;
use App\Jobs\Telegram\ProcessTelegramUpdate;
use App\Models\CoinTransaction;
use App\Models\Entitlement;
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
 * The Stars purchase flow end to end: `/shop` offers the admin-tuned packages,
 * a tap records a Pending `StarPayment` and fetches Telegram's invoice link,
 * `pre_checkout_query` is answered against the row's own price, and
 * `successful_payment` credits coins through the ledger keyed on
 * `telegram_payment_charge_id`.
 *
 * This file owns the §6 verification of `prompts/main.md`: replay a
 * `successful_payment` carrying a duplicate charge id and assert exactly one
 * credit. Driven through `ProcessTelegramUpdate` and the real routers, because
 * a payments bug that only shows when the pieces are wired together is the kind
 * this platform cannot afford.
 */

const TEST_INVOICE_LINK = 'https://t.me/invoice/test-invoice-link';

beforeEach(function () {
    Http::preventStrayRequests();

    config(['services.telegram.bot_token' => '123456:TEST-TOKEN']);

    $this->settings = app(Settings::class);
    $this->settings->set(SettingKey::RequiredChannel, '@challenges');

    $this->ledger = app(CoinLedger::class);
});

/**
 * Telegram's answers around a purchase: a membership status for the gate, an
 * acknowledged tap, an accepted message, the invoice link itself, and a
 * pre-checkout answer.
 *
 * Registered once per test rather than in `beforeEach` because `Http::fake()`
 * appends and the first matching pattern wins — a test that needs the gate to
 * refuse must be the one to register the refusals, or the friendly stub
 * registered first would keep answering.
 */
function telegramServesTheShop(string $membership = 'member'): void
{
    Http::fake([
        '*getChatMember*' => Http::response(['ok' => true, 'result' => ['status' => $membership]]),
        '*answerCallbackQuery*' => Http::response(['ok' => true, 'result' => true]),
        '*sendMessage*' => Http::response(['ok' => true, 'result' => ['message_id' => 11]]),
        '*createInvoiceLink*' => Http::response(['ok' => true, 'result' => TEST_INVOICE_LINK]),
        '*answerPreCheckoutQuery*' => Http::response(['ok' => true, 'result' => true]),
    ]);
}

/**
 * The payer, whether or not an update has introduced them yet.
 */
function thePayer(): User
{
    return User::query()->where('platform_user_id', 777_000_3)->first()
        ?? User::factory()->telegram(777_000_3)->preferring('en')->create();
}

/**
 * Put one message through the whole inbound path.
 */
function asksTheShop(string $text = '/shop'): void
{
    $update = TelegramUpdate::factory()
        ->messageFrom(['id' => 777_000_3, 'first_name' => 'Sara', 'language_code' => 'en'], $text)
        ->create();

    dispatch_sync(new ProcessTelegramUpdate($update));
}

/**
 * The slot rows `/shop` always offers, in `EntitlementType` order.
 *
 * Built from the same `priceOf()` the purchase charges and the same callback
 * the refusal path sends, so this cannot pin a price the product no longer
 * sells at — and cannot pass if the shop grew a second purchase path.
 *
 * @return list<list<array{text: string, callback_data: string}>>
 */
function slotRows(): array
{
    return array_map(
        static fn (EntitlementType $type): array => [slotButton('en', $type)],
        EntitlementType::cases(),
    );
}

/**
 * Put one shop-button tap through the whole inbound path.
 *
 * The shop sends two kinds of button — a package index (`sp:`) and a slot
 * (`bs:`) — and they travel the same inbound path, so this helper is the same
 * one for both.
 */
function tapsShopButton(string $data): void
{
    $update = TelegramUpdate::factory()
        ->callbackQueryFrom(['id' => 777_000_3, 'first_name' => 'Sara', 'language_code' => 'en'], $data)
        ->create();

    dispatch_sync(new ProcessTelegramUpdate($update));
}

/**
 * The one `createInvoiceLink` the bot made, decoded.
 *
 * @return array<string, string>
 */
function theInvoiceRequest(): array
{
    $calls = Http::recorded(
        fn (Request $request): bool => str_contains($request->url(), 'createInvoiceLink'),
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
function thePreCheckoutAnswer(): array
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
 * Put a pre-checkout query through the whole inbound path.
 */
function asksPreCheckout(string $invoicePayload, int $stars, int $fromTelegramId = 777_000_3): void
{
    $update = TelegramUpdate::factory()
        ->preCheckoutQuery($invoicePayload, $stars, $fromTelegramId)
        ->create();

    dispatch_sync(new ProcessTelegramUpdate($update));
}

/**
 * Put a completed payment through the whole inbound path. Every call mints a
 * fresh `update_id`, so a second call is a genuinely separate delivery — the
 * replay §6 asks for, not one Telegram's own retry would have deduplicated.
 */
function paysTheInvoice(string $invoicePayload, string $chargeId, int $stars, int $fromTelegramId = 777_000_3): void
{
    $update = TelegramUpdate::factory()
        ->successfulPaymentFrom(
            ['id' => $fromTelegramId, 'first_name' => 'Sara', 'language_code' => 'en'],
            $invoicePayload,
            $chargeId,
            $stars,
        )
        ->create();

    dispatch_sync(new ProcessTelegramUpdate($update));
}

describe('/shop', function () {
    it('offers every configured package under its own button', function () {
        telegramServesTheShop();
        asksTheShop();

        $packages = $this->settings->array(SettingKey::StarsPackages);

        $reply = soleBotMessage();

        expect($reply['text'])->toContain(botCopy('bot.shop.prompt'))
            ->and($reply['text'])->toContain(botCopy('bot.shop.package', ['stars' => 50, 'coins' => 50]))
            ->and($reply['text'])->toContain(botCopy('bot.shop.package', ['stars' => 500, 'coins' => 620]))
            ->and(keyboardOn($reply))->toBe([...array_map(
                fn (int $index): array => [[
                    'text' => botCopy('bot.shop.button', ['coins' => $packages[$index]['coins']]),
                    'callback_data' => 'sp:'.$index,
                ]],
                array_keys($packages),
            ), ...slotRows()]);
    });

    it('prices the shelves from the setting, not from this file', function () {
        telegramServesTheShop();
        $this->settings->set(SettingKey::StarsPackages, [['stars' => 75, 'coins' => 80]]);

        asksTheShop();

        expect(soleBotMessage()['text'])->toContain(botCopy('bot.shop.package', ['stars' => 75, 'coins' => 80]))
            ->and(keyboardOn(soleBotMessage()))->toBe([[[
                'text' => botCopy('bot.shop.button', ['coins' => 80]),
                'callback_data' => 'sp:0',
            ]], ...slotRows()]);
    });

    it('says so rather than show empty shelves when no packages are configured', function () {
        telegramServesTheShop();
        $this->settings->set(SettingKey::StarsPackages, []);

        asksTheShop();

        // A line in the message, not the whole message. The slot section below
        // it is priced in coins the user already holds, so it is on offer
        // whether or not anything can be topped up — and that is the only
        // reason a Telegram user with no packages configured still has
        // something to do here.
        expect(soleBotMessage()['text'])->toContain(botCopy('bot.shop.no_packages'))
            ->and(keyboardOn(soleBotMessage()))->toBe(slotRows());
    });

    it('sells slots for coins the user already holds', function () {
        telegramServesTheShop();

        asksTheShop();

        $message = soleBotMessage();

        expect($message['text'])
            ->toContain(botCopy('bot.shop.slot.create_slot', [
                'coins' => $this->settings->integer(SettingKey::CreateSlotCoinPrice),
            ]))
            ->and($message['text'])
            ->toContain(botCopy('bot.shop.slot.join_slot', [
                'coins' => $this->settings->integer(SettingKey::JoinSlotCoinPrice),
            ]))
            // Packages first, then slots. A user who came to buy coins should
            // not have to read past two slot rows to find the top-ups.
            ->and(array_slice(keyboardOn($message), -2))->toBe(slotRows());
    });

    it('sells the slot its own button names, through the one purchase path', function () {
        telegramServesTheShop();

        asksTheShop();

        $payer = thePayer();
        $this->ledger->credit($payer, 100, CoinTransactionReason::AdminCredit, 'shop:funds');

        tapsShopButton(slotButton('en', EntitlementType::CreateSlot)['callback_data']);

        // The claim the shop's slot section rests on: it did not grow a second
        // purchase path, it grew a second place to send the first one's
        // button. If a `bs:` button were ever handled twice, or priced twice,
        // this is the test that stops agreeing.
        expect(Entitlement::query()
            ->where('user_id', $payer->getKey())
            ->where('type', EntitlementType::CreateSlot)
            ->count())->toBe(1)
            ->and($this->ledger->balanceFor($payer))
            ->toBe(100 - $this->settings->integer(SettingKey::CreateSlotCoinPrice))
            ->and(lastBotReply()['text'])->toBe(botCopy('bot.slots.bought'));
    });

    it('blocks a user who has not joined the channel', function () {
        telegramServesTheShop('left');
        asksTheShop();

        expect(soleBotMessage()['text'])->toContain(botCopy('bot.gate.blocked', ['channel' => '@challenges']))
            ->and(StarPayment::query()->count())->toBe(0);
    });
});

describe('a package tap', function () {
    it('records the purchase and answers with Telegram’s invoice link', function () {
        telegramServesTheShop();
        asksTheShop();
        tapsShopButton('sp:1');

        $payment = StarPayment::query()->sole();

        $request = theInvoiceRequest();
        $prices = json_decode($request['prices'], true, 512, JSON_THROW_ON_ERROR);

        expect($payment->status)->toBe(StarPaymentStatus::Pending)
            ->and($payment->stars_amount)->toBe(100)
            ->and($payment->coin_amount)->toBe(110)
            ->and($payment->telegram_payment_charge_id)->toBeNull()
            ->and($request['payload'])->toBe($payment->invoice_payload)

            // The three facts CLAUDE.md pins down about Stars invoices, checked
            // on the wire rather than trusted from the code.
            ->and($request['currency'])->toBe('XTR')
            ->and($request['provider_token'])->toBe('')
            ->and($prices)->toBe([['label' => $request['title'], 'amount' => 100]])
            ->and(keyboardOn(latestBotMessage(2)))->toBe([[[
                'text' => botCopy('bot.shop.pay_button', ['stars' => 100]),
                'url' => TEST_INVOICE_LINK,
            ]]]);
    });

    it('prices the invoice from the setting at tap time, not at list time', function () {
        telegramServesTheShop();
        asksTheShop();

        // An admin reprices between the listing and the tap: the row and the
        // invoice must carry the new price, or the shop would sell stale ones.
        $this->settings->set(SettingKey::StarsPackages, [
            ['stars' => 50, 'coins' => 50],
            ['stars' => 100, 'coins' => 200],
        ]);

        tapsShopButton('sp:1');

        expect(StarPayment::query()->sole()->coin_amount)->toBe(200)
            ->and(latestBotMessage(2)['text'])->toBe(botCopy('bot.shop.pay_prompt', [
                'stars' => 100,
                'coins' => 200,
            ]));

        // And the title — which is what Telegram shows in the payment sheet —
        // names the new amount.
        expect(json_decode(theInvoiceRequest()['prices'], true, 512, JSON_THROW_ON_ERROR)[0]['label'])
            ->toBe(botCopy('bot.shop.invoice_title', ['coins' => 200]));
    });

    it('refuses a package that no longer exists', function () {
        telegramServesTheShop();
        asksTheShop();

        tapsShopButton('sp:99');

        expect(latestBotMessage(2)['text'])->toBe(botCopy('bot.fallback.stale_button'))
            ->and(StarPayment::query()->count())->toBe(0);
    });

    it('refuses a button carrying something other than an index', function () {
        telegramServesTheShop();
        asksTheShop();

        tapsShopButton('sp:free');

        expect(latestBotMessage(2)['text'])->toBe(botCopy('bot.fallback.stale_button'))
            ->and(StarPayment::query()->count())->toBe(0);
    });
});

describe('pre-checkout', function () {
    it('approves a query that matches the pending invoice', function () {
        telegramServesTheShop();

        $payment = StarPayment::factory()
            ->for(thePayer())
            ->buying(110, 100)
            ->create();

        asksPreCheckout($payment->invoice_payload, 100);

        $answer = thePreCheckoutAnswer();

        expect($answer['ok'])->toBe('1')
            ->and($answer)->not->toHaveKey('error_message')
            ->and($answer['pre_checkout_query_id'])->toBe(
                (string) TelegramUpdate::query()->latest('id')->sole()->value('pre_checkout_query.id'),
            );
    });

    it('declines and explains when the amount disagrees with the invoice', function () {
        telegramServesTheShop();

        $payment = StarPayment::factory()
            ->for(thePayer())
            ->buying(110, 100)
            ->create();

        asksPreCheckout($payment->invoice_payload, 1);

        $answer = thePreCheckoutAnswer();

        expect($answer['ok'])->toBe('0')
            ->and($answer['error_message'])->toBe(botCopy('bot.shop.pre_checkout_error'))

            // A decline is not a judgement on the invoice: the row stays
            // pending, because the query may be stale or malformed rather than
            // the link being dead.
            ->and($payment->refresh()->status)->toBe(StarPaymentStatus::Pending);
    });

    it('declines a payload learned from somebody else’s link', function () {
        telegramServesTheShop();

        $other = User::factory()->telegram(888_000_9)->create();
        $payment = StarPayment::factory()->for($other)->buying(110, 100)->create();

        asksPreCheckout($payment->invoice_payload, 100);

        expect(thePreCheckoutAnswer()['ok'])->toBe('0')
            ->and($payment->refresh()->status)->toBe(StarPaymentStatus::Pending);
    });

    it('declines an invoice nobody ever issued', function () {
        telegramServesTheShop();

        asksPreCheckout('coins:no-such-invoice', 100);

        expect(thePreCheckoutAnswer()['ok'])->toBe('0');
    });
});

describe('successful_payment', function () {
    it('credits the coins and confirms the new balance', function () {
        telegramServesTheShop();

        $payment = StarPayment::factory()
            ->for(thePayer())
            ->buying(110, 100)
            ->create();

        paysTheInvoice($payment->invoice_payload, 'charge-1', 100);

        $transaction = CoinTransaction::query()->sole();
        $paid = $payment->refresh();

        expect($paid)
            ->status->toBe(StarPaymentStatus::Paid)
            ->telegram_payment_charge_id->toBe('charge-1')
            ->paid_at->not->toBeNull()
            ->and($this->ledger->balanceFor(thePayer()))->toBe(110)
            ->and($transaction->reason)->toBe(CoinTransactionReason::StarsPurchase)
            ->and($transaction->amount)->toBe(110)
            ->and($transaction->idempotency_key)->toBe($paid->creditIdempotencyKey())
            ->and($transaction->reference->is($paid))->toBeTrue()
            ->and(soleBotMessage()['text'])->toBe(botCopy('bot.shop.credited', [
                'coins' => 110,
                'balance' => 110,
            ]));
    });

    it('credits once however many times the same payment is delivered', function () {
        telegramServesTheShop();

        // The §6 check. Three separate deliveries — different update ids, so
        // the webhook's own dedup never sees the second or third — carrying
        // the same charge id for the same invoice.
        $payment = StarPayment::factory()
            ->for(thePayer())
            ->buying(110, 100)
            ->create();

        paysTheInvoice($payment->invoice_payload, 'charge-replay', 100);
        paysTheInvoice($payment->invoice_payload, 'charge-replay', 100);
        paysTheInvoice($payment->invoice_payload, 'charge-replay', 100);

        expect(CoinTransaction::query()->count())->toBe(1)
            ->and($this->ledger->balanceFor(thePayer()))->toBe(110)
            ->and($this->ledger->drift(thePayer()))->toBe(0);
    });

    it('marks the invoice failed and credits nothing when the amounts disagree', function () {
        telegramServesTheShop();

        $payment = StarPayment::factory()
            ->for(thePayer())
            ->buying(110, 100)
            ->create();

        paysTheInvoice($payment->invoice_payload, 'charge-wrong', 1);

        expect($payment->refresh()->status)->toBe(StarPaymentStatus::Failed)
            ->and(CoinTransaction::query()->count())->toBe(0)
            ->and(soleBotMessage()['text'])->toBe(botCopy('bot.shop.not_credited'));
    });

    it('says so when the payment names no invoice of the payer’s', function () {
        telegramServesTheShop();
        thePayer();

        paysTheInvoice('coins:no-such-invoice', 'charge-unknown', 100);

        expect(StarPayment::query()->count())->toBe(0)
            ->and(CoinTransaction::query()->count())->toBe(0)
            ->and(soleBotMessage()['text'])->toBe(botCopy('bot.shop.not_credited'));
    });

    it('does not let one payer settle somebody else’s invoice', function () {
        telegramServesTheShop();
        thePayer();

        $other = User::factory()->telegram(888_000_9)->create();
        $payment = StarPayment::factory()->for($other)->buying(110, 100)->create();

        paysTheInvoice($payment->invoice_payload, 'charge-crossed', 100);

        // The invoice is scoped to whoever it was issued to, not to whoever's
        // update names it.
        expect($payment->refresh()->status)->toBe(StarPaymentStatus::Pending)
            ->and(CoinTransaction::query()->count())->toBe(0);
    });
});

describe('the action behind the flow', function () {
    it('words the invoice itself in the payer’s own language', function () {
        telegramServesTheShop();

        app(CreateStarsInvoice::class)->handle(thePayer(), 0, 'fa');

        $request = theInvoiceRequest();

        expect($request['title'])->toBe(botCopy('bot.shop.invoice_title', ['coins' => 50], 'fa'))
            ->and($request['description'])->toBe(botCopy('bot.shop.invoice_description', [
                'app' => botCopy('common.app_name', [], 'fa'),
            ], 'fa'));
    });
});
