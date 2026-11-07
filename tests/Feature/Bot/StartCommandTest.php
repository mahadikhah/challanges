<?php

use App\Actions\Challenges\MaterialiseChallengePeriods;
use App\Actions\Invites\IssueInviteCode;
use App\Enums\CoinTransactionReason;
use App\Enums\EntitlementType;
use App\Enums\ParticipantStatus;
use App\Enums\SettingKey;
use App\Jobs\Telegram\ProcessTelegramUpdate;
use App\Models\Challenge;
use App\Models\ChallengeParticipant;
use App\Models\Entitlement;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\CoinLedger;
use App\Services\Settings;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
 * `/start` end to end: a raw update goes in through the queued job, the real
 * `UPDATE_HANDLERS` router and `MessageHandler`, and what comes out is a row in
 * `users`, a decision from the channel gate, an invite settled or refused, and one
 * message on the wire. Testing it whole rather than per class is deliberate — the
 * bug this task exists to avoid is an *ordering* bug between those pieces, which no
 * unit test of any one of them can see.
 *
 * A brand-new user is asked which language they speak before they are greeted, so
 * most of these tests take the extra step through `answerLanguageQuestion()` — the
 * same tap a real user makes. That is the point of the arrangement rather than an
 * inconvenience: the greeting arrives after the answer, in the language given.
 */

beforeEach(function () {
    Http::preventStrayRequests();

    config(['services.telegram.bot_token' => '123456:TEST-TOKEN']);

    $this->settings = app(Settings::class);
    $this->settings->set(SettingKey::RequiredChannel, '@challenges');

    $this->issue = app(IssueInviteCode::class);
    $this->ledger = app(CoinLedger::class);
});

/**
 * Telegram's two answers for an arrival: a membership status, and an accepted send.
 *
 * Registered together in one `fake()` call because `Http::fake()` appends and the
 * first matching pattern wins — a second call adding `sendMessage` separately would
 * work, but keeping them together makes the ordering irrelevant. Both patterns are
 * bare-bones wildcards on the method name because `getChatMember` travels as a GET
 * and carries its parameters in the URL.
 *
 * `answerCallbackQuery` is here because answering the language question is a tap,
 * and a tap is acknowledged as well as answered.
 */
function telegramAnswers(string $status): void
{
    Http::fake([
        '*getChatMember*' => Http::response(['ok' => true, 'result' => ['status' => $status]]),
        '*answerCallbackQuery*' => Http::response(['ok' => true, 'result' => true]),
        '*sendMessage*' => Http::response(['ok' => true, 'result' => ['message_id' => 11]]),
    ]);
}

/**
 * Telegram's answers when the membership changes mid-test — somebody who joins the
 * channel between two `/start`s.
 *
 * A second `telegramAnswers()` call could not express this: `Http::fake()` appends
 * and the *first* matching pattern wins, so the original stub would keep answering
 * and the change would be invisible.
 *
 * @param  list<string>  $statuses  one per `getChatMember`, in order
 */
function telegramAnswersInTurn(array $statuses): void
{
    Http::fake([
        '*getChatMember*' => Http::sequence(array_map(
            static fn (string $status): PromiseInterface => Http::response(['ok' => true, 'result' => ['status' => $status]]),
            $statuses,
        )),
        '*answerCallbackQuery*' => Http::response(['ok' => true, 'result' => true]),
        '*sendMessage*' => Http::response(['ok' => true, 'result' => ['message_id' => 11]]),
    ]);
}

/**
 * Put one message through the whole inbound path.
 *
 * @param  array<string, mixed>  $from  merged over Telegram's `from` object
 */
function arrivesAtBot(string $text = '/start', array $from = [], string $chatType = 'private'): TelegramUpdate
{
    $factory = TelegramUpdate::factory();

    $update = $chatType === 'private'
        ? $factory->messageFrom(array_replace(['id' => 777_000_3, 'first_name' => 'Sara'], $from), $text)->create()
        : $factory->messageInChat($chatType, $text)->create();

    dispatch_sync(new ProcessTelegramUpdate($update));

    return $update;
}

