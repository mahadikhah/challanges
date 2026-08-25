<?php

use App\Actions\Challenges\CreateChallenge;
use App\Actions\Entitlements\ConsumeEntitlement;
use App\Enums\ChallengeStatus;
use App\Enums\ChallengeVisibility;
use App\Enums\ConversationState;
use App\Enums\EntitlementType;
use App\Enums\PeriodType;
use App\Enums\ProofType;
use App\Enums\SettingKey;
use App\Jobs\Challenges\AnnounceChallenge;
use App\Jobs\Telegram\ProcessTelegramUpdate;
use App\Models\BotConversation;
use App\Models\Challenge;
use App\Models\Entitlement;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\Settings;
use App\Services\Telegram\BotCallback;
use App\Services\Telegram\Wizards\CreateChallengeWizard;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
 * The create-challenge wizard, driven the way Telegram drives it: one recorded update
 * at a time through `ProcessTelegramUpdate`, the real routers, and the real
 * `BotConversation` row. Nothing is called directly, because the whole point of that
 * row is that two consecutive answers arrive in two unrelated queue jobs and the only
 * thing joining them is what was written down.
 *
 * `AnnounceChallenge` is faked throughout. Whether the channel post lands is
 * `ChannelBroadcaster`'s contract, tested next door; what matters here is that a
 * public challenge asks for one and an invite-only challenge does not. Relying on the
 * real dispatch would be worse than indirect — the job is queued `afterCommit()`, and
 * `RefreshDatabase` wraps each test in a transaction that never commits.
 */

const WIZARD_TELEGRAM_ID = 777_100_1;

beforeEach(function () {
    Http::preventStrayRequests();
    Bus::fake([AnnounceChallenge::class]);

    config(['services.telegram.bot_token' => '123456:TEST-TOKEN']);

    $this->settings = app(Settings::class);
    $this->settings->set(SettingKey::RequiredChannel, '@challenges');

    // Membership already stamped, so the gate is satisfied from the row and these
    // tests spend their Bot API budget on replies rather than on `getChatMember`.
    // The tests that care about the gate arrange their own answer to it.
    $this->creator = User::factory()
        ->telegram(WIZARD_TELEGRAM_ID)
        ->channelVerified()
        ->preferring('en')
        ->create(['first_name' => 'Sara']);

    grantCreateSlots($this->creator, 1);

    Http::fake([
        '*sendMessage*' => Http::response(['ok' => true, 'result' => ['message_id' => 11]]),
        '*answerCallbackQuery*' => Http::response(['ok' => true, 'result' => true]),
    ]);
});

/**
 * Give a creator slots to spend.
 */
function grantCreateSlots(User $user, int $count): void
{
    Entitlement::factory()->createSlot()->count($count)->create(['user_id' => $user->getKey()]);
}

/**
 * The user types something at the bot.
 */
function wizardTypes(string $text): TelegramUpdate
{
    $update = TelegramUpdate::factory()
        ->messageFrom(['id' => WIZARD_TELEGRAM_ID, 'first_name' => 'Sara', 'language_code' => 'en'], $text)
        ->create();

    dispatch_sync(new ProcessTelegramUpdate($update));

    return $update;
}

/**
 * The user sends something with no text in it.
 */
function wizardSendsPhoto(): TelegramUpdate
{
    $update = TelegramUpdate::factory()->create(['payload' => [
        'message' => [
            'message_id' => 5,
            'from' => ['id' => WIZARD_TELEGRAM_ID, 'is_bot' => false, 'first_name' => 'Sara'],
            'chat' => ['id' => WIZARD_TELEGRAM_ID, 'type' => 'private'],
            'photo' => [['file_id' => 'abc']],
        ],
    ]]);

    dispatch_sync(new ProcessTelegramUpdate($update));

    return $update;
}

/**
 * The user taps a button carrying `$data` verbatim.
 */
function wizardTapsData(string $data): TelegramUpdate
{
    $update = TelegramUpdate::factory()
        ->callbackQueryFrom(['id' => WIZARD_TELEGRAM_ID, 'first_name' => 'Sara', 'language_code' => 'en'], $data)
        ->create();

    dispatch_sync(new ProcessTelegramUpdate($update));

    return $update;
}

/**
 * The user taps a button the bot sent for `$step`.
 *
 * The step travels in the callback data exactly as the wizard encoded it, so a test
 * can tap the *wrong* step deliberately — which is what a user scrolling up does.
 */
