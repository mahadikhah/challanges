<?php

use App\Actions\Challenges\MaterialiseChallengePeriods;
use App\Actions\Reminders\DispatchDueReminders;
use App\Enums\ChallengeStatus;
use App\Enums\ReminderKind;
use App\Jobs\Telegram\SendReminder;
use App\Models\Challenge;
use App\Models\ChallengeParticipant;
use App\Models\CheckIn;
use App\Models\ReminderDispatch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
 * The reminder pipeline end to end: `challenges:reminders` mints rows for the
 * near future, hands due ones to a staggered queue, and `SendReminder` sends
 * exactly one message per participant per period per kind — however often any
 * of it runs. This is the §6 verification of `prompts/main.md`: freeze the
 * clock, run the scheduler across a period boundary twice, assert one send.
 *
 * The queue is `sync` under test, so a dispatched job runs inline and the
 * messages are on the wire by the time the command returns.
 */

beforeEach(function () {
    Http::preventStrayRequests();

    config([
        'services.telegram.bot_token' => '123456:TEST-TOKEN',
        'services.telegram.bot_username' => 'ChallengesBot',
    ]);

    Http::fake([
        '*sendMessage*' => Http::response(['ok' => true, 'result' => ['message_id' => 11]]),
    ]);
});

/**
 * A daily UTC challenge starting tomorrow midnight, with two participants who
 * can be messaged.
 *
 * @return array{0: Challenge, 1: CarbonImmutable, 2: array<int, ChallengeParticipant>}
 */
function aRemindedChallenge(): array
{
    // Immutable, because the test walks the clock by offsets of this moment and
    // a mutable Carbon would be edited in place by the first `subHour()`.
    $start = now()->addDay()->startOfDay()->toImmutable();

    $challenge = Challenge::factory()->create([
        'status' => ChallengeStatus::Active,
        'title' => 'Morning run',
        'starts_at' => $start,
        'timezone' => 'UTC',
        'total_periods' => 3,
    ]);

    app(MaterialiseChallengePeriods::class)->handle($challenge);

    $participants = [
        ChallengeParticipant::factory()
            ->for($challenge)
            ->for(User::factory()->telegram(900_100_1)->preferring('en')->create())
            ->create(),
        ChallengeParticipant::factory()
            ->for($challenge)
            ->for(User::factory()->telegram(900_100_2)->preferring('en')->create())
            ->create(),
    ];

    return [$challenge, $start, $participants];
}

it('sends exactly one reminder per participant per period across a boundary, run twice', function () {
    [$challenge, $start, $participants] = aRemindedChallenge();

    // An hour before the start: the near future is minted, nothing is due, and
    // running it twice mints nothing twice.
    $this->travelTo($start->subHour());
    Artisan::call('challenges:reminders');
    Artisan::call('challenges:reminders');

    expect(botMessages())->toBeEmpty()
        ->and(ReminderDispatch::query()->count())->toBe(2 * 2); // starting + ending, ×2 participants

    // Inside the first period: the starting reminder is due, for both
    // participants, once each — however often the sweep runs.
    $this->travelTo($start->addHour());
    Artisan::call('challenges:reminders');
    Artisan::call('challenges:reminders');

    $expected = botCopy('bot.reminder.challenge_starting', [
        'title' => 'Morning run',
        'moment' => $start->format('Y-m-d H:i'),
        'timezone' => 'UTC',
        'total' => 3,
    ]);

    expect(botMessages())->toHaveCount(2)
        ->and(botMessages()[0]['text'])->toBe($expected)
        ->and(botMessages()[1]['text'])->toBe($expected)
        ->and(ReminderDispatch::query()->where('kind', ReminderKind::ChallengeStarting)->whereNotNull('sent_at')->count())->toBe(2);

    // Near the close: the "period ending" nudge fires for both, once each.
    $this->travelTo($start->addHours(22));
    Artisan::call('challenges:reminders');
    Artisan::call('challenges:reminders');

    $closing = botCopy('bot.reminder.period_ending', [
        'title' => 'Morning run',
        'index' => 1,
        'total' => 3,
        'moment' => $start->addDay()->format('Y-m-d H:i'),
        'timezone' => 'UTC',
    ]);

    expect(botMessages())->toHaveCount(4)
        ->and(collect(botMessages())->pluck('text')->filter(fn (string $text): bool => $text === $closing))->toHaveCount(2);

    // Into the second period: "period open" fires once each for the new period.
    $this->travelTo($start->addHours(25));
    Artisan::call('challenges:reminders');
    Artisan::call('challenges:reminders');

    $opened = botCopy('bot.reminder.period_opened', [
        'title' => 'Morning run',
        'index' => 2,
        'total' => 3,
    ]);

    expect(botMessages())->toHaveCount(6)
        ->and(collect(botMessages())->pluck('text')->filter(fn (string $text): bool => $text === $opened))->toHaveCount(2);
});