/*
 * `botMessages()`, `soleBotMessage()`, `latestBotMessage()`, `botCopy()` and
 * `botKeyboard()` read the bot's replies back off the wire. They are shared by every
 * bot test and live in `tests/Pest.php`.
 */

/**
 * Somebody who has already chosen a language and cleared the gate.
 *
 * Pre-created rather than walked through the first-arrival path, because a
 * dashboard test has to have the challenges and the coins in place *before* the
 * greeting renders — and the platform-wide headcount is cached for five minutes,
 * so a greeting sent first would pin the figures at zero.
 */
function greetedUser(int $telegramId = 777_000_3): User
{
    return User::factory()->telegram($telegramId)->preferring('en')->create([
        'channel_verified_at' => now(),
    ]);
}

/**
 * The five figures of the dashboard, at rest.
 *
 * @return array{joined: int, created: int, coins: int, people: int, platform: int}
 */
function greetingFigures(
    int $joined = 0,
    int $created = 0,
    int $coins = 0,
    int $people = 0,
    int $platform = 0,
): array {
    return compact('joined', 'created', 'coins', 'people', 'platform');
}

/**
 * That block as the bot renders it — one paragraph, five lines inside it.
 *
 * Read out of `bot.start.stats.*` in the order the greeting declares them, so a
 * block that gained, lost or reordered a line fails rather than being quietly
 * accommodated. The single `\n`s are the point: `paragraphs()` puts a blank line
 * between its elements, and five paragraphs of dashboard is the wall this
 * arrangement exists to avoid.
 *
 * @param  array{joined: int, created: int, coins: int, people: int, platform: int}  $figures
 */
function statsBlockFor(array $figures, string $locale = 'en'): string
{
    $lines = [];

    foreach ($figures as $key => $count) {
        $lines[] = botCopy("bot.start.stats.{$key}", ['count' => $count], $locale);
    }

    return implode("\n", $lines);
}

/**
 * Somebody standing in a challenge, active unless the test says otherwise.
 */
function greetingStandsIn(User $user, Challenge $challenge): ChallengeParticipant
{
    return ChallengeParticipant::factory()->for($challenge)->for($user)->create([
        'status' => ParticipantStatus::Active,
    ]);
}

describe('a first arrival', function () {
    it('registers, admits and greets them in one message', function () {
        telegramAnswers('member');

        arrivesAtBot('/start');
        answerLanguageQuestion();

        $user = User::query()->where('platform_user_id', 777_000_3)->sole();

        expect($user->hasVerifiedChannel())->toBeTrue()
            ->and(latestBotMessage(2)['text'])->toContain(botCopy('bot.start.welcome', [
                'app' => botCopy('common.app_name'),
                'name' => 'Sara',
            ]));
    });

    it('sends the reply to their own chat and nobody else’s', function () {
        telegramAnswers('member');

        arrivesAtBot('/start');

        // The chat id comes from our `users` row, never from the payload — a crafted
        // update must not be able to redirect one user's private reply.
        expect((int) soleBotMessage()['chat_id'])->toBe(777_000_3);
    });

    it('grants the free baseline the settings declare', function () {
        telegramAnswers('member');

        arrivesAtBot('/start');

        $user = User::query()->where('platform_user_id', 777_000_3)->sole();
        $granted = fn (EntitlementType $type): int => Entitlement::query()
            ->where('user_id', $user->getKey())
            ->where('type', $type)
            ->count();

        expect($granted(EntitlementType::CreateSlot))->toBe($this->settings->integer(SettingKey::FreeCreateSlots))
            ->and($granted(EntitlementType::JoinSlot))->toBe($this->settings->integer(SettingKey::FreeJoinSlots));
    });

    it('tops the baseline up rather than doubling it on a second /start', function () {
        telegramAnswers('member');

        arrivesAtBot('/start');
        arrivesAtBot('/start');

        $user = User::query()->where('platform_user_id', 777_000_3)->sole();

        expect(Entitlement::query()->where('user_id', $user->getKey())->count())
            ->toBe($this->settings->integer(SettingKey::FreeCreateSlots) + $this->settings->integer(SettingKey::FreeJoinSlots));
    });

    it('greets a returning user differently', function () {
        telegramAnswers('member');

        arrivesAtBot('/start');
        answerLanguageQuestion();
        arrivesAtBot('/start');

        expect(latestBotMessage(3)['text'])->toContain(botCopy('bot.start.welcome_back', ['name' => 'Sara']));
    });

    it('creates exactly one user however many times they say hello', function () {
        telegramAnswers('member');

        arrivesAtBot('/start');
        arrivesAtBot('/start');

        expect(User::query()->count())->toBe(1);
    });
});

