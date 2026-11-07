<?php

use App\Actions\Challenges\MaterialiseChallengePeriods;
use App\Actions\Entitlements\ConsumeEntitlement;
use App\Enums\CoinTransactionReason;
use App\Enums\EntitlementSource;
use App\Enums\EntitlementType;
use App\Enums\SettingKey;
use App\Jobs\Telegram\ProcessTelegramUpdate;
use App\Models\Challenge;
use App\Models\CoinTransaction;
use App\Models\Entitlement;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\CoinLedger;
use App\Services\Settings;
use App\Services\Telegram\BotCallback;
use App\Services\Telegram\Callbacks\SlotPurchaseCallback;
use App\Services\Telegram\JoinChallengeFlow;
use App\Services\Telegram\SlotRefusal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

/*
 * Buying a slot, end to end — and this file is the acceptance test for a bug that
 * made the economy a closed loop.
 *
 * `PurchaseEntitlement` was written, tested, and had no production caller: both
 * refusal paths quoted a price with no keyboard under it, so a user who had spent
 * their free slot could hold a thousand coins and still never create a second
 * challenge. The refusal is an *entitlement count* check, not a balance check, so
 * no amount of coins could change it. `SlotPurchaseCallback` and the button in
 * `SlotRefusal` are the missing half, and everything here proves the two halves
 * meet.
 *
 * Two things this file is careful about, because both are places a test can pass
 * for the wrong reason:
 *
 * 1. **A replay is not a double tap.** Telegram issues a fresh `update_id` per
 *    tap, so two taps are two deliveries with two keys and the ledger would
 *    charge for both. What stops a double tap is the slot left in hand by the
 *    first one, not the idempotency key. The replay tests therefore *consume the
 *    slot between the two invocations* — otherwise the guard answers first and
 *    the key is never exercised.
 * 2. **The key is per delivery, not per user.** `update_id` is unique per
 *    platform, so the platform is part of it; the two-rail test is the one that
 *    would catch it going missing.
 *
 * Every request is faked. Nothing here may reach api.telegram.org or tapi.bale.ai.
 */

beforeEach(function () {
    Http::preventStrayRequests();

    config([
        'services.telegram.bot_token' => '123456:TEST-TOKEN',
        'services.telegram.bot_username' => 'ChallengesBot',
        'services.bale.bot_token' => '620001:BALE-TEST-TOKEN',
        'services.bale.bot_username' => 'ChallengesBaleBot',
    ]);

    $this->settings = app(Settings::class);
    $this->settings->set(SettingKey::RequiredChannel, '@challenges');
    $this->settings->set(SettingKey::RequiredChannelBale, '@balechannel');

    $this->ledger = app(CoinLedger::class);
});

/**
 * The messenger answering normally. One `fake()` call so pattern order cannot
 * matter — the patterns below are method-name wildcards, so they cover whichever
 * host the send is going to, and what a test asserts afterwards is *which* host
 * was asked.
 */
function messengerAnswers(): void
{
    Http::fake([
        '*getChatMember*' => Http::response(['ok' => true, 'result' => ['status' => 'member']]),
        '*answerCallbackQuery*' => Http::response(['ok' => true, 'result' => true]),
        '*sendMessage*' => Http::response(['ok' => true, 'result' => ['message_id' => 11]]),
    ]);
}

/**
 * A bot user who reads English, so every assertion can be made against one
 * locale.
 *
 * `locale` is pinned rather than left to the factory's random `language_code`:
 * `ResolveTelegramUser` refreshes `language_code` from every update it sees and
 * never writes `locale`, so a chosen one is the only stable answer here.
 */
function slotBuyer(int $platformUserId, int $coins = 0, bool $bale = false): User
{
    $factory = User::factory()->telegram($platformUserId)->preferring('en');

    if ($bale) {
        $factory = $factory->bale();
    }

    $user = $factory->create();

    if ($coins > 0) {
        app(CoinLedger::class)->credit($user, $coins, CoinTransactionReason::AdminCredit, "float:{$user->getKey()}");
    }

    return $user;
}

