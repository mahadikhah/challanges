<?php

use App\Actions\CheckIns\SettleCheckIn;
use App\Enums\ProofType;
use App\Enums\SettingKey;
use App\Jobs\Telegram\PostCheckInAnnouncement;
use App\Jobs\Telegram\PostDailyLeaderboard;
use App\Jobs\Telegram\ProcessTelegramUpdate;
use App\Models\Challenge;
use App\Models\ChallengeChat;
use App\Models\ChallengeParticipant;
use App\Models\ChallengePeriod;
use App\Models\CheckIn;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
 * Posting into linked chats: check-in announcements and daily leaderboards.
 * The two properties under test throughout are the ones §2.6 calls
 * non-negotiable — a post goes out exactly once per its idempotency key, and a
 * private proof never leaves the platform, whatever a flag somewhere says.
 *
 * `Http::preventStrayRequests()` rather than a catch-all fake, because
 * `Http::fake()` *appends*: a catch-all in `beforeEach` would shadow the
 * per-test stubs. `Queue::fake()` where a test is about what was *queued*;
 * `dispatch_sync` where a test is about what a job *did*.
 */

beforeEach(function () {
    Http::preventStrayRequests();

    config([
        'services.telegram.bot_token' => '123456:TEST-TOKEN',
        'services.telegram.bot_username' => 'ChallengesBot',
    ]);

    $this->settings = app(Settings::class);
    $this->settings->set(SettingKey::RequiredChannel, '@challenges');

    $this->creator = User::factory()
        ->telegram(777_000_1)
        ->preferring('en')
        ->create(['channel_verified_at' => now()]);

    $this->challenge = Challenge::factory()
        ->active()
        ->timeline('2026-08-01', 'UTC', 10)
        ->create(['creator_id' => $this->creator->getKey(), 'title' => 'Morning run']);

    $this->chat = ChallengeChat::factory()
        ->verified()
        ->create([
            'challenge_id' => $this->challenge->getKey(),
            'telegram_chat_id' => -100_4444,
            'post_checkin_announcements' => true,
            'post_daily_leaderboard' => true,
        ]);

    RateLimiter::clear("leaderboard:{$this->chat->getKey()}");
});

/**
 * Telegram answers everything a post needs: the bot's identity, the lazy
 * re-verification questions, and the sends themselves. Fresh verification
 * stamps mean `ensureFresh` spends nothing.
 */
function telegramServesThePost(): void
{
    Http::fake([
        '*getMe' => Http::response(['ok' => true, 'result' => ['id' => 999_000_1, 'is_bot' => true, 'first_name' => 'Challenges Bot']]),
        '*getChatMember*' => Http::response([
            'ok' => true,
            'result' => ['status' => 'administrator', 'can_post_messages' => true, 'user' => ['id' => 999_000_1]],
        ]),
        '*sendMessage*' => Http::response(['ok' => true, 'result' => ['message_id' => 21]]),
        '*sendPhoto*' => Http::response(['ok' => true, 'result' => ['message_id' => 22]]),
    ]);
}

/**
 * A pending check-in on this challenge, ready to be settled.
 */
function pendingCheckIn(int $streak = 0, ?string $proofPath = null): CheckIn
{
    $participant = ChallengeParticipant::factory()
        ->withStreak($streak)
        ->create(['challenge_id' => test()->challenge->getKey()]);

    return CheckIn::factory()
        ->on($participant, firstPeriodOf(test()->challenge))
        ->create(['proof_path' => $proofPath]);
}

/**
 * The challenge's first period, materialised if the factory has not.
 */
function firstPeriodOf(Challenge $challenge): ChallengePeriod
{
    /** @var ChallengePeriod|null $period */
    $period = $challenge->periods()->orderBy('index')->first();

    return $period ?? ChallengePeriod::factory()->for($challenge)->atIndex(0)->create();
}

/**
 * Settle a check-in as approved, through the one place that ever does.
 */
function approve(CheckIn $checkIn): CheckIn
{
    return app(SettleCheckIn::class)->approve($checkIn);
}

/**
 * Every `sendMessage`, as [chat_id => text] pairs.
 *
 * @return array<int, array{chat: string, text: string}>
 */
