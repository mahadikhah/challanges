<?php

use App\Actions\Challenges\MaterialiseChallengePeriods;
use App\Actions\Invites\IssueInviteCode;
use App\Enums\SettingKey;
use App\Jobs\Telegram\ProcessTelegramUpdate;
use App\Models\Challenge;
use App\Models\CoinTransaction;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\Settings;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
 * The language question: `/start` will not greet a user until they have answered
 * it, and everything that `/start` owed them arrives the moment they do.
 *
 * The interesting half of this is the *carrying*. An invite is claimed while the
 * arrival is being created — one coin payment, idempotent by `ClaimInvite`'s own
 * reckoning of a brand-new user — and by the time the answer comes back, that
 * update is long gone. So the outcome travels on the buttons, and reading it back
 * must never be able to pay a second time or say something that is not so.
 *
 * Driven whole through `ProcessTelegramUpdate` and the real routers, for the same
 * reason as every other bot test: the failures worth catching here are wiring
 * failures, and no unit test of any one class sees them.
 */

beforeEach(function () {
    Http::preventStrayRequests();

    config(['services.telegram.bot_token' => '123456:TEST-TOKEN']);

    $this->settings = app(Settings::class);
    $this->settings->set(SettingKey::RequiredChannel, '@challenges');
});

/**
 * Telegram's answers for one arrival: a membership status, and accepted sends.
 *
 * One `fake()` call because `Http::fake()` appends and the first matching pattern
 * wins. A tap is acknowledged as well as answered, so `answerCallbackQuery` is
 * stubbed alongside.
 */
function asksLanguageServes(string $status): void
{
    Http::fake([
        '*getChatMember*' => Http::response(['ok' => true, 'result' => ['status' => $status]]),
        '*answerCallbackQuery*' => Http::response(['ok' => true, 'result' => true]),
        '*sendMessage*' => Http::response(['ok' => true, 'result' => ['message_id' => 11]]),
    ]);
}

/**
 * Telegram's answers when membership changes between two arrivals.
 *
 * @param  list<string>  $statuses  one per `getChatMember`, in order
 */
function asksLanguageServesInTurn(array $statuses): void
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
 * One `/start`, through the whole inbound path.
 *
 * @param  array<string, mixed>  $from  merged over Telegram's `from` object
 */
function asksLanguageSays(string $text = '/start', array $from = []): void
{
    $update = TelegramUpdate::factory()
        ->messageFrom(array_replace(['id' => 777_100_1, 'first_name' => 'Sara'], $from), $text)
        ->create();

    dispatch_sync(new ProcessTelegramUpdate($update));
}

/**
 * One language-button tap, by the payload on the button itself.
 *
 * Takes the raw `callback_data` rather than a locale so a test can replay the
 * exact bytes the bot sent — which is the only way to prove that answering twice
 * cannot pay twice.
 */
function asksLanguageTaps(string $data, int $platformUserId = 777_100_1): void
{
    $update = TelegramUpdate::factory()
        ->callbackQueryFrom(['id' => $platformUserId, 'first_name' => 'Sara'], $data)
        ->create();

    dispatch_sync(new ProcessTelegramUpdate($update));
}

/**
 * The user the arrivals came from.
 */
function asksLanguageUser(): User
{
    return User::query()->where('platform_user_id', 777_100_1)->sole();
}

/**
 * The `callback_data` on every button of a message, flattened.
 *
 * @return list<string>
 */
function asksLanguageButtons(array $message): array
{
    return array_map(
        static fn (array $button): string => $button['callback_data'],
        array_merge([], ...keyboardOn($message)),
    );
}

/**
 * How many entries the ledger holds for a user.
 */
function asksLanguageLedgerEntries(User $user): int
{
    return CoinTransaction::query()->where('user_id', $user->getKey())->count();
}

/**
 * A challenge with a live join link.
 */
function asksLanguageJoinable(string $token = 'abcdefghjkmn'): Challenge
{
    $challenge = Challenge::factory()->active()
        ->create(['join_token' => $token, 'title' => 'Read every day', 'total_periods' => 30]);

    app(MaterialiseChallengePeriods::class)->handle($challenge);

    return $challenge;
}

it('asks a user who has never chosen instead of greeting them', function () {
    asksLanguageServes('member');

    asksLanguageSays('/start');

    $message = soleBotMessage();

    // The prompt, with both languages on it — and nothing of the greeting, which
    // is what "instead of the welcome" means.
    expect($message['text'])->toBe(botCopy('bot.language.prompt'))
        ->and(asksLanguageButtons($message))->toBe(['lg:en:start:first', 'lg:fa:start:first'])
        ->and(asksLanguageUser()->locale)->toBeNull();
});