describe('the channel gate', function () {
    it('blocks a non-member with a way to join and a way back', function () {
        telegramAnswers('left');

        arrivesAtBot('/start');

        $reply = soleBotMessage();

        // Two buttons, one row: the join link leaves Telegram, so the way to say
        // "I am back" travels next to it rather than on a row of its own.
        expect($reply['text'])->toContain(botCopy('bot.gate.blocked', ['channel' => '@challenges']))
            ->and($reply['text'])->toContain(botCopy('bot.gate.then_start_again'))
            ->and(botKeyboard())->toBe([[commandButton('en', 'start'), [
                'text' => botCopy('bot.gate.join_button'),
                'url' => 'https://t.me/challenges',
            ]]]);
    });

    it('hands a blocked user nothing', function () {
        telegramAnswers('kicked');

        arrivesAtBot('/start');

        $user = User::query()->where('platform_user_id', 777_000_3)->sole();

        // The row has to exist — that is how the invite above them got paid — but
        // membership and the free allowance both wait until they have joined.
        expect($user->channel_verified_at)->toBeNull()
            ->and($user->hasVerifiedChannel())->toBeFalse()
            ->and(Entitlement::query()->where('user_id', $user->getKey())->count())->toBe(0);
    });

    it('asks Telegram again on every /start rather than trusting the last no', function () {
        telegramAnswers('left');

        arrivesAtBot('/start');
        arrivesAtBot('/start');

        // A user who sends `/start` twice has almost certainly just tapped the join
        // button; a cached "no" would send them round the loop for the TTL.
        Http::assertSentCount(4);
    });

    it('lets them in the moment they have joined', function () {
        telegramAnswersInTurn(['left', 'member']);

        arrivesAtBot('/start');
        arrivesAtBot('/start');
        answerLanguageQuestion();

        expect(User::query()->where('platform_user_id', 777_000_3)->sole()->hasVerifiedChannel())->toBeTrue()
            ->and(latestBotMessage(3)['text'])->toContain(botCopy('bot.start.welcome_back', ['name' => 'Sara']));
    });

    it('offers only the way back when the channel has no public link', function () {
        $this->settings->set(SettingKey::RequiredChannel, '-1001234567890');
        telegramAnswers('left');

        arrivesAtBot('/start');

        // No `https://t.me/-100…` exists, so offering a join button would be a
        // dead one. The retry button is not decoration around it — it is the only
        // move there is, short of asking an admin for the link by hand.
        expect(soleBotMessage()['text'])->toContain(botCopy('bot.gate.blocked_without_link'))
            ->and(botKeyboard())->toBe([[commandButton('en', 'start')]]);
    });
});

