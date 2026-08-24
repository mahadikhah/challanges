<?php

use App\Enums\CoinTransactionReason;
use App\Enums\ConversationState;
use App\Enums\EntitlementSource;
use App\Enums\EntitlementType;
use App\Enums\InviteStatus;
use App\Enums\ReminderKind;
use App\Enums\SettingKey;
use App\Enums\StarPaymentStatus;
use App\Models\BotConversation;
use App\Models\Challenge;
use App\Models\ChallengeParticipant;
use App\Models\ChallengePeriod;
use App\Models\CoinTransaction;
use App\Models\Entitlement;
use App\Models\Invite;
use App\Models\ReminderDispatch;
use App\Models\StarPayment;
use App\Models\TelegramUpdate;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('the coin ledger', function () {
    it('records a signed amount next to the balance it produced', function () {
        $entry = CoinTransaction::factory()
            ->because(CoinTransactionReason::StarsPurchase, 100)
            ->leavingBalance(100)
            ->create();

        expect($entry->fresh()?->amount)->toBe(100)
            ->and($entry->balance_after)->toBe(100)
            ->and($entry->reason)->toBe(CoinTransactionReason::StarsPurchase);
    });

    it('refuses a replayed idempotency key', function () {
        // The whole point of the column: a retried webhook must not pay twice.
        CoinTransaction::factory()->keyedBy('star_payment:credit:abc')->create();

        expect(fn () => CoinTransaction::factory()->keyedBy('star_payment:credit:abc')->create())
            ->toThrow(QueryException::class);
    });

    it('points back at whatever caused the entry', function () {
        $payment = StarPayment::factory()->paid()->create();

        $entry = CoinTransaction::factory()
            ->because(CoinTransactionReason::StarsPurchase)
            ->about($payment)
            ->create();

        expect($entry->fresh()?->reference)->toBeInstanceOf(StarPayment::class)
            ->and($entry->reference?->getKey())->toBe($payment->id);
    });

    it('leaves the reference null for an entry nothing caused', function () {
        $entry = CoinTransaction::factory()->create();

        expect($entry->fresh()?->reference_type)->toBeNull()
            ->and($entry->reference)->toBeNull();
    });

    it('reads a statement newest first', function () {
        $user = User::factory()->telegram()->create();

        $first = CoinTransaction::factory()->for($user)->create();
        $second = CoinTransaction::factory()->for($user)->create();
        CoinTransaction::factory()->create(); // someone else's money

        expect(CoinTransaction::query()->forUser($user)->pluck('id')->all())
            ->toBe([$second->id, $first->id]);
    });

    it('accepts a bare user id for a statement, so a lookup need not hydrate a model', function () {
        $user = User::factory()->telegram()->create();
        CoinTransaction::factory()->for($user)->count(2)->create();

        expect(CoinTransaction::query()->forUser($user->id)->count())->toBe(2);
    });

    it('reports magnitude regardless of direction', function () {
        $debit = CoinTransaction::factory()
            ->because(CoinTransactionReason::FreezePurchase, 15)
            ->create();

        expect($debit->amount)->toBe(-15)
            ->and($debit->magnitude())->toBe(15)
            ->and($debit->isDebit())->toBeTrue()
            ->and($debit->isCredit())->toBeFalse();
    });

    it('flags a row whose sign disagrees with its reason', function () {
        // Only reachable by writing around CoinLedger, which is exactly the
        // corruption this predicate exists to name.
        $corrupt = CoinTransaction::factory()->create([
            'reason' => CoinTransactionReason::FreezePurchase,
            'amount' => 15,
        ]);

        expect($corrupt->hasConsistentSign())->toBeFalse();
    });

    it('treats a zero-amount entry as inconsistent whatever the reason', function () {
        $noop = CoinTransaction::factory()->create(['amount' => 0]);

        expect($noop->hasConsistentSign())->toBeFalse();
    });

    it('holds a consistent sign for every reason the factory derives', function (CoinTransactionReason $reason) {
        $entry = CoinTransaction::factory()->because($reason, 7)->create();

        expect($entry->fresh()?->hasConsistentSign())->toBeTrue()
            ->and($entry->amount)->toBe(7 * $reason->sign());
    })->with(CoinTransactionReason::cases());

    it('follows the user it belongs to', function () {
        $user = User::factory()->telegram()->create();
        CoinTransaction::factory()->for($user)->count(3)->create();

        expect($user->coinTransactions)->toHaveCount(3)
            ->and($user->coinTransactions->first()?->user->id)->toBe($user->id);
    });
});