function postedMessages(): array
{
    return Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'sendMessage'))
        ->map(function (array $call): array {
            $sent = [];
            parse_str((string) $call[0]->body(), $sent);

            return [
                'chat' => (string) ($sent['chat_id'] ?? ''),
                'text' => (string) ($sent['text'] ?? ''),
            ];
        })
        ->values()
        ->all();
}

/**
 * A message typed inside the linked chat, through the whole inbound path.
 */
function typesInTheChat(int $telegramId, string $text): void
{
    $update = TelegramUpdate::factory()->messageFrom(['id' => $telegramId, 'first_name' => 'Sara'], $text)->create();

    $payload = $update->payload;
    $payload['message']['chat'] = ['id' => -100_4444, 'type' => 'group'];
    $update->forceFill(['payload' => $payload])->save();

    dispatch_sync(new ProcessTelegramUpdate($update));
}

describe('check-in announcements', function () {
    it('queues one announcement job per eligible chat when a check-in is approved', function () {
        Queue::fake();

        $checkIn = pendingCheckIn(streak: 3);
        approve($checkIn);

        Queue::assertPushed(PostCheckInAnnouncement::class, 1);
    });

    it('does not queue an announcement for a period closed as missed or frozen', function () {
        Queue::fake();

        $checkIn = pendingCheckIn();
        app(SettleCheckIn::class)->close($checkIn);

        Queue::assertNotPushed(PostCheckInAnnouncement::class);
    });

    it('posts exactly once even when the job is dispatched twice', function () {
        telegramServesThePost();

        $checkIn = approve(pendingCheckIn(streak: 4));

        dispatch_sync(new PostCheckInAnnouncement($this->chat->getKey(), $checkIn->getKey()));
        dispatch_sync(new PostCheckInAnnouncement($this->chat->getKey(), $checkIn->getKey()));

        $toTheChat = array_filter(postedMessages(), fn (array $sent): bool => $sent['chat'] === '-1004444');

        expect($toTheChat)->toHaveCount(1)
            ->and(current($toTheChat)['text'])->toContain('period 1')->toContain('streak: 5')
            ->and($this->chat->posts()->count())->toBe(1);
    });

    it('never attaches an image when the chat has not opted into proof sharing', function () {
        // Faked before the settlement: the queue is sync in tests, so the
        // announcement job runs inside `approve()` itself.
        telegramServesThePost();

        $checkIn = approve(pendingCheckIn(proofPath: 'check-in-proofs/2026/08/28/secret.jpg'));

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'sendPhoto'));

        expect(current(array_filter(postedMessages(), fn (array $sent): bool => $sent['chat'] === '-1004444'))['text'])
            ->not->toContain('secret.jpg');
    });

    it('attaches the approved photo when the chat opted in and the challenge publishes its proofs', function () {
        Storage::fake('local');
        Storage::disk('local')->put('check-in-proofs/2026/08/28/proof.jpg', 'bytes');

        // Before the settlement: the sync queue runs the job inside approve().
        telegramServesThePost();

        $challenge = Challenge::factory()
            ->provenBy(ProofType::ImageApproval, public: true)
            ->active()
            ->timeline('2026-08-01', 'UTC', 10)
            ->create(['creator_id' => $this->creator->getKey()]);

        $chat = ChallengeChat::factory()->verified()->create([
            'challenge_id' => $challenge->getKey(),
            'telegram_chat_id' => -100_5555,
            'post_checkin_announcements' => true,
            'share_proof_media' => true,
        ]);

        $participant = ChallengeParticipant::factory()->withStreak(2)->create(['challenge_id' => $challenge->getKey()]);

        $checkIn = approve(CheckIn::factory()
            ->on($participant, firstPeriodOf($challenge))
            ->create(['proof_path' => 'check-in-proofs/2026/08/28/proof.jpg']));

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'sendPhoto'));
    });

    it('refuses the photo even if share_proof_media were forced on a private-proofs challenge', function () {
        // Defense in depth: the registration guard refuses this combination,
        // so the row is built by hand — exactly what a bypassed guard would
        // leave behind. The job must still not leak the proof.
        Storage::fake('local');
        Storage::disk('local')->put('check-in-proofs/2026/08/28/private.jpg', 'bytes');

        // Before the settlement: the sync queue runs the job inside approve().
        telegramServesThePost();

        $this->chat->forceFill(['share_proof_media' => true])->save();

        expect($this->challenge->proof_is_public)->toBeFalse();

        approve(pendingCheckIn(proofPath: 'check-in-proofs/2026/08/28/private.jpg'));

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'sendPhoto'));
    });

    it('posts into every opted-in chat of the challenge, not just the first', function () {
        telegramServesThePost();

        ChallengeChat::factory()->verified()->create([
            'challenge_id' => $this->challenge->getKey(),
            'telegram_chat_id' => -100_6666,
            'post_checkin_announcements' => true,
        ]);

        $checkIn = approve(pendingCheckIn());

        dispatch_sync(new PostCheckInAnnouncement($this->chat->getKey(), $checkIn->getKey()));

        /** @var ChallengeChat $second */
        $second = ChallengeChat::query()->where('telegram_chat_id', -100_6666)->first();

        dispatch_sync(new PostCheckInAnnouncement($second->getKey(), $checkIn->getKey()));

        $chats = array_column(array_filter(postedMessages(), fn (array $sent): bool => in_array($sent['chat'], ['-1004444', '-1006666'], true)), 'chat');

        expect($chats)->toEqual(['-1004444', '-1006666']);
    });
});