describe('invite attribution', function () {
    it('pays the inviter when the arrival is brand-new', function () {
        $inviter = User::factory()->telegram(555_000_1)->create();
        $invite = $this->issue->handle($inviter);
        telegramAnswers('member');

        arrivesAtBot("/start {$invite->code}");
        answerLanguageQuestion();

        $invitee = User::query()->where('platform_user_id', 777_000_3)->sole();

        expect($invite->refresh()->wasPaid())->toBeTrue()
            ->and($invite->invited_user_id)->toBe($invitee->getKey())
            ->and($invitee->referred_by_user_id)->toBe($inviter->getKey())
            ->and($this->ledger->balanceFor($inviter))
            ->toBe($this->settings->integer(SettingKey::InviteCoinReward))
            ->and(latestBotMessage(2)['text'])
            ->toContain(botCopy('bot.invite.credited', ['name' => $inviter->first_name ?? $inviter->name]));
    });

    it('pays the inviter even when the arrival is then blocked at the gate', function () {
        // The ordering assertion this whole file exists for. Block first and the
        // credit is not deferred, it is lost: the user joins the channel, sends
        // `/start` again, and by then their row exists so nobody is brand-new any
        // more. The reward is for bringing a person to the bot — which has happened.
        $inviter = User::factory()->telegram(555_000_2)->create();
        $invite = $this->issue->handle($inviter);
        telegramAnswers('left');

        arrivesAtBot("/start {$invite->code}");

        expect($invite->refresh()->wasPaid())->toBeTrue()
            ->and($this->ledger->balanceFor($inviter))
            ->toBe($this->settings->integer(SettingKey::InviteCoinReward))
            // And the blocked user is still told about it, in the same message as
            // the join button.
            ->and(soleBotMessage()['text'])->toContain(botCopy('bot.invite.credited', [
                'name' => $inviter->first_name ?? $inviter->name,
            ]));
    });

    it('pays once however many times Telegram retries the update', function () {
        $inviter = User::factory()->telegram(555_000_3)->create();
        $invite = $this->issue->handle($inviter);
        telegramAnswers('member');

        arrivesAtBot("/start {$invite->code}");
        arrivesAtBot("/start {$invite->code}");

        expect($this->ledger->balanceFor($inviter))
            ->toBe($this->settings->integer(SettingKey::InviteCoinReward));
    });

    it('admits them anyway when the code is refused, and says which way it failed', function () {
        telegramAnswers('member');

        arrivesAtBot('/start no-such-code');
        answerLanguageQuestion();

        $user = User::query()->where('platform_user_id', 777_000_3)->sole();

        expect($user->hasVerifiedChannel())->toBeTrue()
            ->and($user->referred_by_user_id)->toBeNull()
            ->and(latestBotMessage(2)['text'])->toContain(botCopy('bot.invite.refused.not_found'));
    });

    it('refuses somebody redeeming their own link', function () {
        $inviter = User::factory()->telegram(777_000_3)->create();
        $invite = $this->issue->handle($inviter);
        telegramAnswers('member');

        arrivesAtBot("/start {$invite->code}");
        answerLanguageQuestion();

        expect($this->ledger->balanceFor($inviter))->toBe(0)
            ->and(latestBotMessage(2)['text'])->toContain(botCopy('bot.invite.refused.self_invite'));
    });

    it('refuses a link somebody else already used', function () {
        $inviter = User::factory()->telegram(555_000_4)->create();
        $invite = $this->issue->handle($inviter);
        telegramAnswers('member');

        arrivesAtBot("/start {$invite->code}", ['id' => 888_000_1, 'first_name' => 'Reza']);
        arrivesAtBot("/start {$invite->code}");
        answerLanguageQuestion();

        expect($this->ledger->balanceFor($inviter))
            ->toBe($this->settings->integer(SettingKey::InviteCoinReward))
            ->and(latestBotMessage(3)['text'])->toContain(botCopy('bot.invite.refused.already_claimed'));
    });

    it('refuses a second inviter for somebody already attributed', function () {
        $first = User::factory()->telegram(555_000_5)->create();
        $second = User::factory()->telegram(555_000_6)->create();
        telegramAnswers('member');

        arrivesAtBot('/start '.$this->issue->handle($first)->code);
        arrivesAtBot('/start '.$this->issue->handle($second)->code);
        answerLanguageQuestion();

        // One person, one inviter, for life — otherwise a code passed around between
        // friends mints coins.
        expect($this->ledger->balanceFor($second))->toBe(0)
            ->and(latestBotMessage(3)['text'])->toContain(botCopy('bot.invite.refused.invitee_already_attributed'));
    });

    it('matches a code the phone keyboard capitalised', function () {
        $inviter = User::factory()->telegram(555_000_7)->create();
        $invite = $this->issue->handle($inviter);
        telegramAnswers('member');

        arrivesAtBot('/start '.strtoupper($invite->code));

        expect($invite->refresh()->invited_user_id)->not->toBeNull();
    });

    it('says nothing about invites when no code was offered', function () {
        telegramAnswers('member');

        arrivesAtBot('/start');
        answerLanguageQuestion();

        expect(latestBotMessage(2)['text'])
            ->not->toContain(botCopy('bot.invite.refused.not_found'))
            ->and(latestBotMessage(2)['text'])->toContain(botCopy('bot.start.next_steps'));
    });
});