function wizardTaps(ConversationState $step, string $value): TelegramUpdate
{
    return wizardTapsData(BotCallback::encode(CreateChallengeWizard::ACTION, $step->value, $value));
}

/**
 * Tap the button the bot last offered for `$value`.
 *
 * Reads the step out of the live flow rather than being told it, so a test that walks
 * the whole wizard does not have to restate the order the wizard owns.
 */
function wizardChooses(string $value): TelegramUpdate
{
    return wizardTaps(BotConversation::query()->sole()->state, $value);
}

/**
 * The callback values the last keyboard offered, in order, with their step stripped.
 *
 * @return list<string>
 */
function lastOfferedValues(): array
{
    $values = [];

    foreach (lastBotKeyboard() as $row) {
        foreach ($row as $button) {
            $parts = explode(':', $button['callback_data'] ?? '');
            $values[] = (string) end($parts);
        }
    }

    return $values;
}

/**
 * The flow, live or lapsed, or null once it has been dropped.
 */
function liveFlow(): ?BotConversation
{
    return BotConversation::query()->first();
}

/**
 * The answers gathered so far.
 *
 * @return array<string, mixed>
 */
function flowAnswers(): array
{
    return liveFlow()?->payload ?? [];
}

/**
 * A flow parked at a step with answers already given, skipping the walk to it.
 *
 * @param  array<string, mixed>  $answers
 */
function flowSittingAt(ConversationState $state, array $answers = []): BotConversation
{
    return BotConversation::factory()
        ->at($state, $answers)
        ->create(['user_id' => test()->creator->getKey()]);
}

/**
 * The same flow, but already lapsed.
 *
 * @param  array<string, mixed>  $answers
 */
function lapsedFlowAt(ConversationState $state, array $answers = []): BotConversation
{
    return BotConversation::factory()
        ->at($state, $answers)
        ->expired()
        ->create(['user_id' => test()->creator->getKey()]);
}

/**
 * Every answer the wizard needs, for the tests that only care about the last step.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function completeDraft(array $overrides = []): array
{
    return array_replace([
        'title' => 'Read every day',
        'description' => 'Twenty pages, no excuses.',
        'period_type' => PeriodType::Daily->value,
        'timezone' => 'Asia/Tehran',
        'start_date' => CarbonImmutable::now('Asia/Tehran')->addDay()->toDateString(),
        'total_periods' => 30,
        'proof_type' => ProofType::Button->value,
        'visibility' => ChallengeVisibility::InviteOnly->value,
    ], $overrides);
}

/**
 * Every bound a wizard prompt or error line might interpolate.
 *
 * @return array<string, int>
 */
function wizardLimits(): array
{
    return CreateChallenge::limits();
}

describe('opening the flow', function () {
    it('asks for a title and records where the flow is', function () {
        wizardTypes('/create');

        expect(liveFlow()?->state)->toBe(ConversationState::AwaitingChallengeTitle)
            ->and(flowAnswers())->toBe([])
            ->and(soleBotMessage()['text'])
            ->toContain(botCopy('bot.wizard.opening'))
            ->toContain(botCopy('bot.wizard.awaiting_challenge_title.prompt', wizardLimits()));
    });

    it('gives the flow the lifetime an admin configured', function () {
        $this->settings->set(SettingKey::ConversationTtlMinutes, 5);

        wizardTypes('/create');

        expect(liveFlow()?->expires_at?->timestamp)
            ->toBeGreaterThan(now()->addMinutes(4)->timestamp)
            ->toBeLessThanOrEqual(now()->addMinutes(5)->timestamp);
    });

    it('sends a non-member to the channel instead of asking anything', function () {
        $this->creator->forceFill(['channel_verified_at' => null])->save();
        Http::fake(['*getChatMember*' => Http::response(['ok' => true, 'result' => ['status' => 'left']])]);

        wizardTypes('/create');

        // The gate comes before the first question, not after the tenth.
        expect(liveFlow())->toBeNull()
            ->and(soleBotMessage()['text'])->toContain(botCopy('bot.gate.blocked', ['channel' => '@challenges']));
    });

    it('refuses when there is no create-slot left, and says what one costs', function () {
        Entitlement::query()->where('user_id', $this->creator->getKey())->delete();

        wizardTypes('/create');

        expect(liveFlow())->toBeNull()
            ->and(soleBotMessage()['text'])
            ->toContain(botCopy('bot.wizard.no_slot'))
            // A dead end with no price is worse than a refusal, and the price is a
            // `Setting` — so it has to be read at the moment of refusal.
            ->toContain(botCopy('bot.wizard.slot_price', [
                'coins' => $this->settings->integer(EntitlementType::CreateSlot->priceSetting()),
            ]));
    });

    it('restarts from scratch when /create arrives mid-flow', function () {
        flowSittingAt(ConversationState::AwaitingProofType, completeDraft());

        wizardTypes('/create');

        expect(liveFlow()?->state)->toBe(ConversationState::AwaitingChallengeTitle)
            ->and(flowAnswers())->toBe([])
            ->and(soleBotMessage()['text'])->toContain(botCopy('bot.wizard.restarted'));
    });

    it('does not call a restart a restart when the last flow had lapsed', function () {
        lapsedFlowAt(ConversationState::AwaitingProofType, completeDraft());

        wizardTypes('/create');

        expect(soleBotMessage()['text'])
            ->toContain(botCopy('bot.wizard.opening'))
            ->not->toContain(botCopy('bot.wizard.restarted'));

        // The lapsed row is replaced rather than joined by a second one: a user has
        // at most one flow, and `bot_conversations.user_id` is unique.
        expect(BotConversation::query()->count())->toBe(1);
    });
});