describe('entitlements', function () {
    it('starts life unspent', function () {
        $slot = Entitlement::factory()->create();

        expect($slot->isAvailable())->toBeTrue()
            ->and($slot->isConsumed())->toBeFalse()
            ->and($slot->consumed_at)->toBeNull();
    });

    it('counts only unspent slots of the type asked for', function () {
        $user = User::factory()->telegram()->create();

        Entitlement::factory()->for($user)->joinSlot()->count(2)->create();
        Entitlement::factory()->for($user)->joinSlot()->consumed()->create();
        Entitlement::factory()->for($user)->createSlot()->create();

        expect($user->entitlements()->available(EntitlementType::JoinSlot)->count())->toBe(2)
            ->and($user->entitlements()->available(EntitlementType::CreateSlot)->count())->toBe(1)
            ->and($user->entitlements()->available()->count())->toBe(3)
            ->and($user->entitlements()->consumed()->count())->toBe(1);
    });

    it('knows whether a coin transaction should exist for it', function (EntitlementSource $source, bool $expected) {
        $slot = Entitlement::factory()->from($source)->create();

        expect($slot->wasPaidFor())->toBe($expected);
    })->with([
        'free baseline' => [EntitlementSource::FreeBaseline, false],
        'bought with coins' => [EntitlementSource::CoinPurchase, true],
        'granted by an admin' => [EntitlementSource::AdminGrant, false],
    ]);

    it('names the challenge it was spent on', function () {
        $challenge = Challenge::factory()->create();
        $slot = Entitlement::factory()->createSlot()->consumed($challenge)->create();

        expect($slot->fresh()?->challenge?->id)->toBe($challenge->id);
    });

    it('survives the deletion of the challenge it was spent on', function () {
        // The slot is spent either way; forgetting that would hand it back for free.
        $challenge = Challenge::factory()->create();
        $slot = Entitlement::factory()->consumed($challenge)->create();

        $challenge->delete();

        expect($slot->fresh()?->challenge_id)->toBeNull()
            ->and($slot->fresh()?->isConsumed())->toBeTrue();
    });
});