describe('what the bot refuses to act on', function () {
    it('ignores a message from anywhere but a private chat', function (string $chatType) {
        telegramAnswers('member');

        arrivesAtBot('/start', [], $chatType);

        // A group or channel message has no single identifiable user, and replying
        // into one is how private state leaks.
        expect(User::query()->count())->toBe(0);
        Http::assertNothingSent();
    })->with(['group', 'supergroup', 'channel']);

    it('ignores another bot talking to it', function () {
        telegramAnswers('member');

        arrivesAtBot('/start', ['id' => 999_000_1, 'is_bot' => true]);

        expect(User::query()->count())->toBe(0);
        Http::assertNothingSent();
    });

    it('still marks an ignored update processed, so it is not retried forever', function () {
        telegramAnswers('member');

        $update = arrivesAtBot('/start', [], 'group');

        // Ignoring is a decision, not a failure. Leaving `processed_at` null would
        // have Telegram redeliver a message we will never act on.
        expect($update->refresh()->processed_at)->not->toBeNull();
    });
});

describe('anything that is not a command we know', function () {
    it('offers the two moves that work from anywhere', function (string $text) {
        telegramAnswers('member');

        arrivesAtBot($text);

        // Two buttons, not a sentence naming them. The words under the buttons
        // are the command menu's own, so a user who taps one has also learned
        // which command it is — and `/create` leads because a message the bot
        // could not read most likely meant "get on with a challenge".
        expect(soleBotMessage()['text'])->toBe(botCopy('bot.fallback.unknown'))
            ->and(botKeyboard())->toBe([[commandButton('en', 'create'), commandButton('en', 'start')]]);
    })->with([
        'an unknown command' => ['/teleport'],
        'free text' => ['hello there'],
        'a bare slash' => ['/'],
    ]);

    it('does not spend a gate check on it', function () {
        telegramAnswers('member');

        arrivesAtBot('/teleport');

        // The gate is invoked by the commands that need it, not blanket per message:
        // at ~30 Bot API calls a second, one `getChatMember` per stray message is a
        // budget reminder fan-out cannot spare.
        Http::assertSentCount(1);
    });

    it('handles a photo with no text at all', function () {
        telegramAnswers('member');

        $update = TelegramUpdate::factory()->create(['payload' => [
            'message' => [
                'message_id' => 5,
                'from' => ['id' => 777_000_3, 'is_bot' => false, 'first_name' => 'Sara'],
                'chat' => ['id' => 777_000_3, 'type' => 'private'],
                'photo' => [['file_id' => 'abc']],
            ],
        ]]);

        dispatch_sync(new ProcessTelegramUpdate($update));

        // A photo is not a command and not a wizard answer, so it lands on the
        // same dead end as free text — buttons included. That matters more here
        // than anywhere: a user who sends a photo has visibly *tried* something.
        expect(soleBotMessage()['text'])->toBe(botCopy('bot.fallback.unknown'))
            ->and(botKeyboard())->toBe([[commandButton('en', 'create'), commandButton('en', 'start')]]);
    });

    it('reads a command addressed to the bot by name', function () {
        telegramAnswers('member');

        arrivesAtBot('/start@challenges_bot');
        answerLanguageQuestion();

        expect(latestBotMessage(2)['text'])->toContain(botCopy('bot.start.welcome', [
            'app' => botCopy('common.app_name'),
            'name' => 'Sara',
        ]));
    });
});