describe('the daily leaderboard', function () {
    it('posts once per chat and day even when the job runs twice', function () {
        telegramServesThePost();

        ChallengeParticipant::factory()->withStreak(7)->create(['challenge_id' => $this->challenge->getKey()]);
        ChallengeParticipant::factory()->withStreak(3)->create(['challenge_id' => $this->challenge->getKey()]);

        $date = now()->toDateString();

        dispatch_sync(new PostDailyLeaderboard($this->chat->getKey(), $date));
        dispatch_sync(new PostDailyLeaderboard($this->chat->getKey(), $date));

        $toTheChat = array_values(array_filter(postedMessages(), fn (array $sent): bool => $sent['chat'] === '-1004444'));

        expect($toTheChat)->toHaveCount(1)
            ->and($toTheChat[0]['text'])->toContain('Morning run')
            ->and($this->chat->posts()->where('post_date', $date)->count())->toBe(1);
    });

    it('shows the top streaks descending, capped by the setting', function () {
        telegramServesThePost();
        $this->settings->set(SettingKey::LeaderboardTopSize, 2);

        foreach ([9, 5, 1] as $streak) {
            ChallengeParticipant::factory()->withStreak($streak)->create([
                'challenge_id' => $this->challenge->getKey(),
                'user_id' => User::factory()->telegram()->create(['first_name' => "Streak{$streak}"])->getKey(),
            ]);
        }

        dispatch_sync(new PostDailyLeaderboard($this->chat->getKey(), now()->toDateString()));

        $text = current(array_filter(postedMessages(), fn (array $sent): bool => $sent['chat'] === '-1004444'))['text'];

        expect($text)->toContain('Streak9')->toContain('Streak5')
            ->not->toContain('Streak1')
            ->and(strpos($text, 'Streak9'))->toBeLessThan(strpos($text, 'Streak5'));
    });

    it('posts nothing when nobody has a streak yet', function () {
        telegramServesThePost();

        ChallengeParticipant::factory()->withStreak(0)->create(['challenge_id' => $this->challenge->getKey()]);

        dispatch_sync(new PostDailyLeaderboard($this->chat->getKey(), now()->toDateString()));

        expect(postedMessages())->toBeEmpty()
            ->and($this->chat->posts()->count())->toBe(0);
    });

    it('deactivates a chat that fails re-verification and posts nothing into it', function () {
        // Stale stamps force the lazy re-check, which finds the bot demoted.
        $this->chat->forceFill([
            'bot_admin_verified_at' => now()->subDays(2),
            'creator_admin_verified_at' => now()->subDays(2),
        ])->save();

        ChallengeParticipant::factory()->withStreak(7)->create(['challenge_id' => $this->challenge->getKey()]);

        Http::fake([
            '*getMe' => Http::response(['ok' => true, 'result' => ['id' => 999_000_1, 'is_bot' => true]]),
            '*getChatMember*' => Http::response([
                'ok' => true,
                'result' => ['status' => 'member', 'user' => ['id' => 999_000_1]],
            ]),
            '*sendMessage*' => Http::response(['ok' => true, 'result' => ['message_id' => 21]]),
        ]);

        dispatch_sync(new PostDailyLeaderboard($this->chat->getKey(), now()->toDateString()));

        expect($this->chat->fresh()?->is_active)->toBeFalse();

        // The only sendMessage is the creator's revocation notice, in their
        // private chat — nothing went into the linked chat.
        expect(postedMessages())->toHaveCount(1)
            ->and(postedMessages()[0]['chat'])->toBe('7770001');
    });
});