/**
 * One tap arriving the way Telegram delivers it, through the whole inbound path.
 *
 * @param  array<string, mixed>  $from  merged over Telegram's `from` object
 */
function tapBuysSlot(string $data, array $from = [], ?int $updateId = null, bool $bale = false): TelegramUpdate
{
    $factory = TelegramUpdate::factory()->callbackQueryFrom(
        array_replace(['id' => 777_600_1, 'first_name' => 'Sara'], $from),
        $data,
    );

    if ($bale) {
        $factory = $factory->bale();
    }

    if ($updateId !== null) {
        $factory = $factory->withUpdateId($updateId);
    }

    $update = $factory->create();

    dispatch_sync(new ProcessTelegramUpdate($update));

    return $update;
}

/**
 * Deliver the same update a second time, the way the queue does after a handler
 * charged and then failed on the outgoing message.
 *
 * `ProcessTelegramUpdate` stamps `processed_at` only once the work succeeds, so an
 * update whose reply never landed is left unprocessed and redelivered verbatim —
 * same id, same payload, same button. Clearing the stamp is what makes that
 * redelivery reachable in a test.
 */
function redeliver(TelegramUpdate $update): void
{
    $update->forceFill(['processed_at' => null])->save();

    dispatch_sync(new ProcessTelegramUpdate($update->fresh()));
}

/**
 * What the bot charged for this slot type, as ledger entries.
 */
function slotPurchases(User $user, EntitlementType $type = EntitlementType::CreateSlot): int
{
    return CoinTransaction::query()
        ->where('user_id', $user->getKey())
        ->where('reason', $type->purchaseReason())
        ->count();
}

describe('the refusal that used to be permanent', function () {
    it('sells the slot a refusal button offered', function () {
        messengerAnswers();
        $this->settings->set(SettingKey::CreateSlotCoinPrice, 30);

        // Exactly the price, and nothing in hand: the state the reported bug left
        // every user in after their one free challenge.
        $user = slotBuyer(777_600_1, coins: 30);

        tapBuysSlot(BotCallback::encode(SlotPurchaseCallback::ACTION, EntitlementType::CreateSlot->value), [
            'id' => 777_600_1,
        ]);

        $slot = Entitlement::query()->where('user_id', $user->getKey())->sole();

        expect($slot->type)->toBe(EntitlementType::CreateSlot)
            ->and($slot->source)->toBe(EntitlementSource::CoinPurchase)
            ->and($slot->consumed_at)->toBeNull()
            ->and($this->ledger->balanceFor($user))->toBe(0)
            ->and(soleBotMessage()['text'])->toBe(botCopy('bot.slots.bought'))
            // Which is the whole point of the bug: the next thing they wanted to do
            // was create a challenge, and now they can.
            ->and(botKeyboard())->toBe([[commandButton('en', 'create')]]);
    });

    it('takes them back to the join they were refused, not to a dead end', function () {
        messengerAnswers();
        $this->settings->set(SettingKey::JoinSlotCoinPrice, 25);

        $challenge = Challenge::factory()->active()->create([
            'join_token' => 'abcdefghjkmn',
            'title' => 'Read every day',
            'total_periods' => 30,
        ]);
        app(MaterialiseChallengePeriods::class)->handle($challenge);

        $user = slotBuyer(777_600_2, coins: 25);

        // The refusal, exactly as the join flow builds it: no free baseline was
        // ever granted, so nothing is on the card and the join cannot be paid for.
        app(JoinChallengeFlow::class)->confirm($user, $challenge->join_token);

        $offered = keyboardOn(soleBotMessage())[0][0];

        expect($offered)->toBe(slotButton('en', EntitlementType::JoinSlot, $challenge->join_token));

        // The tap on the button the bot actually sent, rather than one rebuilt here.
        tapBuysSlot($offered['callback_data'], ['id' => 777_600_2]);

        expect(lastBotReply()['text'])->toBe(botCopy('bot.slots.bought'))
            // A genuine resume: `confirm()` re-resolves the challenge from the
            // token and re-runs its own gate and slot checks, so this button is
            // the same one the preview sends.
            ->and(keyboardOn(lastBotReply()))->toBe([[
                [
                    'text' => botCopy('bot.join.join_button'),
                    'callback_data' => BotCallback::encode('jn', $challenge->join_token),
                ],
            ]]);
    });

    it('charges the price at the tap, not the one the button was labelled with', function () {
        messengerAnswers();
        $this->settings->set(SettingKey::CreateSlotCoinPrice, 25);

        $user = slotBuyer(777_600_3, coins: 40);

        app(SlotRefusal::class)->send($user, EntitlementType::CreateSlot);

        $offered = keyboardOn(soleBotMessage())[0][0];

        expect($offered)->toBe(slotButton('en', EntitlementType::CreateSlot));

        // The keyboard sat in the chat for a week and an admin moved the price. The
        // label is a sentence sent long ago; the charge has to be today's number.
        $this->settings->set(SettingKey::CreateSlotCoinPrice, 40);

        tapBuysSlot($offered['callback_data'], ['id' => 777_600_3]);

        expect($this->ledger->balanceFor($user))->toBe(0)
            ->and(lastBotReply()['text'])->toBe(botCopy('bot.slots.bought'));
    });
});