describe('invites', function () {
    it('rejects a duplicate code, so a code is single-use', function () {
        Invite::factory()->withCode('welcome1')->create();

        expect(fn () => Invite::factory()->withCode('welcome1')->create())
            ->toThrow(QueryException::class);
    });

    it('attributes a user to at most one inviter for life', function () {
        // The structural half of "credit only a brand-new user": once someone is
        // claimed, no second invite can claim them again.
        $arrival = User::factory()->telegram()->create();
        Invite::factory()->creditedFor($arrival)->create();

        expect(fn () => Invite::factory()->creditedFor($arrival)->create())
            ->toThrow(QueryException::class);
    });

    it('lets many codes sit unclaimed despite the unique invited-user index', function () {
        Invite::factory()->count(3)->create();

        expect(Invite::query()->whereNull('invited_user_id')->count())->toBe(3);
    });

    it('distinguishes used-but-unpaid from never used', function () {
        $existing = User::factory()->telegram()->create();
        $claimed = Invite::factory()->claimedBy($existing)->create();
        $open = Invite::factory()->create();

        expect($claimed->isClaimed())->toBeTrue()
            ->and($claimed->wasPaid())->toBeFalse()
            ->and($claimed->credited_at)->toBeNull()
            ->and($open->isOpen())->toBeTrue()
            ->and($open->isClaimed())->toBeFalse();
    });

    it('records the moment an inviter got paid', function () {
        $invite = Invite::factory()->creditedFor(User::factory()->telegram()->create())->create();

        expect($invite->wasPaid())->toBeTrue()
            ->and($invite->credited_at)->not->toBeNull()
            ->and($invite->status)->toBe(InviteStatus::Credited);
    });

    it('scopes to codes still open to a new arrival', function () {
        $inviter = User::factory()->telegram()->create();

        $open = Invite::factory()->for($inviter, 'inviter')->create();
        Invite::factory()->for($inviter, 'inviter')->claimedBy(User::factory()->telegram()->create())->create();
        $credited = Invite::factory()->for($inviter, 'inviter')
            ->creditedFor(User::factory()->telegram()->create())
            ->create();

        expect($inviter->sentInvites()->open()->pluck('id')->all())->toBe([$open->id])
            ->and($inviter->sentInvites()->credited()->pluck('id')->all())->toBe([$credited->id]);
    });

    it('reaches a claimed invite from either side', function () {
        $inviter = User::factory()->telegram()->create();
        $arrival = User::factory()->telegram()->create();

        Invite::factory()->for($inviter, 'inviter')->creditedFor($arrival)->create();

        expect($arrival->claimedInvite?->inviter->id)->toBe($inviter->id)
            ->and($inviter->sentInvites->first()?->invitedUser?->id)->toBe($arrival->id);
    });

    it('builds a bot deep link, tolerating a configured @ prefix', function () {
        config()->set('services.telegram.bot_username', '@challenges_bot');
        $invite = Invite::factory()->withCode('abc123')->create();

        // `?start=`, not `?startapp=`: attribution happens on the bot's first
        // /start, before the Mini App is ever opened.
        expect($invite->deepLink())->toBe('https://t.me/challenges_bot?start=abc123');
    });
});

describe('star payments', function () {
    it('rejects a replayed charge id, so one payment credits once', function () {
        StarPayment::factory()->paid('charge_fixed')->create();

        expect(fn () => StarPayment::factory()->paid('charge_fixed')->create())
            ->toThrow(QueryException::class);
    });

    it('lets many invoices await payment without a charge id', function () {
        StarPayment::factory()->count(3)->create();

        expect(StarPayment::query()->whereNull('telegram_payment_charge_id')->count())->toBe(3);
    });

    it('rejects a duplicate invoice payload, so one invoice is one purchase', function () {
        StarPayment::factory()->withPayload('coins:fixed')->create();

        expect(fn () => StarPayment::factory()->withPayload('coins:fixed')->create())
            ->toThrow(QueryException::class);
    });

    it('keys crediting and refunding separately', function () {
        // A refund has to be able to write even though the credit already did.
        $payment = StarPayment::factory()->paid('charge_xyz')->create();

        expect($payment->creditIdempotencyKey())->toBe('star_payment:credit:charge_xyz')
            ->and($payment->refundIdempotencyKey())->toBe('star_payment:refund:charge_xyz')
            ->and($payment->creditIdempotencyKey())->not->toBe($payment->refundIdempotencyKey());
    });

    it('refuses to call a paid row refundable when Telegram gave us no charge id', function () {
        // `refundStarPayment` needs the id; without it there is nothing to call.
        $unrefundable = StarPayment::factory()->create([
            'status' => StarPaymentStatus::Paid,
            'telegram_payment_charge_id' => null,
        ]);

        expect($unrefundable->isPaid())->toBeTrue()
            ->and($unrefundable->isRefundable())->toBeFalse();
    });

    it('stops being refundable once it has been refunded', function () {
        $refunded = StarPayment::factory()->refunded()->create();

        expect($refunded->isRefundable())->toBeFalse()
            ->and($refunded->isPaid())->toBeFalse()
            ->and($refunded->refunded_at)->not->toBeNull();
    });

    it('freezes the coin amount at invoice time', function () {
        // A later change to the Stars packages must not retroactively re-price a
        // purchase somebody already paid for.
        $payment = StarPayment::factory()->buying(coins: 120, stars: 100)->paid()->create();

        expect($payment->fresh()?->coin_amount)->toBe(120)
            ->and($payment->stars_amount)->toBe(100);
    });

    it('keeps the raw successful_payment payload for reconciliation', function () {
        $payment = StarPayment::factory()->paid()->create();

        expect($payment->fresh()?->payload)->toBeArray()
            ->and($payment->payload['successful_payment']['currency'] ?? null)->toBe('XTR');
    });

    it('scopes paid apart from pending', function () {
        $user = User::factory()->telegram()->create();

        StarPayment::factory()->for($user)->paid()->create();
        StarPayment::factory()->for($user)->count(2)->create();
        StarPayment::factory()->for($user)->failed()->create();

        expect($user->starPayments()->paid()->count())->toBe(1)
            ->and($user->starPayments()->pending()->count())->toBe(2);
    });
});