it('sets the locale and greets them in it once they answer', function () {
    asksLanguageServes('member');

    asksLanguageSays('/start');
    answerLanguageQuestion('fa');

    expect(asksLanguageUser()->locale)->toBe('fa')
        ->and(latestBotMessage(2)['text'])->toContain(botCopy('bot.start.welcome', [
            'app' => botCopy('common.app_name', [], 'fa'),
            'name' => 'Sara',
        ], 'fa'));
});

it('answers an ordinary /language tap with the confirmation, not a greeting', function () {
    asksLanguageServes('member');

    asksLanguageSays('/language');
    answerLanguageQuestion('fa');

    // Nothing was owed on this path: they asked the question themselves, so the
    // tap's only job is to answer it.
    expect(asksLanguageUser()->locale)->toBe('fa')
        ->and(latestBotMessage(2)['text'])->toBe(botCopy('bot.language.set', ['language' => 'فارسی'], 'fa'));
});

describe('carrying an invite across the question', function () {
    it('credits the inviter exactly once and says so after the pick', function () {
        $inviter = User::factory()->telegram(555_100_1)->create();
        $invite = app(IssueInviteCode::class)->handle($inviter);

        asksLanguageServes('member');

        asksLanguageSays('/start '.$invite->code);

        // Asked first, and told nothing yet: the note is `/start`'s to deliver,
        // and `/start` has not finished.
        expect(soleBotMessage()['text'])->toBe(botCopy('bot.language.prompt'))
            ->and($invite->refresh()->credited_at)->not->toBeNull();

        answerLanguageQuestion();

        expect(asksLanguageLedgerEntries($inviter))->toBe(1)
            ->and($invite->refresh()->invited_user_id)->toBe(asksLanguageUser()->getKey())
            ->and(latestBotMessage(2)['text'])->toContain(botCopy('bot.invite.credited', [
                'name' => $inviter->first_name ?? $inviter->name,
            ]));
    });

    it('cannot pay twice when the same button is tapped again', function () {
        $inviter = User::factory()->telegram(555_100_2)->create();
        $invite = app(IssueInviteCode::class)->handle($inviter);

        asksLanguageServes('member');

        asksLanguageSays('/start '.$invite->code);

        $button = asksLanguageButtons(soleBotMessage())[0];

        asksLanguageTaps($button);

        // The same bytes, replayed. The carry names an invite; it does not run
        // the claim again, and the claim is the only thing that pays.
        asksLanguageTaps($button);

        expect(asksLanguageLedgerEntries($inviter))->toBe(1)
            ->and($invite->refresh()->invited_user_id)->toBe(asksLanguageUser()->getKey());

        // The second tap still names a button that was genuinely ours, so it is
        // answered — with the same greeting, and no second coin.
        expect(latestBotMessage(3)['text'])->toContain(botCopy('bot.invite.credited', [
            'name' => $inviter->first_name ?? $inviter->name,
        ]));
    });

    it('says nothing about an invite that is not the tapper’s own', function () {
        $inviter = User::factory()->telegram(555_100_3)->create();
        $invite = app(IssueInviteCode::class)->handle($inviter);
        User::factory()->telegram(555_100_4)->create();

        $invite->forceFill(['invited_user_id' => User::query()->where('platform_user_id', 555_100_4)->sole()->getKey()])->save();

        asksLanguageServes('member');

        asksLanguageSays('/start');

        // Somebody else's invite id, typed into a payload we minted ourselves.
        asksLanguageTaps('lg:en:start:first:invite:'.$invite->getKey());

        expect(asksLanguageUser()->locale)->toBe('en')
            ->and(lastBotReply()['text'])
            ->not->toContain(botCopy('bot.invite.credited', ['name' => $inviter->first_name ?? $inviter->name]))
            ->and(lastBotReply()['text'])->toContain(botCopy('bot.start.welcome', [
                'app' => botCopy('common.app_name'),
                'name' => 'Sara',
            ]));
    });
});

describe('carrying a join payload across the question', function () {
    it('shows the preview after the pick', function () {
        $challenge = asksLanguageJoinable();

        asksLanguageServes('member');

        asksLanguageSays('/start '.$challenge->joinPayload());

        expect(asksLanguageButtons(soleBotMessage()))
            ->toBe(['lg:en:start:first:join:'.$challenge->joinPayload(), 'lg:fa:start:first:join:'.$challenge->joinPayload()]);

        answerLanguageQuestion();

        expect(latestBotMessage(2)['text'])
            ->toContain(botCopy('bot.join.preview_headline', ['title' => 'Read every day']))
            ->and(asksLanguageUser()->locale)->toBe('en');
    });

    it('says the link is dead when it named no challenge', function () {
        asksLanguageServes('member');

        asksLanguageSays('/start j_notarealtoken');

        answerLanguageQuestion();

        expect(latestBotMessage(2)['text'])->toBe(botCopy('bot.join.not_found'));
    });
});

