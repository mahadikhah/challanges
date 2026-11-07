<?php

use App\Actions\Challenges\MaterialiseChallengePeriods;
use App\Enums\ChallengeStatus;
use App\Enums\PeriodType;
use App\Models\Challenge;
use App\Models\ChallengeParticipant;
use App\Models\User;
use App\Services\Telegram\PeriodUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Lang;

uses(RefreshDatabase::class);

/*
 * A challenge is counted in its own unit. A daily one is a run of days, a weekly
 * one of weeks; "check in every period" asks a participant to learn our word for
 * a row in `challenge_periods` before they can read the sentence.
 *
 * Two halves. `PeriodUnit` is asked directly for the noun and the arithmetic —
 * six period types, two locales, no message in the way — and then the four
 * sentences that name a unit are rendered with the values it produces, so a
 * catalogue line that loses its `:cadence` slot fails here rather than in a
 * participant's chat. The wiring itself — that `SendReminder` really does pass
 * those values — is pinned end to end by the sweep at the bottom and by the
 * surfaces' own files (`ReminderSweepTest`, `JoinChallengeFlowTest`,
 * `CheckInFlowTest`, `ChallengeChatPostingTest`, `ChannelBroadcasterTest`), which
 * all assert the rendered unit for a real challenge.
 */

/**
 * The unit service, with nobody's locale in it.
 */
function periodUnits(): PeriodUnit
{
    return app(PeriodUnit::class);
}

/**
 * A challenge of a given cadence, with a pinned timeline.
 */
function countedChallenge(PeriodType $periodType, ?int $customDays = null, int $totalPeriods = 5): Challenge
{
    return Challenge::factory()
        ->every($periodType, $customDays)
        ->timeline('2026-09-01', 'UTC', $totalPeriods)
        ->create(['title' => 'Morning run', 'status' => ChallengeStatus::Active]);
}

/**
 * A reader of one language.
 */
function readerOf(string $locale): User
{
    return User::factory()->telegram(random_int(900_500_1, 900_599_9))->preferring($locale)->create();
}

/**
 * The four sentences that name a cadence, with the values the bot puts in them.
 *
 * `:how` is left empty on purpose: it is Task 7's sentence and has its own tests.
 *
 * @return array<string, string>
 */
function sentencesNamingTheUnit(User $user, Challenge $challenge): array
{
    $units = periodUnits();
    $index = $units->opening($challenge, 0);
    $total = $units->total($challenge);
    $cadence = $units->one($user, $challenge);

    return [
        'reminder.period_opened' => botCopy('bot.reminder.period_opened', [
            'title' => $challenge->title,
            'cadence' => $cadence,
            'index' => $index,
            'total' => $total,
            'how' => '',
        ]),
        'join.joined' => botCopy('bot.join.joined', [
            'title' => $challenge->title,
            'span' => $units->span($user, $challenge),
            'how' => '',
        ]),
        'checkin.todo' => botCopy('bot.checkin.todo', [
            'title' => $challenge->title,
            'cadence' => $cadence,
            'index' => $index,
            'total' => $total,
            'how' => '',
        ]),
        'chatpost.checkin' => botCopy('bot.chatpost.checkin', [
            'name' => 'Sara',
            'cadence' => $cadence,
            'period' => $index,
            'total' => $total,
            'streak' => 1,
        ]),
    ];
}