describe('recorded webhook updates', function () {
    it('rejects a replayed update id', function () {
        // Telegram retries anything non-2xx; the unique index is what makes that safe.
        TelegramUpdate::factory()->withUpdateId(4242)->create();

        expect(fn () => TelegramUpdate::factory()->withUpdateId(4242)->create())
            ->toThrow(QueryException::class);
    });

    it('derives its kind from the payload shape', function () {
        expect(TelegramUpdate::factory()->message('/start')->create()->kind())->toBe('message')
            ->and(TelegramUpdate::factory()->callbackQuery('join:1')->create()->kind())->toBe('callback_query')
            ->and(TelegramUpdate::factory()->preCheckoutQuery('coins:1')->create()->kind())->toBe('pre_checkout_query');
    });

    it('says nothing rather than guessing at a shape we do not handle', function () {
        $update = TelegramUpdate::factory()->unhandled()->create();

        expect($update->kind())->toBeNull()
            ->and($update->fromTelegramId())->toBeNull();
    });

    it('reads the sender out of whichever kind it is', function () {
        $message = TelegramUpdate::factory()->message('/start', 777)->create();
        $callback = TelegramUpdate::factory()->callbackQuery('join:1', 888)->create();

        expect($message->fromTelegramId())->toBe(777)
            ->and($callback->fromTelegramId())->toBe(888);
    });

    it('tolerates a missing path rather than exploding on Telegram-shaped data', function () {
        $update = TelegramUpdate::factory()->message('/start')->create();

        expect($update->value('message.text'))->toBe('/start')
            ->and($update->value('message.photo.0.file_id'))->toBeNull()
            ->and($update->value('message.photo.0.file_id', 'none'))->toBe('none');
    });

    it('queues unprocessed updates oldest first', function () {
        $first = TelegramUpdate::factory()->create();
        $second = TelegramUpdate::factory()->create();
        $done = TelegramUpdate::factory()->processed()->create();

        expect(TelegramUpdate::query()->unprocessed()->pluck('id')->all())->toBe([$first->id, $second->id])
            ->and($done->isProcessed())->toBeTrue();
    });
});