describe('the on-demand leaderboard command', function () {
    it('tells a non-admin caller only admins may ask', function () {
        Http::fake([
            '*getChatMember*' => Http::response([
                'ok' => true,
                'result' => ['status' => 'member', 'user' => ['id' => 555_000_1]],
            ]),
            '*sendMessage*' => Http::response(['ok' => true, 'result' => ['message_id' => 31]]),
        ]);

        typesInTheChat(555_000_1, '/leaderboard');

        expect(postedMessages()[0]['chat'])->toBe('-1004444')
            ->and(postedMessages()[0]['text'])->toContain('Only administrators');
    });

    it('answers an admin within the cooldown with the time remaining', function () {
        Http::fake([
            '*getMe' => Http::response(['ok' => true, 'result' => ['id' => 999_000_1, 'is_bot' => true, 'first_name' => 'Challenges Bot']]),
            '*getChatMember*' => Http::response([
                'ok' => true,
                'result' => ['status' => 'administrator', 'can_post_messages' => true, 'user' => ['id' => 555_000_1]],
            ]),
            '*sendMessage*' => Http::response(['ok' => true, 'result' => ['message_id' => 31]]),
        ]);

        ChallengeParticipant::factory()->withStreak(5)->create([
            'challenge_id' => $this->challenge->getKey(),
        ]);

        // Everything runs sync in tests, so the board the first ask queues
        // goes out inside the same dispatch.
        typesInTheChat(555_000_1, '/leaderboard');
        typesInTheChat(555_000_1, '/leaderboard');

        $toTheChat = array_values(array_filter(postedMessages(), fn (array $sent): bool => $sent['chat'] === '-1004444'));

        expect($toTheChat)->toHaveCount(2)
            ->and($toTheChat[0]['text'])->toContain('Morning run')
            ->and($toTheChat[1]['text'])->toContain('Try again in');
    });

    it('posts the board for an admin once no cooldown applies', function () {
        Http::fake([
            '*getMe' => Http::response(['ok' => true, 'result' => ['id' => 999_000_1, 'is_bot' => true, 'first_name' => 'Challenges Bot']]),
            '*getChatMember*' => Http::response([
                'ok' => true,
                'result' => ['status' => 'creator', 'can_post_messages' => true, 'user' => ['id' => 555_000_2]],
            ]),
            '*sendMessage*' => Http::response(['ok' => true, 'result' => ['message_id' => 31]]),
        ]);

        ChallengeParticipant::factory()->withStreak(5)->create([
            'challenge_id' => $this->challenge->getKey(),
        ]);

        RateLimiter::clear("leaderboard:{$this->chat->getKey()}");

        typesInTheChat(555_000_2, '/leaderboard');

        $toTheChat = array_values(array_filter(postedMessages(), fn (array $sent): bool => $sent['chat'] === '-1004444'));

        expect($toTheChat)->toHaveCount(1)
            ->and($toTheChat[0]['text'])->toContain('Morning run');
    });

    it('ignores any other message typed in the chat', function () {
        // No fake beyond a catch-all that would fail the test on a stray
        // send — the assertion is that nothing is asked or said.
        typesInTheChat(555_000_1, 'good morning everyone');

        expect(postedMessages())->toBeEmpty();
    });
});

/*
 * A quantity challenge's linked chat: the board ranks by score and the
 * announcement carries the period's points. Binary is the regression bar —
 * every test above must keep passing untouched, which is why the fixtures
 * here build their own challenge rather than bending the shared one.
 */