it('never mints a reminder for a period a late joiner did not owe', function () {
    [$challenge, $start, $participants] = aRemindedChallenge();

    ChallengeParticipant::factory()
        ->for($challenge)
        ->for(User::factory()->telegram(900_100_3)->preferring('en')->create())
        ->create(['joined_period_index' => 2]);

    $this->travelTo($start->subHour());
    Artisan::call('challenges:reminders');

    $latecomer = User::query()->where('telegram_id', 900_100_3)->sole();

    expect(ReminderDispatch::query()
        ->whereHas('participant', fn ($query) => $query->where('user_id', $latecomer->getKey()))
        ->count())->toBe(0);
});

it('suppresses the closing nudge for a participant who has already checked in', function () {
    [$challenge, $start, [$settled, $pending]] = aRemindedChallenge();

    CheckIn::factory()->on($settled, $challenge->periods()->first())->approved()->create();

    $this->travelTo($start->subHour());
    Artisan::call('challenges:reminders');

    // The starting reminder goes to both; only the closing nudge is in question.
    $this->travelTo($start->addHour());
    Artisan::call('challenges:reminders');

    $this->travelTo($start->addHours(22));
    Artisan::call('challenges:reminders');

    $closing = botCopy('bot.reminder.period_ending', [
        'title' => 'Morning run',
        'index' => 1,
        'total' => 3,
        'moment' => $start->addDay()->format('Y-m-d H:i'),
        'timezone' => 'UTC',
    ]);

    // The checked-in participant hears nothing further; the other is nudged.
    // Both rows are stamped, so a later sweep does not revisit the question.
    $nudges = collect(botMessages())->filter(fn (array $message): bool => $message['text'] === $closing);

    expect(botMessages())->toHaveCount(3)
        ->and($nudges->pluck('chat_id')->all())->toBe([(string) 900_100_2])
        ->and(ReminderDispatch::query()->where('kind', ReminderKind::PeriodEnding)->whereNotNull('sent_at')->count())->toBe(2);
});

it('stamps a reminder for an already-swept period rather than sending it', function () {
    [$challenge, $start, $participants] = aRemindedChallenge();
    $participant = $participants[0];

    $period = $challenge->periods()->first();
    $period->update(['rolled_over_at' => now()]);

    $reminder = ReminderDispatch::factory()
        ->on($participant, $period)
        ->ofKind(ReminderKind::PeriodEnding)
        ->scheduledFor(now()->subHour()->toDateTimeString())
        ->create();

    app(DispatchDueReminders::class)->handle();

    expect($reminder->refresh()->sent_at)->not->toBeNull()
        ->and(botMessages())->toBeEmpty();
});

it('queues at most one batch, staggered one send a second', function () {
    Queue::fake();

    [$challenge, $start, $participants] = aRemindedChallenge();
    $period = $challenge->periods()->first();

    // More due rows than the batch admits — each on its own participant (the
    // pair is unique per challenge, so one user cannot owe it twice), and the
    // users need no Telegram identity because nothing is sent: the queue is
    // faked and the jobs never run.
    foreach (range(1, DispatchDueReminders::BATCH + 5) as $i) {
        $participant = ChallengeParticipant::factory()
            ->for($challenge)
            ->for(User::factory()->create())
            ->create();

        ReminderDispatch::factory()
            ->on($participant, $period)
            ->scheduledFor(now()->subMinutes(5)->toDateTimeString())
            ->create();
    }

    $dispatched = app(DispatchDueReminders::class)->handle();

    expect($dispatched)->toBe(DispatchDueReminders::BATCH)
        ->and(Queue::pushed(SendReminder::class))->toHaveCount(DispatchDueReminders::BATCH);

    // The trickle: one second apart, in queue order.
    $delays = collect(Queue::pushed(SendReminder::class))
        ->map(fn (SendReminder $job) => $job->delay)
        ->all();

    expect($delays)->toBe(range(0, DispatchDueReminders::BATCH - 1));

    // The invariant the stagger depends on: the whole batch must drain inside
    // one cron tick, or the next sweep would re-dispatch still-queued rows.
    expect(DispatchDueReminders::BATCH * DispatchDueReminders::STAGGER_SECONDS)->toBeLessThan(60);
});