describe('the recipient’s language', function () {
    it('asks in the client’s language, then greets in the one that is picked', function () {
        telegramAnswers('member');

        arrivesAtBot('/start', ['language_code' => 'fa', 'first_name' => 'سارا']);

        // The question is the one line that cannot be sent in its own answer, so it
        // resolves in the only language available for them: the one their client
        // reports. Answering it is what makes that guess a choice.
        expect(soleBotMessage()['text'])->toBe(botCopy('bot.language.prompt', [], 'fa'));

        answerLanguageQuestion('fa');

        expect(latestBotMessage(2)['text'])->toContain(botCopy('bot.start.welcome', [
            'app' => botCopy('common.app_name', [], 'fa'),
            'name' => 'سارا',
        ], 'fa'));
    });

    it('replies in the language the user chose, not the one their client reports', function () {
        telegramAnswers('member');

        arrivesAtBot('/start', ['language_code' => 'en']);
        answerLanguageQuestion();
        User::query()->where('platform_user_id', 777_000_3)->update(['locale' => 'fa']);

        arrivesAtBot('/start', ['language_code' => 'en']);

        expect(latestBotMessage(3)['text'])->toContain(botCopy('bot.start.welcome_back', ['name' => 'Sara'], 'fa'));
    });

    it('does not leak one recipient’s locale into the next', function () {
        telegramAnswers('member');

        arrivesAtBot('/start', ['language_code' => 'fa', 'first_name' => 'سارا']);
        arrivesAtBot('/start', ['id' => 888_000_2, 'language_code' => 'en', 'first_name' => 'Reza']);

        // A queue worker serves everybody, so `app()->getLocale()` is whoever was
        // processed last. Every line resolves the locale per recipient instead —
        // including the question each of them was asked.
        expect(latestBotMessage(2)['text'])->toBe(botCopy('bot.language.prompt'));

        answerLanguageQuestion();

        expect(latestBotMessage(3)['text'])->toContain(botCopy('bot.start.welcome', [
            'app' => botCopy('common.app_name'),
            'name' => 'Reza',
        ]));
    });
});