describe('paying once', function () {
    it('replays a redelivered tap instead of charging again', function () {
        messengerAnswers();
        $this->settings->set(SettingKey::CreateSlotCoinPrice, 30);

        $user = slotBuyer(777_600_4, coins: 60);
        $data = BotCallback::encode(SlotPurchaseCallback::ACTION, EntitlementType::CreateSlot->value);

        $update = tapBuysSlot($data, ['id' => 777_600_4], updateId: 4_100_001);

        expect($this->ledger->balanceFor($user))->toBe(30);

        // The slot is spent before the redelivery lands. Without this the guard
        // below answers first — "you still have a slot left" — and the test would
        // pass without the idempotency key ever being consulted.
        app(ConsumeEntitlement::class)->handle($user, EntitlementType::CreateSlot, Challenge::factory()->create());

        redeliver($update);

        expect($this->ledger->balanceFor($user))->toBe(30)
            ->and(slotPurchases($user))->toBe(1)
            ->and(Entitlement::query()->where('user_id', $user->getKey())->count())->toBe(1);
    });

    it('charges again for a tap that is genuinely a second purchase', function () {
        messengerAnswers();
        $this->settings->set(SettingKey::CreateSlotCoinPrice, 30);

        $user = slotBuyer(777_600_5, coins: 60);
        $data = BotCallback::encode(SlotPurchaseCallback::ACTION, EntitlementType::CreateSlot->value);

        tapBuysSlot($data, ['id' => 777_600_5], updateId: 4_100_002);

        // Same slot spent, then a fresh tap rather than a redelivery. A key that
        // ignored the update id — or the whole "key" idea — would refuse this and
        // silently sell a slot the user asked for and could pay for.
        app(ConsumeEntitlement::class)->handle($user, EntitlementType::CreateSlot, Challenge::factory()->create());

        tapBuysSlot($data, ['id' => 777_600_5], updateId: 4_100_003);

        expect($this->ledger->balanceFor($user))->toBe(0)
            ->and(slotPurchases($user))->toBe(2)
            ->and(Entitlement::query()->where('user_id', $user->getKey())->count())->toBe(2);
    });

    it('keeps two rails with the same update id apart', function () {
        messengerAnswers();
        $this->settings->set(SettingKey::CreateSlotCoinPrice, 30);

        // `update_id` is unique per platform, not globally: Telegram and Bale
        // number their updates independently and these two collide on purpose. A
        // key without the platform segment would have the Bale buyer replay the
        // Telegram buyer's delivery and pay nothing at all.
        $sharedId = 4_100_004;
        $telegramUser = slotBuyer(777_600_6);
        $baleUser = slotBuyer(777_600_7, bale: true);

        $this->ledger->credit($telegramUser, 30, CoinTransactionReason::AdminCredit, 'float:tg');
        $this->ledger->credit($baleUser, 30, CoinTransactionReason::AdminCredit, 'float:bale');

        $data = BotCallback::encode(SlotPurchaseCallback::ACTION, EntitlementType::CreateSlot->value);

        tapBuysSlot($data, ['id' => 777_600_6], updateId: $sharedId);
        tapBuysSlot($data, ['id' => 777_600_7], updateId: $sharedId, bale: true);

        expect($this->ledger->balanceFor($telegramUser))->toBe(0)
            ->and($this->ledger->balanceFor($baleUser))->toBe(0)
            ->and(slotPurchases($telegramUser))->toBe(1)
            ->and(slotPurchases($baleUser))->toBe(1);
    });

    it('does not charge for the second half of a double tap', function () {
        messengerAnswers();
        $this->settings->set(SettingKey::CreateSlotCoinPrice, 30);

        // Enough for two, so a missing guard shows up as a spent balance rather
        // than as an exception.
        $user = slotBuyer(777_600_8, coins: 60);
        $data = BotCallback::encode(SlotPurchaseCallback::ACTION, EntitlementType::CreateSlot->value);

        tapBuysSlot($data, ['id' => 777_600_8], updateId: 4_100_005);
        tapBuysSlot($data, ['id' => 777_600_8], updateId: 4_100_006);

        // Telegram gives each tap its own update id, so the idempotency key does
        // not see these as one purchase and cannot stop the second. What stops it
        // is the slot the first one left in hand.
        expect($this->ledger->balanceFor($user))->toBe(30)
            ->and(slotPurchases($user))->toBe(1)
            ->and(lastBotReply()['text'])->toBe(botCopy('bot.slots.already_have'))
            ->and(lastBotKeyboard())->toBe([[commandButton('en', 'create')]]);
    });
});