describe('the unit a challenge is counted in', function () {
    it('names the unit its cadence is made of', function (PeriodType $periodType, ?int $customDays, string $noun, string $span, string $length) {
        $challenge = countedChallenge($periodType, $customDays);

        expect(periodUnits()->one(null, $challenge))->toBe($noun)
            ->and(periodUnits()->span(null, $challenge))->toBe($span)
            ->and(periodUnits()->length(null, $challenge))->toBe($length);
    })->with([
        'daily' => [PeriodType::Daily, null, 'day', 'day', '5 days'],
        'weekly' => [PeriodType::Weekly, null, 'week', 'week', '5 weeks'],
        'monthly' => [PeriodType::Monthly, null, 'month', 'month', '5 months'],
        'seasonal' => [PeriodType::Seasonal, null, 'season', 'season', '5 seasons'],
        'yearly' => [PeriodType::Yearly, null, 'year', 'year', '5 years'],
        // Custom has no noun of its own: a 3-day cadence is three days, and the
        // five of them are fifteen days rather than five of anything.
        'custom every three days' => [PeriodType::Custom, 3, 'day', '3 days', '15 days'],
    ]);

    it('says "1 day" rather than "1 days"', function () {
        $units = periodUnits();

        // A weekly challenge one period long, and a custom one a single day
        // long: both are one of their unit, and English pluralises on the count
        // rather than on the type.
        $single = countedChallenge(PeriodType::Weekly, null, 1);

        expect($units->length(null, $single))->toBe('1 week')
            ->and($units->span(null, $single))->toBe('week');

        $oneDay = countedChallenge(PeriodType::Custom, 1, 1);

        expect($units->length(null, $oneDay))->toBe('1 day')
            ->and($units->span(null, $oneDay))->toBe('day');
    });

    it('counts a custom challenge in days, not in periods', function () {
        $challenge = countedChallenge(PeriodType::Custom, 3, 6);
        $units = periodUnits();

        expect($units->total($challenge))->toBe(18)
            // The second period of a 3-day challenge opens on day 4 and closes
            // on day 6 — the numbers its participant is actually living through.
            ->and($units->opening($challenge, 0))->toBe(1)
            ->and($units->opening($challenge, 1))->toBe(4)
            ->and($units->closing($challenge, 1))->toBe(6)
            ->and($units->length(null, $challenge))->toBe('18 days');
    });

    it('resolves the noun per recipient, not in whatever locale ran last', function () {
        $challenge = countedChallenge(PeriodType::Weekly);
        $units = periodUnits();

        // Both readers in the same test, deliberately: a composer that reached
        // for `Lang::get()` without a locale would give the second reader the
        // first reader's language, and an assertion on one reader alone cannot
        // see that.
        expect($units->one(readerOf('en'), $challenge))->toBe('week')
            ->and($units->one(readerOf('fa'), $challenge))->toBe('هفته')
            ->and($units->length(readerOf('fa'), $challenge))->toBe('5 هفته');
    });

    it('falls back to the platform language where there is no recipient', function () {
        $challenge = countedChallenge(PeriodType::Daily);

        // A channel post and a linked chat's announcement have no addressee to
        // ask; both render in the fallback locale, and so must the unit.
        expect(periodUnits()->one(null, $challenge))->toBe(botCopy('enums.period_unit.daily.one'))
            ->and(periodUnits()->length(null, $challenge))->toBe('5 days');
    });
});

describe('the sentences that name the unit', function () {
    it('says the unit, and never "period"', function (PeriodType $periodType, ?int $customDays, string $ordinal, string $span) {
        $challenge = countedChallenge($periodType, $customDays);
        $sentences = sentencesNamingTheUnit(readerOf('en'), $challenge);

        expect($sentences)->toHaveCount(4);

        // Three of the four number a window, and number it in the challenge's own
        // unit: "day 1 of 5", "day 1 of 15" for a custom challenge of six 3-day
        // periods. The fourth names the window instead of counting it.
        foreach (['reminder.period_opened', 'checkin.todo', 'chatpost.checkin'] as $key) {
            expect($sentences[$key])->toContain($ordinal);
        }

        expect($sentences['join.joined'])->toContain("every {$span}");

        foreach ($sentences as $key => $sentence) {
            // The word this whole task exists to remove. `:period` as a
            // placeholder name is not it — every value is in place by now.
            expect($sentence)->not->toContain('period');
        }
    })->with([
        'daily' => [PeriodType::Daily, null, 'day 1 of 5', 'day'],
        'weekly' => [PeriodType::Weekly, null, 'week 1 of 5', 'week'],
        'monthly' => [PeriodType::Monthly, null, 'month 1 of 5', 'month'],
        'seasonal' => [PeriodType::Seasonal, null, 'season 1 of 5', 'season'],
        'yearly' => [PeriodType::Yearly, null, 'year 1 of 5', 'year'],
        'custom every three days' => [PeriodType::Custom, 3, 'day 1 of 15', '3 days'],
    ]);
});