describe('a typed answer', function () {
    it('is recorded, and the next question asked', function () {
        flowSittingAt(ConversationState::AwaitingChallengeTitle);

        wizardTypes('Read every day');

        expect(flowAnswers()['title'])->toBe('Read every day')
            ->and(liveFlow()?->state)->toBe(ConversationState::AwaitingChallengeDescription)
            ->and(soleBotMessage()['text'])
            ->toContain(botCopy('bot.wizard.awaiting_challenge_description.prompt', wizardLimits()));
    });

    it('re-asks with the bound when it cannot be read', function (ConversationState $state, string $text) {
        flowSittingAt($state, completeDraft());

        wizardTypes($text);

        // The step does not move, and the reason given is the step's own `.error`
        // line — so a creator is told the bound rather than just told no.
        expect(liveFlow()?->state)->toBe($state)
            ->and(soleBotMessage()['text'])->toContain(botCopy("bot.wizard.{$state->value}.error", wizardLimits()));
    })->with([
        'a title of two characters' => [ConversationState::AwaitingChallengeTitle, 'ab'],
        'a title past the limit' => [ConversationState::AwaitingChallengeTitle, str_repeat('x', 121)],
        'a description past the limit' => [ConversationState::AwaitingChallengeDescription, str_repeat('y', 1001)],
        'no periods at all' => [ConversationState::AwaitingTotalPeriods, '0'],
        'more periods than we allow' => [ConversationState::AwaitingTotalPeriods, '1001'],
        'periods that are not a number' => [ConversationState::AwaitingTotalPeriods, 'lots'],
        'a negative period length' => [ConversationState::AwaitingCustomPeriodDays, '-3'],
        'a period longer than a year' => [ConversationState::AwaitingCustomPeriodDays, '400'],
    ]);

    it('counts a title in characters, so a Farsi one is not cut in half', function () {
        flowSittingAt(ConversationState::AwaitingChallengeTitle);

        // Well inside 120 characters and well past 120 bytes.
        $title = str_repeat('کتاب ', 20);

        wizardTypes($title);

        expect(flowAnswers()['title'])->toBe(trim($title))
            ->and(liveFlow()?->state)->toBe(ConversationState::AwaitingChallengeDescription);
    });

    it('reads a count typed on a Persian keyboard', function () {
        flowSittingAt(ConversationState::AwaitingTotalPeriods, completeDraft());

        wizardTypes('۱۴');

        expect(flowAnswers()['total_periods'])->toBe(14);
    });

    it('asks again when a step that wants a button is typed at', function () {
        flowSittingAt(ConversationState::AwaitingPeriodType, completeDraft());

        wizardTypes('daily');

        // Not an error the user made — they missed the keyboard. The reply names the
        // form the answer has to take rather than quoting a bound.
        expect(liveFlow()?->state)->toBe(ConversationState::AwaitingPeriodType)
            ->and(soleBotMessage()['text'])->toContain(botCopy('bot.wizard.awaiting_period_type.expected'));
    });

    it('asks again when a step that wants words gets a photo', function () {
        flowSittingAt(ConversationState::AwaitingChallengeTitle);

        wizardSendsPhoto();

        expect(liveFlow()?->state)->toBe(ConversationState::AwaitingChallengeTitle)
            ->and(soleBotMessage()['text'])->toContain(botCopy('bot.wizard.awaiting_challenge_title.expected'));
    });

    it('lets a command win over the question it interrupts', function () {
        flowSittingAt(ConversationState::AwaitingChallengeTitle);

        wizardTypes('/cancel');

        // The cost of this ordering is that a title cannot begin with a slash. The
        // alternative is a user who cannot get out of a flow, which is worse.
        expect(liveFlow())->toBeNull()
            ->and(soleBotMessage()['text'])->toBe(botCopy('bot.wizard.cancelled'));
    });

    it('ignores text sent to a flow that has lapsed', function () {
        lapsedFlowAt(ConversationState::AwaitingChallengeTitle);

        wizardTypes('Read every day');

        expect(flowAnswers())->toBe([])
            ->and(soleBotMessage()['text'])->toBe(botCopy('bot.fallback.unknown'));
    });
});