describe('bot conversations', function () {
    it('holds one flow per user, so starting a wizard replaces the last', function () {
        $user = User::factory()->telegram()->create();
        BotConversation::factory()->for($user)->create();

        expect(fn () => BotConversation::factory()->for($user)->create())
            ->toThrow(QueryException::class);
    });

    it('merges answers as it advances rather than replacing them', function () {
        // A step must be revisitable without losing the answers gathered around it.
        $conversation = BotConversation::factory()
            ->at(ConversationState::AwaitingPeriodType, ['title' => 'Read daily'])
            ->create();

        $conversation->advanceTo(ConversationState::AwaitingStartDate, ['period_type' => 'daily'])->save();

        expect($conversation->fresh()?->state)->toBe(ConversationState::AwaitingStartDate)
            ->and($conversation->fresh()?->payload)
            ->toBe(['title' => 'Read daily', 'period_type' => 'daily']);
    });

    it('overwrites an answer that is given again', function () {
        $conversation = BotConversation::factory()->withAnswers(['title' => 'first'])->create();

        $conversation->advanceTo(ConversationState::AwaitingChallengeTitle, ['title' => 'second'])->save();

        expect($conversation->fresh()?->answer('title'))->toBe('second');
    });

    it('advances from an empty payload without tripping over the null', function () {
        $conversation = BotConversation::factory()->create(['payload' => null]);

        $conversation->advanceTo(ConversationState::AwaitingChallengeDescription, ['title' => 'Walk'])->save();

        expect($conversation->fresh()?->payload)->toBe(['title' => 'Walk'])
            ->and($conversation->answer('missing', 'fallback'))->toBe('fallback');
    });

    it('reads a nested answer by dot path', function () {
        $conversation = BotConversation::factory()
            ->withAnswers(['schedule' => ['timezone' => 'Asia/Tehran']])
            ->create();

        expect($conversation->answer('schedule.timezone'))->toBe('Asia/Tehran');
    });

    it('treats a lapsed conversation as dead and a null expiry as immortal', function () {
        $live = BotConversation::factory()->create();
        $expired = BotConversation::factory()->expired()->create();
        $everlasting = BotConversation::factory()->everlasting()->create();

        expect($live->isLive())->toBeTrue()
            ->and($expired->hasExpired())->toBeTrue()
            ->and($expired->isLive())->toBeFalse()
            ->and($everlasting->hasExpired())->toBeFalse();

        expect(BotConversation::query()->live()->pluck('id')->all())
            ->toEqualCanonicalizing([$live->id, $everlasting->id])
            ->and(BotConversation::query()->expired()->pluck('id')->all())->toBe([$expired->id]);
    });

    it('is reachable from the user it belongs to', function () {
        $user = User::factory()->telegram()->create();
        BotConversation::factory()->for($user)->at(ConversationState::AwaitingProofType)->create();

        expect($user->conversation?->state)->toBe(ConversationState::AwaitingProofType);
    });
});

describe('reminder dispatches', function () {
    it('allows one row per participant, period and kind', function () {
        // The idempotency key that stops a re-run double-sending.
        $participant = ChallengeParticipant::factory()->create();
        $period = ChallengePeriod::factory()->create(['challenge_id' => $participant->challenge_id]);

        ReminderDispatch::factory()->on($participant, $period)->ofKind(ReminderKind::PeriodOpened)->create();

        expect(fn () => ReminderDispatch::factory()
            ->on($participant, $period)
            ->ofKind(ReminderKind::PeriodOpened)
            ->create())
            ->toThrow(QueryException::class);
    });

    it('still allows a different nudge for the same period', function () {
        $participant = ChallengeParticipant::factory()->create();
        $period = ChallengePeriod::factory()->create(['challenge_id' => $participant->challenge_id]);

        ReminderDispatch::factory()->on($participant, $period)->ofKind(ReminderKind::PeriodOpened)->create();
        ReminderDispatch::factory()->on($participant, $period)->ofKind(ReminderKind::PeriodEnding)->create();

        expect(ReminderDispatch::query()->count())->toBe(2);
    });

    it('picks up what is due and leaves the rest alone', function () {
        $due = ReminderDispatch::factory()->stalled()->create();
        ReminderDispatch::factory()->upcoming()->create();
        ReminderDispatch::factory()->sent()->create();

        expect(ReminderDispatch::query()->due()->pluck('id')->all())->toBe([$due->id]);
    });

    it('orders the due queue by when it should have gone out', function () {
        $later = ReminderDispatch::factory()->scheduledFor(now()->subMinutes(5)->toDateTimeString())->create();
        $earlier = ReminderDispatch::factory()->scheduledFor(now()->subHour()->toDateTimeString())->create();

        expect(ReminderDispatch::query()->due()->pluck('id')->all())->toBe([$earlier->id, $later->id]);
    });

    it('separates a failed send from one that is merely not due yet', function () {
        expect(ReminderDispatch::factory()->stalled()->create()->hasStalled())->toBeTrue()
            ->and(ReminderDispatch::factory()->upcoming()->create()->hasStalled())->toBeFalse()
            ->and(ReminderDispatch::factory()->sent()->create()->hasStalled())->toBeFalse();
    });

    it('is reachable from both the participant and the period', function () {
        $participant = ChallengeParticipant::factory()->create();
        $period = ChallengePeriod::factory()->create(['challenge_id' => $participant->challenge_id]);

        $dispatch = ReminderDispatch::factory()->on($participant, $period)->create();

        expect($participant->reminderDispatches->pluck('id')->all())->toBe([$dispatch->id])
            ->and($period->reminderDispatches->pluck('id')->all())->toBe([$dispatch->id])
            ->and($dispatch->participant->id)->toBe($participant->id)
            ->and($dispatch->period->id)->toBe($period->id);
    });
});