describe('the order the gate and the question come in', function () {
    it('sends the gate prompt first and asks only once they are in', function () {
        asksLanguageServesInTurn(['left', 'member']);

        asksLanguageSays('/start');
        asksLanguageSays('/start');

        // Membership is asked about afresh on every `/start` — but until it is
        // confirmed, the only thing they are owed is the way in.
        expect(botMessages()[0]['text'])->toContain(botCopy('bot.gate.blocked', ['channel' => '@challenges']))
            ->and(latestBotMessage(2)['text'])->toBe(botCopy('bot.language.prompt'));

        answerLanguageQuestion();

        expect(latestBotMessage(3)['text'])->toContain(botCopy('bot.start.welcome_back', ['name' => 'Sara']))
            ->and(asksLanguageUser()->locale)->toBe('en');
    });

    it('asks nobody who is still behind the gate', function () {
        asksLanguageServes('left');

        asksLanguageSays('/start');

        expect(soleBotMessage()['text'])->toContain(botCopy('bot.gate.blocked', ['channel' => '@challenges']))
            ->and(asksLanguageUser()->locale)->toBeNull();
    });
});

describe('a user who has already chosen', function () {
    it('is greeted as before, with no question and only the next move offered', function () {
        User::factory()->telegram(777_100_1)->preferring('en')->create();

        asksLanguageServes('member');

        asksLanguageSays('/start');

        $message = soleBotMessage();

        // The buttons are the greeting's own, not the language question's: one to
        // create, and none for the language, which this user has already settled.
        // No `cancel` either — nothing is open to cancel.
        expect($message['text'])->toContain(botCopy('bot.start.welcome_back', ['name' => 'Sara']))
            ->and($message['text'])->not->toContain(botCopy('bot.language.prompt'))
            ->and(keyboardOn($message))->toBe([[commandButton('en', 'create')]]);
    });

    it('still gets the invite note on the same message', function () {
        $inviter = User::factory()->telegram(555_100_5)->create();
        $invite = app(IssueInviteCode::class)->handle($inviter);

        // No locale: the invitee is brand-new, so `ClaimInvite` pays — and the
        // note waits for the answer rather than being dropped.
        asksLanguageServes('member');

        asksLanguageSays('/start '.$invite->code);

        expect(asksLanguageUser()->locale)->toBeNull()
            ->and(asksLanguageLedgerEntries($inviter))->toBe(1);

        answerLanguageQuestion();

        expect(latestBotMessage(2)['text'])->toContain(botCopy('bot.invite.credited', [
            'name' => $inviter->first_name ?? $inviter->name,
        ]));
    });
});

describe('a payload that names something we cannot serve', function () {
    it('refuses a carried button naming an unsupported locale', function () {
        asksLanguageServes('member');

        asksLanguageSays('/start');

        asksLanguageTaps('lg:fr:start:first');

        expect(lastBotReply()['text'])->toBe(botCopy('bot.fallback.stale_button'))
            ->and(asksLanguageUser()->locale)->toBeNull();
    });

    it('refuses a carry whose arrival marker is not ours', function () {
        asksLanguageServes('member');

        asksLanguageSays('/start');

        // A payload that names a locale we serve, so the only thing left to
        // refuse it on is what it claims `/start` owed.
        asksLanguageTaps('lg:en:elsewhere:first:invite:1');

        expect(asksLanguageUser()->locale)->toBe('en')
            ->and(lastBotReply()['text'])->toBe(botCopy('bot.language.set', ['language' => 'English']));
    });

    it('ignores a join payload that is not one', function () {
        asksLanguageServes('member');

        asksLanguageSays('/start');

        // A carried join segment that would never have been minted: the greeting
        // is what they get, rather than a "that link is no longer valid" about a
        // link they never followed.
        asksLanguageTaps('lg:en:start:first:join:nonsense');

        expect(lastBotReply()['text'])->toContain(botCopy('bot.start.welcome', [
            'app' => botCopy('common.app_name'),
            'name' => 'Sara',
        ]));
    });
});

describe('the shape of the carry', function () {
    it('fits Telegram’s callback limit even with the longest refusal we can carry', function () {
        $first = User::factory()->telegram(555_100_6)->create();
        $second = User::factory()->telegram(555_100_7)->create();

        asksLanguageServes('member');

        asksLanguageSays('/start '.app(IssueInviteCode::class)->handle($first)->code);
        asksLanguageSays('/start '.app(IssueInviteCode::class)->handle($second)->code);

        // `invitee_already_attributed` is the longest reason a refusal can put on
        // a button, and `BotCallback::encode()` throws rather than truncating — so
        // a prompt that went out at all is the proof that it fits.
        $buttons = asksLanguageButtons(lastBotReply());

        expect($buttons)->toHaveCount(2)
            ->and($buttons[0])->toContain(':refused:invitee_already_attributed')
            ->and(strlen($buttons[0]))->toBeLessThanOrEqual(64);

        answerLanguageQuestion();

        expect(latestBotMessage(3)['text'])->toContain(botCopy('bot.invite.refused.invitee_already_attributed'));
    });
});