describe('the start date', function () {
    it('reads an ISO date as a day in the challenge’s timezone', function () {
        flowSittingAt(ConversationState::AwaitingStartDate, ['timezone' => 'Asia/Tehran']);

        $date = CarbonImmutable::now('Asia/Tehran')->addDays(3)->toDateString();

        wizardTypes($date);

        expect(flowAnswers()['start_date'])->toBe($date)
            ->and(liveFlow()?->state)->toBe(ConversationState::AwaitingTotalPeriods);
    });

    it('accepts the separators a phone keyboard offers', function (string $separator) {
        flowSittingAt(ConversationState::AwaitingStartDate, ['timezone' => 'UTC']);

        $date = CarbonImmutable::now('UTC')->addDays(2);

        wizardTypes($date->format("Y{$separator}m{$separator}d"));

        expect(flowAnswers()['start_date'])->toBe($date->toDateString());
    })->with([
        'dashes' => ['-'],
        'slashes' => ['/'],
        'dots' => ['.'],
    ]);

    it('refuses a date that has already gone', function () {
        flowSittingAt(ConversationState::AwaitingStartDate, ['timezone' => 'UTC']);

        wizardTypes(CarbonImmutable::now('UTC')->subDay()->toDateString());

        // A start date in the past means a period that closed before the challenge
        // existed, and a participant already behind on it.
        expect(liveFlow()?->state)->toBe(ConversationState::AwaitingStartDate)
            ->and(flowAnswers())->not->toHaveKey('start_date');
    });

    it('refuses a day that does not exist rather than rolling it over', function (string $date) {
        flowSittingAt(ConversationState::AwaitingStartDate, ['timezone' => 'UTC']);

        wizardTypes($date);

        // Carbon would turn 31 April into 1 May without complaining; somebody who
        // typed the wrong month should hear about it.
        expect(flowAnswers())->not->toHaveKey('start_date');
    })->with([
        'the 31st of April' => ['2099-04-31'],
        'the 30th of February' => ['2099-02-30'],
        'the 13th month' => ['2099-13-01'],
    ]);

    it('refuses something that is not a date', function (string $text) {
        flowSittingAt(ConversationState::AwaitingStartDate, ['timezone' => 'UTC']);

        wizardTypes($text);

        expect(flowAnswers())->not->toHaveKey('start_date');
    })->with([
        'a day and month only' => ['03-04'],
        'words' => ['next monday'],
        'a two-digit year' => ['26-01-01'],
    ]);

    it('reads Today from the challenge’s calendar, not the server’s', function () {
        // 22:00 UTC is already tomorrow in Tehran. A creator who taps "Today" means
        // their own today, which is the whole reason the timezone is asked first.
        $this->travelTo(CarbonImmutable::parse('2026-03-10 22:00:00', 'UTC'));

        flowSittingAt(ConversationState::AwaitingStartDate, ['timezone' => 'Asia/Tehran']);

        wizardChooses(CreateChallengeWizard::TODAY);

        expect(flowAnswers()['start_date'])->toBe('2026-03-11');
    });

    it('reads Tomorrow as the day after that', function () {
        $this->travelTo(CarbonImmutable::parse('2026-03-10 22:00:00', 'UTC'));

        flowSittingAt(ConversationState::AwaitingStartDate, ['timezone' => 'Asia/Tehran']);

        wizardChooses(CreateChallengeWizard::TOMORROW);

        expect(flowAnswers()['start_date'])->toBe('2026-03-12');
    });
});