describe('economy enum labels', function () {
    it('translates every case in both locales', function (string $enum) {
        /** @var class-string<BackedEnum> $enum */
        foreach (['en', 'fa'] as $locale) {
            app()->setLocale($locale);

            foreach ($enum::cases() as $case) {
                /** @var object{label: callable-string} $case */
                $label = $case->label();

                // A missing line comes back as the raw key, which is the bug.
                expect($label)->not->toContain('enums.')
                    ->and($label)->not->toBe('');
            }
        }
    })->with([
        CoinTransactionReason::class,
        EntitlementType::class,
        EntitlementSource::class,
        InviteStatus::class,
        StarPaymentStatus::class,
        ReminderKind::class,
    ]);

    it('leaves the conversation state untranslated, because a user never sees it', function () {
        // Internal machinery. If this ever grows a label(), it has been confused
        // with the prompts the wizard sends, which are their own lang lines.
        expect(method_exists(ConversationState::class, 'label'))->toBeFalse();
    });

    it('offers reasons as a value => label map for admin filters', function () {
        app()->setLocale('en');

        expect(CoinTransactionReason::options())
            ->toHaveCount(count(CoinTransactionReason::cases()))
            ->and(CoinTransactionReason::options()['invite_credit'])->toBe('Invite reward');
    });
});