describe('a quantity challenge’s chat posts', function () {
    beforeEach(function () {
        $this->challenge = Challenge::factory()
            ->active()
            ->quantity()
            ->timeline('2026-08-01', 'UTC', 10)
            ->create(['creator_id' => $this->creator->getKey(), 'title' => 'Morning run']);

        $this->chat = ChallengeChat::factory()
            ->verified()
            ->create([
                'challenge_id' => $this->challenge->getKey(),
                'telegram_chat_id' => -100_5555,
                'post_checkin_announcements' => true,
                'post_daily_leaderboard' => true,
            ]);

        RateLimiter::clear("leaderboard:{$this->chat->getKey()}");
    });

    it('ranks the board by score, not streak, where the two orderings differ', function () {
        telegramServesThePost();
        $this->settings->set(SettingKey::LeaderboardTopSize, 3);

        // Score-descending is the opposite of streak-descending here: the
        // long-streak low-scorer must not top the board.
        ChallengeParticipant::factory()->create([
            'challenge_id' => $this->challenge->getKey(),
            'user_id' => User::factory()->telegram()->create(['first_name' => 'HighScore'])->getKey(),
            'current_streak' => 1,
            'total_score' => 800,
        ]);
        ChallengeParticipant::factory()->create([
            'challenge_id' => $this->challenge->getKey(),
            'user_id' => User::factory()->telegram()->create(['first_name' => 'LowScore'])->getKey(),
            'current_streak' => 9,
            'total_score' => 200,
        ]);

        dispatch_sync(new PostDailyLeaderboard($this->chat->getKey(), now()->toDateString()));

        $text = current(array_filter(postedMessages(), fn (array $sent): bool => $sent['chat'] === '-1005555'))['text'];

        expect($text)->toContain(__('bot.chatpost.leaderboard.headline_scored', ['title' => 'Morning run']))
            ->toContain(__('bot.chatpost.leaderboard.row_scored', [
                'rank' => 1, 'name' => 'HighScore', 'score' => 800, 'unit' => 'pushups',
            ]))
            ->toContain(__('bot.chatpost.leaderboard.row_scored', [
                'rank' => 2, 'name' => 'LowScore', 'score' => 200, 'unit' => 'pushups',
            ]))
            ->and(strpos($text, 'HighScore'))->toBeLessThan(strpos($text, 'LowScore'))
            ->and($text)->not->toContain('in a row');
    });

    it('posts nothing when nobody has scored yet', function () {
        telegramServesThePost();

        ChallengeParticipant::factory()->create([
            'challenge_id' => $this->challenge->getKey(),
            'current_streak' => 7,
            'total_score' => 0,
        ]);

        dispatch_sync(new PostDailyLeaderboard($this->chat->getKey(), now()->toDateString()));

        expect(postedMessages())->toBeEmpty()
            ->and($this->chat->posts()->count())->toBe(0);
    });

    it('announces the period’s score alongside the name and period', function () {
        telegramServesThePost();

        $checkIn = pendingCheckIn();
        $checkIn->update(['reported_value' => '45.00', 'score' => 150]);
        approve($checkIn);

        dispatch_sync(new PostCheckInAnnouncement($this->chat->getKey(), $checkIn->getKey()));

        $text = current(array_filter(postedMessages(), fn (array $sent): bool => $sent['chat'] === '-1005555'))['text'];

        expect($text)->toBe(__('bot.chatpost.checkin_scored', [
            'title' => 'Morning run',
            'name' => $checkIn->participant->user->first_name,
            'period' => 1,
            'total' => 10,
            'streak' => 1,
            'value' => 45,
            'unit' => 'pushups',
            'score' => 150,
        ]));
    });

    it('announces a scored-but-unscored-row period in the plain format', function () {
        // A frozen or missed quantity row is announced like any other period
        // that did not move the streak — nothing increments, nothing posts.
        telegramServesThePost();

        $checkIn = pendingCheckIn();
        $checkIn->update(['reported_value' => '15.00']);
        $checkIn->participant->forceFill(['freezes_total' => 1])->save();
        app(SettleCheckIn::class)->approve($checkIn);

        dispatch_sync(new PostCheckInAnnouncement($this->chat->getKey(), $checkIn->getKey()));

        expect(postedMessages())->toBeEmpty()
            ->and($this->chat->posts()->count())->toBe(0);
    });
});