describe('a tapped answer', function () {
    it('offers every case of the enum it is asking about', function (ConversationState $state, array $expected) {
        flowSittingAt($state, completeDraft());

        // Typing at a button step re-asks it, which puts the keyboard on the wire
        // where a test can read it back.
        wizardTypes('anything');

        expect(lastOfferedValues())->toBe($expected);
    })->with([
        'period types' => [
            ConversationState::AwaitingPeriodType,
            ['daily', 'weekly', 'monthly', 'seasonal', 'yearly', 'custom'],
        ],
        'proof types' => [
            ConversationState::AwaitingProofType,
            ['button', 'text_autogen', 'image_approval'],
        ],
        'visibilities' => [
            ConversationState::AwaitingVisibility,
            ['public', 'invite_only'],
        ],
    ]);

    it('asks for a day count only when the period is a custom one', function (PeriodType $type, ConversationState $next) {
        flowSittingAt(ConversationState::AwaitingPeriodType, ['title' => 'Read every day']);

        wizardChooses($type->value);

        expect(flowAnswers()['period_type'])->toBe($type->value)
            ->and(liveFlow()?->state)->toBe($next);
    })->with([
        'daily' => [PeriodType::Daily, ConversationState::AwaitingTimezone],
        'weekly' => [PeriodType::Weekly, ConversationState::AwaitingTimezone],
        'monthly' => [PeriodType::Monthly, ConversationState::AwaitingTimezone],
        'seasonal' => [PeriodType::Seasonal, ConversationState::AwaitingTimezone],
        'yearly' => [PeriodType::Yearly, ConversationState::AwaitingTimezone],
        'custom' => [PeriodType::Custom, ConversationState::AwaitingCustomPeriodDays],
    ]);

    it('skips the description without storing an empty one', function () {
        flowSittingAt(ConversationState::AwaitingChallengeDescription, ['title' => 'Read every day']);

        wizardChooses(CreateChallengeWizard::SKIP);

        expect(flowAnswers())->toHaveKey('description')
            ->and(flowAnswers()['description'])->toBeNull()
            ->and(liveFlow()?->state)->toBe(ConversationState::AwaitingPeriodType);
    });

    it('re-asks the current question when a button from further up is tapped', function () {
        flowSittingAt(ConversationState::AwaitingProofType, completeDraft([
            'period_type' => PeriodType::Weekly->value,
        ]));

        wizardTaps(ConversationState::AwaitingPeriodType, PeriodType::Daily->value);

        // An inline keyboard outlives the message it came on, so nothing stops a user
        // scrolling up and tapping "Daily" three questions later. That has to be a
        // no-op, not a silent overwrite of an answer already given.
        expect(liveFlow()?->state)->toBe(ConversationState::AwaitingProofType)
            ->and(flowAnswers()['period_type'])->toBe(PeriodType::Weekly->value)
            ->and(soleBotMessage()['text'])
            ->toContain(botCopy('bot.wizard.stale_step'))
            ->toContain(botCopy('bot.wizard.awaiting_proof_type.prompt'));
    });

    it('refuses a value that was never offered', function (ConversationState $state, string $value) {
        flowSittingAt($state, completeDraft());

        wizardTaps($state, $value);

        // `callback_data` is a string a client sent us. Every value is re-checked
        // against what the keyboard actually offered rather than trusted.
        expect(liveFlow()?->state)->toBe($state)
            ->and(soleBotMessage()['text'])->toContain(botCopy("bot.wizard.{$state->value}.error", wizardLimits()));
    })->with([
        'a period we do not have' => [ConversationState::AwaitingPeriodType, 'fortnightly'],
        'a timezone off the list' => [ConversationState::AwaitingTimezone, 'Mars/Olympus'],
        'a proof type we do not have' => [ConversationState::AwaitingProofType, 'video'],
        'a visibility we do not have' => [ConversationState::AwaitingVisibility, 'friends_only'],
    ]);

    it('treats a button with nothing to read as stale', function (string $data) {
        flowSittingAt(ConversationState::AwaitingPeriodType, completeDraft());

        wizardTapsData($data);

        expect(liveFlow()?->state)->toBe(ConversationState::AwaitingPeriodType)
            ->and(soleBotMessage()['text'])->toContain(botCopy('bot.fallback.stale_button'));
    })->with([
        'no step and no value' => ['wz'],
        'a step but no value' => ['wz:awaiting_period_type'],
        'a step we have retired' => ['wz:awaiting_something_else:daily'],
    ]);

    it('says a button is no longer live when there is no flow to feed', function () {
        wizardTaps(ConversationState::AwaitingPeriodType, PeriodType::Daily->value);

        expect(liveFlow())->toBeNull()
            ->and(soleBotMessage()['text'])->toBe(botCopy('bot.fallback.stale_button'));
    });

    it('will not feed a tap into a flow that has lapsed', function () {
        lapsedFlowAt(ConversationState::AwaitingPeriodType);

        wizardTaps(ConversationState::AwaitingPeriodType, PeriodType::Daily->value);

        expect(flowAnswers())->toBe([])
            ->and(soleBotMessage()['text'])->toBe(botCopy('bot.fallback.stale_button'));
    });

    it('stops the spinner before it does the work', function () {
        flowSittingAt(ConversationState::AwaitingPeriodType, ['title' => 'Read every day']);

        wizardChooses(PeriodType::Daily->value);

        // `callback_query_id` expires, so acknowledging after the work would leave a
        // button spinning forever on any retry of a slow update.
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'answerCallbackQuery'));
    });

    it('drops the flow when Cancel is tapped', function () {
        flowSittingAt(ConversationState::AwaitingCreateConfirmation, completeDraft());

        wizardChooses(CreateChallengeWizard::CANCEL);

        expect(liveFlow())->toBeNull()
            ->and(Challenge::query()->count())->toBe(0)
            ->and(soleBotMessage()['text'])->toBe(botCopy('bot.wizard.cancelled'));
    });
});