describe('economy enum behaviour', function () {
    it('takes the sign of a transaction from its reason', function (CoinTransactionReason $reason) {
        expect($reason->sign())->toBe($reason->isCredit() ? 1 : -1)
            ->and($reason->isDebit())->toBe(! $reason->isCredit());
    })->with(CoinTransactionReason::cases());

    it('splits admin adjustments in two so the caller cannot choose a sign', function () {
        expect(CoinTransactionReason::AdminCredit->sign())->toBe(1)
            ->and(CoinTransactionReason::AdminDebit->sign())->toBe(-1);
    });

    it('treats a Stars refund as money leaving the balance', function () {
        expect(CoinTransactionReason::StarsRefund->isDebit())->toBeTrue();
    });

    it('maps a slot type to its allowance, price and ledger reason', function () {
        expect(EntitlementType::CreateSlot->freeAllowanceSetting())->toBe(SettingKey::FreeCreateSlots)
            ->and(EntitlementType::CreateSlot->priceSetting())->toBe(SettingKey::CreateSlotCoinPrice)
            ->and(EntitlementType::CreateSlot->purchaseReason())->toBe(CoinTransactionReason::CreateSlotPurchase)
            ->and(EntitlementType::JoinSlot->freeAllowanceSetting())->toBe(SettingKey::FreeJoinSlots)
            ->and(EntitlementType::JoinSlot->priceSetting())->toBe(SettingKey::JoinSlotCoinPrice)
            ->and(EntitlementType::JoinSlot->purchaseReason())->toBe(CoinTransactionReason::JoinSlotPurchase);
    });

    it('counts only a coin purchase as paid for', function () {
        expect(EntitlementSource::CoinPurchase->isPaid())->toBeTrue()
            ->and(EntitlementSource::FreeBaseline->isPaid())->toBeFalse()
            ->and(EntitlementSource::AdminGrant->isPaid())->toBeFalse();
    });

    it('knows which payment states can still move', function (StarPaymentStatus $status, bool $terminal) {
        expect($status->isTerminal())->toBe($terminal);
    })->with([
        'pending' => [StarPaymentStatus::Pending, false],
        'paid' => [StarPaymentStatus::Paid, false],
        'refunded' => [StarPaymentStatus::Refunded, true],
        'failed' => [StarPaymentStatus::Failed, true],
    ]);

    it('suppresses only the last-chance nudge once a period is settled', function (ReminderKind $kind, bool $skip) {
        expect($kind->skipWhenSettled())->toBe($skip);
    })->with([
        'challenge starting' => [ReminderKind::ChallengeStarting, false],
        'period opened' => [ReminderKind::PeriodOpened, false],
        'period ending' => [ReminderKind::PeriodEnding, true],
    ]);

    it('knows what input each conversation state is waiting for', function (
        ConversationState $state,
        bool $text,
        bool $photo,
    ) {
        expect($state->expectsText())->toBe($text)
            ->and($state->expectsPhoto())->toBe($photo)
            ->and($state->expectsCallback())->toBe(! $text && ! $photo);
    })->with([
        'title' => [ConversationState::AwaitingChallengeTitle, true, false],
        'description' => [ConversationState::AwaitingChallengeDescription, true, false],
        'period type' => [ConversationState::AwaitingPeriodType, false, false],
        'custom days' => [ConversationState::AwaitingCustomPeriodDays, true, false],
        'start date' => [ConversationState::AwaitingStartDate, true, false],
        'total periods' => [ConversationState::AwaitingTotalPeriods, true, false],
        'timezone' => [ConversationState::AwaitingTimezone, false, false],
        'proof type' => [ConversationState::AwaitingProofType, false, false],
        'visibility' => [ConversationState::AwaitingVisibility, false, false],
        'confirmation' => [ConversationState::AwaitingCreateConfirmation, false, false],
        'check-in text' => [ConversationState::AwaitingCheckInText, true, false],
        'check-in photo' => [ConversationState::AwaitingCheckInPhoto, false, true],
    ]);

    it('splits the wizard steps from the check-in steps with no state left over', function () {
        $wizard = array_filter(ConversationState::cases(), fn (ConversationState $s) => $s->isCreateChallengeStep());
        $checkIn = array_filter(ConversationState::cases(), fn (ConversationState $s) => $s->isCheckInStep());

        expect(count($wizard) + count($checkIn))->toBe(count(ConversationState::cases()))
            ->and($checkIn)->toHaveCount(2);
    });
});

describe('economy enum values are stable', function () {
    it('keeps the stored value, not the case name, so a rename cannot orphan rows', function () {
        $entry = CoinTransaction::factory()->because(CoinTransactionReason::InviteCredit)->create();

        expect($entry->getRawOriginal('reason'))->toBe('invite_credit');
    });

    it('pins every case value', function (string $enum, array $expected) {
        /** @var class-string<BackedEnum> $enum */
        expect(array_column($enum::cases(), 'value'))->toBe($expected);
    })->with([
        'coin transaction reason' => [CoinTransactionReason::class, [
            'stars_purchase',
            'invite_credit',
            'challenge_completion',
            'admin_credit',
            'create_slot_purchase',
            'join_slot_purchase',
            'freeze_purchase',
            'stars_refund',
            'admin_debit',
        ]],
        'entitlement type' => [EntitlementType::class, ['create_slot', 'join_slot']],
        'entitlement source' => [EntitlementSource::class, ['free_baseline', 'coin_purchase', 'admin_grant']],
        'invite status' => [InviteStatus::class, ['pending', 'claimed', 'credited']],
        'star payment status' => [StarPaymentStatus::class, ['pending', 'paid', 'refunded', 'failed']],
        'reminder kind' => [ReminderKind::class, ['challenge_starting', 'period_opened', 'period_ending']],
    ]);
});