describe('what a purchase refuses', function () {
    it('offers the shop when the balance will not cover it, and sells nothing', function () {
        messengerAnswers();
        $this->settings->set(SettingKey::CreateSlotCoinPrice, 30);

        $user = slotBuyer(777_600_9, coins: 10);

        tapBuysSlot(
            BotCallback::encode(SlotPurchaseCallback::ACTION, EntitlementType::CreateSlot->value),
            ['id' => 777_600_9],
        );

        expect(Entitlement::query()->where('user_id', $user->getKey())->count())->toBe(0)
            ->and($this->ledger->balanceFor($user))->toBe(10)
            // The shortfall is the useful half: "you cannot afford it" without a
            // number is a wall, and the number is what makes the shop worth opening.
            ->and(soleBotMessage()['text'])->toBe(botCopy('bot.slots.short', [
                'price' => 30,
                'balance' => 10,
                'short' => 20,
            ]))
            ->and(botKeyboard())->toBe([[commandButton('en', 'shop')]]);
    });

    it('sends the user to an admin when the slot is not priced at all', function () {
        messengerAnswers();
        Log::spy();

        // An admin set the price to zero. `PurchaseEntitlement` refuses rather than
        // handing out a free slot, because a zero-coin `CoinPurchase` row would
        // misreport a baseline grant as something paid for.
        $this->settings->set(SettingKey::CreateSlotCoinPrice, 0);
        $user = slotBuyer(777_601_0, coins: 100);

        tapBuysSlot(
            BotCallback::encode(SlotPurchaseCallback::ACTION, EntitlementType::CreateSlot->value),
            ['id' => 777_601_0],
        );

        // The user is told the least useful thing anyone can tell them, so the
        // record that matters is the log line naming the misconfiguration.
        Log::shouldHaveReceived('error')->once();

        expect(Entitlement::query()->where('user_id', $user->getKey())->count())->toBe(0)
            ->and($this->ledger->balanceFor($user))->toBe(100)
            ->and(soleBotMessage()['text'])->toBe(botCopy('bot.slots.misconfigured'))
            // No button, because no button would help.
            ->and(botKeyboard())->toBe([]);
    });

    it('refuses a tap with no update id behind it rather than inventing a key', function () {
        messengerAnswers();
        Log::spy();

        $user = slotBuyer(777_601_1, coins: 100);

        // Parsed without an envelope: the shape a crafted payload or a button left
        // over from an older deploy would have. Any key invented here — a
        // timestamp, a random — would turn the *next* redelivery into a genuine
        // second charge, which is the exact failure the key exists to prevent.
        app(SlotPurchaseCallback::class)->handle(
            $user,
            BotCallback::parse(BotCallback::encode(SlotPurchaseCallback::ACTION, EntitlementType::CreateSlot->value)),
        );

        Log::shouldHaveReceived('warning')->once();

        expect(Entitlement::query()->where('user_id', $user->getKey())->count())->toBe(0)
            ->and($this->ledger->balanceFor($user))->toBe(100)
            ->and(soleBotMessage()['text'])->toBe(botCopy('bot.fallback.stale_button'));
    });

    it('refuses a payload naming a slot type that does not exist', function () {
        messengerAnswers();

        $user = slotBuyer(777_601_2, coins: 100);

        tapBuysSlot(BotCallback::encode(SlotPurchaseCallback::ACTION, 'freeze_slot'), ['id' => 777_601_2]);

        expect(Entitlement::query()->where('user_id', $user->getKey())->count())->toBe(0)
            ->and($this->ledger->balanceFor($user))->toBe(100)
            ->and(soleBotMessage()['text'])->toBe(botCopy('bot.fallback.stale_button'));
    });

    it('refuses to spend on behalf of someone the gate has lost', function () {
        // The button outlives the state that produced it, so membership is
        // re-verified at the tap rather than inherited from whenever the refusal
        // was sent.
        Http::fake([
            '*getChatMember*' => Http::response(['ok' => true, 'result' => ['status' => 'left']]),
            '*answerCallbackQuery*' => Http::response(['ok' => true, 'result' => true]),
            '*sendMessage*' => Http::response(['ok' => true, 'result' => ['message_id' => 11]]),
        ]);

        $user = slotBuyer(777_601_3, coins: 100);

        tapBuysSlot(
            BotCallback::encode(SlotPurchaseCallback::ACTION, EntitlementType::CreateSlot->value),
            ['id' => 777_601_3],
        );

        expect(Entitlement::query()->where('user_id', $user->getKey())->count())->toBe(0)
            ->and($this->ledger->balanceFor($user))->toBe(100)
            ->and(soleBotMessage()['text'])->toContain(botCopy('bot.gate.blocked', ['channel' => '@challenges']));
    });

    it('never reaches for a slot the balance belongs to somebody else', function () {
        messengerAnswers();
        $this->settings->set(SettingKey::CreateSlotCoinPrice, 30);

        // Coins are per user; the tap names only a slot type. A handler that
        // resolved the payer from anything but the verified sender would spend
        // these.
        $broke = slotBuyer(777_601_4);
        $rich = slotBuyer(777_601_5, coins: 30);

        tapBuysSlot(
            BotCallback::encode(SlotPurchaseCallback::ACTION, EntitlementType::CreateSlot->value),
            ['id' => 777_601_4],
        );

        expect($this->ledger->balanceFor($rich))->toBe(30)
            ->and(Entitlement::query()->where('user_id', $rich->getKey())->count())->toBe(0)
            ->and(Entitlement::query()->where('user_id', $broke->getKey())->count())->toBe(0);
    });
});