describe('the confirmation step', function () {
    it('reads the draft back before anything is created', function () {
        flowSittingAt(ConversationState::AwaitingVisibility, completeDraft());

        wizardChooses(ChallengeVisibility::Public->value);

        expect(soleBotMessage()['text'])
            ->toContain('Read every day')
            ->toContain('Asia/Tehran')
            ->toContain(botCopy(PeriodType::Daily->translationKey()))
            ->toContain(botCopy(ProofType::Button->translationKey()))
            ->toContain(botCopy(ChallengeVisibility::Public->translationKey()))
            ->and(lastOfferedValues())->toBe([CreateChallengeWizard::CONFIRM, CreateChallengeWizard::CANCEL])
            ->and(Challenge::query()->count())->toBe(0);
    });

    it('says a description is absent rather than leaving a gap', function () {
        flowSittingAt(ConversationState::AwaitingCreateConfirmation, completeDraft(['description' => null]));

        wizardTypes('anything');

        expect(soleBotMessage()['text'])->toContain(botCopy('bot.wizard.no_description'));
    });

    it('creates the challenge, spends the slot and lays out the timeline', function () {
        flowSittingAt(ConversationState::AwaitingCreateConfirmation, completeDraft([
            'total_periods' => 30,
            'visibility' => ChallengeVisibility::Public->value,
        ]));

        wizardChooses(CreateChallengeWizard::CONFIRM);

        $challenge = Challenge::query()->sole();

        expect($challenge->creator_id)->toBe($this->creator->getKey())
            ->and($challenge->title)->toBe('Read every day')
            ->and($challenge->period_type)->toBe(PeriodType::Daily)
            ->and($challenge->timezone)->toBe('Asia/Tehran')
            ->and($challenge->total_periods)->toBe(30)
            ->and($challenge->visibility)->toBe(ChallengeVisibility::Public)
            ->and($challenge->status)->toBe(ChallengeStatus::Scheduled)
            // Proofs are private unless the creator says otherwise, and the wizard
            // never asks — so a new challenge cannot leak one by default.
            ->and($challenge->proof_is_public)->toBeFalse()
            ->and($challenge->periods()->count())->toBe(30)
            ->and($this->creator->entitlements()->whereNotNull('consumed_at')->count())->toBe(1)
            ->and(liveFlow())->toBeNull();
    });

    it('starts the first period at midnight in the challenge’s timezone', function () {
        flowSittingAt(ConversationState::AwaitingCreateConfirmation, completeDraft([
            'timezone' => 'Asia/Tehran',
            'start_date' => '2099-06-01',
        ]));

        wizardChooses(CreateChallengeWizard::CONFIRM);

        // Stored UTC, computed in Tehran: midnight on 1 June is the evening before in
        // UTC. Writing the local wall clock into a UTC column is the bug this asserts
        // against.
        expect(Challenge::query()->sole()->starts_at->toIso8601String())
            ->toBe(CarbonImmutable::create(2099, 6, 1, 0, 0, 0, 'Asia/Tehran')->utc()->toIso8601String());
    });

    it('asks the channel to announce a public challenge and says so', function () {
        flowSittingAt(ConversationState::AwaitingCreateConfirmation, completeDraft([
            'visibility' => ChallengeVisibility::Public->value,
        ]));

        wizardChooses(CreateChallengeWizard::CONFIRM);

        Bus::assertDispatched(AnnounceChallenge::class);

        expect(soleBotMessage()['text'])
            ->toContain(botCopy('bot.wizard.created', ['title' => 'Read every day']))
            ->toContain(botCopy('bot.wizard.created_public'));
    });

    it('keeps an invite-only challenge off the channel', function () {
        flowSittingAt(ConversationState::AwaitingCreateConfirmation, completeDraft([
            'visibility' => ChallengeVisibility::InviteOnly->value,
        ]));

        wizardChooses(CreateChallengeWizard::CONFIRM);

        Bus::assertNotDispatched(AnnounceChallenge::class);

        expect(soleBotMessage()['text'])->toContain(botCopy('bot.wizard.created_private'));
    });

    it('checks the gate again at the moment of creation, not only at /create', function () {
        // Membership can lapse inside one flow — the questions take minutes. The
        // privileged act is creating, so that is where the check has to be.
        $this->settings->set(SettingKey::ChannelVerificationTtlMinutes, 0);
        Http::fake(['*getChatMember*' => Http::response(['ok' => true, 'result' => ['status' => 'left']])]);

        flowSittingAt(ConversationState::AwaitingCreateConfirmation, completeDraft());

        wizardChooses(CreateChallengeWizard::CONFIRM);

        expect(Challenge::query()->count())->toBe(0)
            // The flow survives, so they can join the channel and tap Create again
            // rather than answer ten questions a second time.
            ->and(liveFlow()?->state)->toBe(ConversationState::AwaitingCreateConfirmation)
            ->and(soleBotMessage()['text'])->toContain(botCopy('bot.gate.blocked', ['channel' => '@challenges']));
    });

    it('refuses when the slot was spent elsewhere while the flow was open', function () {
        flowSittingAt(ConversationState::AwaitingCreateConfirmation, completeDraft());

        $elsewhere = Challenge::factory()->create(['creator_id' => $this->creator->getKey()]);
        app(ConsumeEntitlement::class)->handle($this->creator, EntitlementType::CreateSlot, $elsewhere);

        wizardChooses(CreateChallengeWizard::CONFIRM);

        // The check at `/create` was advisory; this one is inside the transaction, so
        // the half-written challenge rolls back rather than being created for free.
        expect(Challenge::query()->count())->toBe(1)
            ->and(liveFlow())->toBeNull()
            ->and(soleBotMessage()['text'])->toContain(botCopy('bot.wizard.no_slot'));
    });

    it('drops an incomplete draft rather than guessing at the gaps', function () {
        // Reachable when a deploy adds a question to a flow already in progress.
        flowSittingAt(ConversationState::AwaitingCreateConfirmation, ['title' => 'Read every day']);

        wizardChooses(CreateChallengeWizard::CONFIRM);

        expect(Challenge::query()->count())->toBe(0)
            ->and(liveFlow())->toBeNull()
            ->and(soleBotMessage()['text'])->toBe(botCopy('bot.wizard.incomplete'));
    });

    it('ignores a value at confirmation that is neither Create nor Cancel', function () {
        flowSittingAt(ConversationState::AwaitingCreateConfirmation, completeDraft());

        wizardTaps(ConversationState::AwaitingCreateConfirmation, 'maybe');

        expect(Challenge::query()->count())->toBe(0)
            ->and(liveFlow()?->state)->toBe(ConversationState::AwaitingCreateConfirmation)
            ->and(soleBotMessage()['text'])->toContain(botCopy('bot.fallback.stale_button'));
    });
});