describe('the dashboard the greeting grew into', function () {
    it('shows a brand-new user the zeros, and the two rows of moves', function () {
        telegramAnswers('member');

        arrivesAtBot('/start');
        answerLanguageQuestion();

        $message = latestBotMessage(2);

        // The greeting is delivered by `LanguageCallback` for somebody who has
        // just answered the language question, so this also covers the second
        // caller: the dashboard appears there too, not only on a later `/start`.
        expect($message['text'])->toContain(statsBlockFor(greetingFigures()))
            ->and(keyboardOn($message))->toBe([
                [commandButton('en', 'checkin'), commandButton('en', 'create')],
                [commandButton('en', 'shop'), commandButton('en', 'challenges')],
            ]);
    });

    it('counts what somebody is in, what they made and what they hold', function () {
        telegramAnswers('member');

        $user = greetedUser();
        $friend = User::factory()->telegram(777_010_1)->preferring('en')->create();
        $stranger = User::factory()->telegram(777_010_2)->preferring('en')->create();

        // One they are standing in, with a friend...
        $joined = Challenge::factory()->active()->create(['creator_id' => $friend->getKey()]);
        greetingStandsIn($user, $joined);
        greetingStandsIn($friend, $joined);

        // ...and one they made, with somebody they have never met in it. The
        // creator is not a participant of their own challenge.
        $made = Challenge::factory()->active()->create(['creator_id' => $user->getKey()]);
        greetingStandsIn($stranger, $made);

        $this->ledger->credit($user, 45, CoinTransactionReason::AdminCredit, 'dashboard:credit');

        arrivesAtBot('/start');

        expect(soleBotMessage()['text'])->toContain(statsBlockFor(greetingFigures(
            joined: 1,
            created: 1,
            coins: 45,
            people: 3,
            platform: 3,
        )));
    });

    it('counts the platform separately from the challenges the user is in', function () {
        telegramAnswers('member');

        $user = greetedUser();
        $friend = User::factory()->telegram(777_010_3)->preferring('en')->create();

        $ours = Challenge::factory()->active()->create(['creator_id' => $friend->getKey()]);
        greetingStandsIn($user, $ours);
        greetingStandsIn($friend, $ours);

        // Somebody else's challenge entirely, with people this user will never
        // meet. Without it both "people" lines would read 2, and one query
        // pasted into both figures would pass every assertion here.
        $elsewhere = Challenge::factory()->active()->create([
            'creator_id' => User::factory()->telegram(777_010_4)->preferring('en')->create()->getKey(),
        ]);

        foreach ([777_010_5, 777_010_6] as $id) {
            greetingStandsIn(User::factory()->telegram($id)->preferring('en')->create(), $elsewhere);
        }

        arrivesAtBot('/start');

        expect(soleBotMessage()['text'])->toContain(statsBlockFor(greetingFigures(
            joined: 1,
            people: 2,
            platform: 4,
        )));
    });

    it('says all of it in the reader’s language', function () {
        telegramAnswers('member');

        $user = User::factory()->telegram(777_010_7)->preferring('fa')->create([
            'channel_verified_at' => now(),
        ]);

        $this->ledger->credit($user, 7, CoinTransactionReason::AdminCredit, 'dashboard:farsi');

        arrivesAtBot('/start', ['id' => 777_010_7, 'first_name' => 'سارا']);

        expect(soleBotMessage()['text'])->toContain(statsBlockFor(greetingFigures(coins: 7), 'fa'));
    });

    it('keeps the way out of an open flow, and only then', function () {
        telegramAnswers('member');

        $user = greetedUser();
        Entitlement::factory()->createSlot()->create(['user_id' => $user->getKey()]);

        arrivesAtBot('/create');
        arrivesAtBot('/start');

        // Three rows while the create wizard is asking its questions: the way
        // out is the one button here whose absence would strand somebody, and
        // `/start` is reachable at any time — including mid-wizard, which is
        // exactly when a confused user sends it.
        expect(keyboardOn(latestBotMessage(2)))->toBe([
            [commandButton('en', 'checkin'), commandButton('en', 'create')],
            [commandButton('en', 'shop'), commandButton('en', 'challenges')],
            [commandButton('en', 'cancel')],
        ]);
    });

    it('shows it to nobody who is still behind the gate', function () {
        telegramAnswers('left');

        $user = greetedUser();
        Challenge::factory()->active()->create(['creator_id' => $user->getKey()]);

        arrivesAtBot('/start');

        // The gate answers first and answers alone. A headcount in front of the
        // one thing this user has to do is noise, and "1 challenge you created"
        // to somebody who cannot act on it is worse than noise.
        $reply = soleBotMessage();

        expect($reply['text'])->not->toContain(botCopy('bot.start.stats.created', ['count' => 1]))
            ->and($reply['text'])->not->toContain(botCopy('bot.start.next_steps'))
            ->and(keyboardOn($reply))->toBe([[commandButton('en', 'start'), [
                'text' => botCopy('bot.gate.join_button'),
                'url' => 'https://t.me/challenges',
            ]]]);
    });

    it('stays out of the way of a join link', function () {
        telegramAnswers('member');

        $challenge = Challenge::factory()->active()->create([
            'join_token' => 'dashbrdtoken',
            'title' => 'Read every day',
            'total_periods' => 30,
        ]);

        app(MaterialiseChallengePeriods::class)->handle($challenge);

        arrivesAtBot('/start j_'.$challenge->join_token);
        answerLanguageQuestion();

        $text = latestBotMessage(2)['text'];

        // Joining is the conversation they meant to have. A dashboard in front
        // of it is a screen to scroll past before reaching the button they came
        // for — and the preview already names where they would stand.
        expect($text)->toContain(botCopy('bot.join.preview_headline', ['title' => 'Read every day']))
            ->and($text)->not->toContain(botCopy('bot.start.stats.joined', ['count' => 0]))
            ->and($text)->not->toContain(botCopy('bot.start.next_steps'));
    });
});