describe('the catalogue', function () {
    it('defines a unit for every period type, in every locale', function (string $locale) {
        $supported = array_keys(config('localization.supported'));

        expect($supported)->toContain($locale);

        foreach (PeriodType::cases() as $case) {
            $key = "enums.period_unit.{$case->value}";

            expect(Lang::get("{$key}.one", [], $locale))->toBeString()->not->toBe("{$key}.one")
                ->and(Lang::get("{$key}.after_number", [], $locale))->toBeString()->not->toBe("{$key}.after_number");
        }
    })->with(['en', 'fa']);

    it('leaves the picker labels untouched', function () {
        // `enums.period_type` is what a creator chooses a cadence from — "Daily",
        // "Weekly" — and reads back in the summary. It is not the noun and must
        // not have moved.
        expect(botCopy('enums.period_type.daily'))->toBe('Daily')
            ->and(botCopy('enums.period_type.seasonal'))->toBe('Seasonal')
            ->and(botCopy('enums.period_type.custom', [], 'fa'))->toBe('دلخواه');
    });

    it('has stopped calling a challenge a period, everywhere but the menu entry', function (string $locale) {
        $word = $locale === 'fa' ? 'دوره' : 'period';
        $offenders = [];

        $walk = function (array $lines, string $prefix) use (&$walk, &$offenders, $word): void {
            foreach ($lines as $key => $value) {
                $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

                if (is_array($value)) {
                    $walk($value, $path);

                    continue;
                }

                if (! is_string($value)) {
                    continue;
                }

                // Placeholder *names* are ours, not the reader's: `:period` is an
                // interpolation slot whose value is the thing being asserted
                // above, so it is stripped before the scan.
                $prose = (string) preg_replace('/:[a-z_]+/', '', $value);

                if (mb_stripos($prose, $word) !== false) {
                    $offenders[] = $path;
                }
            }
        };

        $walk(Lang::get('bot', [], $locale), '');

        // One deliberate survivor. `/checkin` is a menu entry with no challenge
        // in hand — it lists what is owed across every challenge a user is in, so
        // there is no single cadence for it to name.
        expect($offenders)->toBe(['commands.checkin']);
    })->with(['en', 'fa']);
});

describe('the reminder a participant actually receives', function () {
    beforeEach(function () {
        Http::preventStrayRequests();

        config([
            'services.telegram.bot_token' => '123456:TEST-TOKEN',
            'services.telegram.bot_username' => 'ChallengesBot',
        ]);
    });

    it('counts the challenge in days for both readers, each in their own language', function () {
        Http::fake([
            '*sendMessage*' => Http::response(['ok' => true, 'result' => ['message_id' => 11]]),
        ]);

        $start = now()->addDay()->startOfDay()->toImmutable();

        // Six periods of three days: eighteen days, counted in days because a
        // 3-day window has no other honest name.
        $challenge = Challenge::factory()
            ->every(PeriodType::Custom, 3)
            ->timeline($start->toDateTimeString(), 'UTC', 6)
            ->create(['title' => 'Morning run', 'status' => ChallengeStatus::Active]);

        app(MaterialiseChallengePeriods::class)->handle($challenge);

        foreach ([['en', 900_100_1], ['fa', 900_100_2]] as [$locale, $telegramId]) {
            ChallengeParticipant::factory()
                ->for($challenge)
                ->for(User::factory()->telegram($telegramId)->preferring($locale)->create())
                ->create();
        }

        // The sweep mints a starting nudge an hour before the start and sends it
        // once the moment has arrived — the same two-pass shape `ReminderSweepTest`
        // walks.
        $this->travelTo($start->subHour());
        Artisan::call('challenges:reminders');

        $this->travelTo($start->addHour());
        Artisan::call('challenges:reminders');

        $messages = botMessages();

        expect($messages)->toHaveCount(2)
            ->and($messages[0]['text'])->toBe(botCopy('bot.reminder.challenge_starting', [
                'title' => 'Morning run',
                'moment' => $start->format('Y-m-d H:i'),
                'timezone' => 'UTC',
                'length' => '18 days',
                'span' => '3 days',
                'how' => botCopy('bot.checkin.how.button'),
            ]))
            ->and($messages[1]['text'])->toBe(botCopy('bot.reminder.challenge_starting', [
                'title' => 'Morning run',
                'moment' => $start->format('Y-m-d H:i'),
                'timezone' => 'UTC',
                'length' => '18 روز',
                'span' => '3 روز',
                'how' => botCopy('bot.checkin.how.button', [], 'fa'),
            ], 'fa'));
    });
});