describe('the flow end to end', function () {
    it('turns ten answers into one challenge', function () {
        wizardTypes('/create');
        wizardTypes('Read every day');
        wizardTypes('Twenty pages, no excuses.');
        wizardChooses(PeriodType::Custom->value);
        wizardTypes('3');
        wizardChooses('Asia/Tehran');
        wizardChooses(CreateChallengeWizard::TOMORROW);
        wizardTypes('12');
        wizardChooses(ProofType::TextAutogen->value);
        wizardChooses(ChallengeVisibility::InviteOnly->value);
        wizardChooses(CreateChallengeWizard::CONFIRM);

        $challenge = Challenge::query()->sole();

        expect($challenge->title)->toBe('Read every day')
            ->and($challenge->description)->toBe('Twenty pages, no excuses.')
            ->and($challenge->period_type)->toBe(PeriodType::Custom)
            ->and($challenge->custom_period_days)->toBe(3)
            ->and($challenge->timezone)->toBe('Asia/Tehran')
            ->and($challenge->total_periods)->toBe(12)
            ->and($challenge->proof_type)->toBe(ProofType::TextAutogen)
            ->and($challenge->visibility)->toBe(ChallengeVisibility::InviteOnly)
            ->and($challenge->starts_at->setTimezone('Asia/Tehran')->toDateString())
            ->toBe(CarbonImmutable::now('Asia/Tehran')->addDay()->toDateString())
            ->and($challenge->periods()->count())->toBe(12)
            ->and(liveFlow())->toBeNull();

        // Eleven updates, eleven replies. One message per update is a rate-limit rule
        // rather than a tidiness preference.
        expect(botMessages())->toHaveCount(11);
    });

    it('creates one challenge however many times Telegram redelivers the confirming tap', function () {
        flowSittingAt(ConversationState::AwaitingCreateConfirmation, completeDraft());

        $tap = wizardTaps(ConversationState::AwaitingCreateConfirmation, CreateChallengeWizard::CONFIRM);

        // Telegram retries any update it did not get a 2xx for, and the retry carries
        // the same `update_id`. One tap, one challenge, one slot spent, one reply.
        dispatch_sync(new ProcessTelegramUpdate($tap));

        expect(Challenge::query()->count())->toBe(1)
            ->and($this->creator->entitlements()->whereNotNull('consumed_at')->count())->toBe(1)
            ->and(botMessages())->toHaveCount(1);
    });

    it('answers each step in the creator’s own language', function () {
        $this->creator->forceFill(['locale' => 'fa'])->save();

        flowSittingAt(ConversationState::AwaitingChallengeTitle);

        wizardTypes('Read every day');

        expect(soleBotMessage()['text'])
            ->toBe(botCopy('bot.wizard.awaiting_challenge_description.prompt', wizardLimits(), 'fa'));
    });
});

describe('/cancel', function () {
    it('drops whatever flow was open', function () {
        flowSittingAt(ConversationState::AwaitingTotalPeriods, completeDraft());

        wizardTypes('/cancel');

        expect(liveFlow())->toBeNull()
            ->and(soleBotMessage()['text'])->toBe(botCopy('bot.wizard.cancelled'));
    });

    it('clears a lapsed flow too, so the next /create is not a restart', function () {
        lapsedFlowAt(ConversationState::AwaitingTotalPeriods, completeDraft());

        wizardTypes('/cancel');

        expect(BotConversation::query()->count())->toBe(0);
    });

    it('says there was nothing to cancel', function () {
        wizardTypes('/cancel');

        expect(soleBotMessage()['text'])->toBe(botCopy('bot.cancel.nothing_open'));
    });

    it('does not spend a gate check on letting somebody out', function () {
        $this->creator->forceFill(['channel_verified_at' => null])->save();

        flowSittingAt(ConversationState::AwaitingTotalPeriods, completeDraft());

        wizardTypes('/cancel');

        // Refusing to let a non-member out of a wizard would trap them in it, so
        // `/cancel` asks Telegram nothing at all.
        expect(liveFlow())->toBeNull();
        Http::assertSentCount(1);
    });
});
