<?php

use App\Actions\Challenges\MaterialiseChallengePeriods;
use App\Enums\PeriodType;
use App\Models\Challenge;
use App\Models\ChallengePeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->materialise = app(MaterialiseChallengePeriods::class);
});

/**
 * A challenge whose timeline is pinned, so nothing leans on a factory default.
 *
 * `$startsAt` is read as **UTC**, matching the column — every test that cares
 * about a local wall-clock time states the conversion it expects out loud.
 */
function challengeOn(
    string $startsAt,
    string $timezone,
    PeriodType $periodType = PeriodType::Daily,
    int $totalPeriods = 3,
    ?int $customDays = null,
): Challenge {
    return Challenge::factory()
        ->every($periodType, $customDays)
        ->timeline($startsAt, $timezone, $totalPeriods)
        ->create();
}

/**
 * Every boundary on the timeline as `Y-m-d H:i`, viewed from `$timezone`.
 *
 * One more entry than there are periods — the final `ends_at` closes the last
 * one — which is also how a gap or an overlap would show up as a wrong string.
 *
 * @param  list<array{index: int, starts_at: CarbonImmutable, ends_at: CarbonImmutable}>  $periods
 * @return list<string>
 */
function boundariesAsSeenFrom(array $periods, string $timezone = 'UTC'): array
{
    $format = fn (CarbonImmutable $moment): string => $moment
        ->setTimezone($timezone)
        ->format('Y-m-d H:i');

    $strings = array_map(fn (array $period): string => $format($period['starts_at']), $periods);
    $strings[] = $format($periods[count($periods) - 1]['ends_at']);

    return $strings;
}

describe('the shape of a timeline', function () {
    it('creates exactly one period per requested period, indexed from zero', function () {
        $challenge = challengeOn('2026-01-15 00:00:00', 'UTC', totalPeriods: 12);

        $periods = $this->materialise->handle($challenge);

        expect($periods)->toHaveCount(12)
            ->and($periods->pluck('index')->all())->toBe(range(0, 11));
    });

    it('opens the first period exactly when the challenge starts', function () {
        $challenge = challengeOn('2026-01-15 09:30:00', 'UTC');

        $first = $this->materialise->handle($challenge)->first();

        expect($first->starts_at->toDateTimeString())->toBe('2026-01-15 09:30:00');
    });

    it('leaves no gap and no overlap between consecutive periods', function () {
        $challenge = challengeOn('2026-01-15 00:00:00', 'UTC', PeriodType::Monthly, totalPeriods: 6);

        $periods = $this->materialise->handle($challenge);

        foreach ($periods->take(5) as $position => $period) {
            expect($period->ends_at->toDateTimeString())
                ->toBe($periods[$position + 1]->starts_at->toDateTimeString());
        }
    });

    it('gives the boundary instant to the later period, never to both', function () {
        // The half-open interval, tested through the model rather than asserted
        // about the migration: `ends_at` is exclusive.
        $challenge = challengeOn('2026-01-15 00:00:00', 'UTC');

        $periods = $this->materialise->handle($challenge);
        $boundary = $periods[0]->ends_at;

        expect($periods[0]->contains($boundary))->toBeFalse()
            ->and($periods[1]->contains($boundary))->toBeTrue()
            ->and($periods[0]->contains($boundary->subSecond()))->toBeTrue();
    });

    it('returns the timeline in timeline order', function () {
        $challenge = challengeOn('2026-01-15 00:00:00', 'UTC', totalPeriods: 5);

        $starts = $this->materialise->handle($challenge)->pluck('starts_at')->all();
        $sorted = collect($starts)->sort()->values()->all();

        expect($starts)->toEqual($sorted);
    });

    it('marks nothing as rolled over on a fresh timeline', function () {
        $challenge = challengeOn('2026-01-15 00:00:00', 'UTC');

        expect($this->materialise->handle($challenge)->pluck('rolled_over_at')->filter())->toBeEmpty();
    });
});

describe('each period type', function () {
    it('advances by the interval the type names', function (PeriodType $type, ?int $customDays, array $expected) {
        // Three periods, so four boundaries — enough to catch an off-by-one in the
        // step multiplier that a single period would hide.
        $challenge = challengeOn('2026-01-15 00:00:00', 'UTC', $type, 3, $customDays);

        expect(boundariesAsSeenFrom($this->materialise->boundaries($challenge)))->toBe($expected);
    })->with([
        'daily' => [PeriodType::Daily, null, [
            '2026-01-15 00:00', '2026-01-16 00:00', '2026-01-17 00:00', '2026-01-18 00:00',
        ]],
        'weekly' => [PeriodType::Weekly, null, [
            '2026-01-15 00:00', '2026-01-22 00:00', '2026-01-29 00:00', '2026-02-05 00:00',
        ]],
        'monthly' => [PeriodType::Monthly, null, [
            '2026-01-15 00:00', '2026-02-15 00:00', '2026-03-15 00:00', '2026-04-15 00:00',
        ]],
        'seasonal — a quarter' => [PeriodType::Seasonal, null, [
            '2026-01-15 00:00', '2026-04-15 00:00', '2026-07-15 00:00', '2026-10-15 00:00',
        ]],
        'yearly' => [PeriodType::Yearly, null, [
            '2026-01-15 00:00', '2027-01-15 00:00', '2028-01-15 00:00', '2029-01-15 00:00',
        ]],
        'custom — five days' => [PeriodType::Custom, 5, [
            '2026-01-15 00:00', '2026-01-20 00:00', '2026-01-25 00:00', '2026-01-30 00:00',
        ]],
        'custom — a single day, indistinguishable from daily' => [PeriodType::Custom, 1, [
            '2026-01-15 00:00', '2026-01-16 00:00', '2026-01-17 00:00', '2026-01-18 00:00',
        ]],
    ]);

    it('ignores a stray custom day count on a type that does not use one', function () {
        // `customPeriodDays()` gates on the type, so a leftover 90 from an edited
        // draft cannot quietly turn a weekly challenge into a quarterly one.
        $challenge = challengeOn('2026-01-15 00:00:00', 'UTC', PeriodType::Weekly);
        $challenge->custom_period_days = 90;

        expect(boundariesAsSeenFrom($this->materialise->boundaries($challenge)))
            ->toBe(['2026-01-15 00:00', '2026-01-22 00:00', '2026-01-29 00:00', '2026-02-05 00:00']);
    });
});

describe('the challenge timezone, not the server', function () {
    it('reads the wall-clock time the creator chose, in their own zone', function () {
        // 20:30 UTC is 00:00 the next day in Tehran, so this is a challenge that
        // opens at local midnight on 1 March.
        $challenge = challengeOn('2026-02-28 20:30:00', 'Asia/Tehran');

        expect(boundariesAsSeenFrom($this->materialise->boundaries($challenge), 'Asia/Tehran'))
            ->toBe(['2026-03-01 00:00', '2026-03-02 00:00', '2026-03-03 00:00', '2026-03-04 00:00']);
    });

    it('writes UTC to the column, whatever zone the maths happened in', function () {
        // The trap this guards: the query builder formats a date binding in the
        // timezone the object carries, so a Tehran-local Carbon would write
        // "00:00" into a UTC column and lose three and a half hours in silence.
        $challenge = challengeOn('2026-02-28 20:30:00', 'Asia/Tehran');

        $this->materialise->handle($challenge);

        $raw = DB::table('challenge_periods')
            ->where('challenge_id', $challenge->id)
            ->where('index', 0)
            ->value('starts_at');

        expect($raw)->toBe('2026-02-28 20:30:00');
    });

    it('puts the same local start at different instants in different zones', function () {
        // Both open at 08:00 local on the same date. Tehran is +03:30 all year and
        // London is +01:00 in June, so they are genuinely 150 minutes apart and the
        // stored instants have to say so.
        $tehran = challengeOn('2026-06-10 04:30:00', 'Asia/Tehran');
        $london = challengeOn('2026-06-10 07:00:00', 'Europe/London');

        $tehranStart = $this->materialise->boundaries($tehran)[0]['starts_at'];
        $londonStart = $this->materialise->boundaries($london)[0]['starts_at'];

        expect($tehranStart->setTimezone('Asia/Tehran')->format('H:i'))->toBe('08:00')
            ->and($londonStart->setTimezone('Europe/London')->format('H:i'))->toBe('08:00')
            ->and($tehranStart->diffInMinutes($londonStart))->toBe(150.0);
    });

    it('is a pure function of the challenge, not of the clock', function () {
        // A materialiser that leaked `now()` would build a different timeline on
        // every re-run, and idempotency would be luck. Note that the process
        // timezone is deliberately *not* varied here: Eloquent round-trips a
        // datetime attribute through a string and re-parses it in `app.timezone`,
        // so moving that setting reinterprets every stored timestamp in the
        // database. It has to stay UTC — recorded in prompts/progress.md.
        $challenge = challengeOn('2026-01-15 00:00:00', 'Asia/Tehran', PeriodType::Monthly);

        $today = boundariesAsSeenFrom($this->materialise->boundaries($challenge));

        $this->travelTo(CarbonImmutable::parse('2027-08-09 13:45:00'));
        $muchLater = boundariesAsSeenFrom($this->materialise->boundaries($challenge));
        $this->travelBack();

        expect($muchLater)->toBe($today)
            ->and($today[0])->toBe('2026-01-15 00:00');
    });
});

describe('daylight saving', function () {
    it('keeps the local check-in time fixed when the clocks go forward', function () {
        // London moves to BST at 01:00 on 29 March 2026. A daily challenge that
        // opens at local midnight must keep opening at local midnight.
        $challenge = challengeOn('2026-03-27 00:00:00', 'Europe/London', totalPeriods: 4);

        expect(boundariesAsSeenFrom($this->materialise->boundaries($challenge), 'Europe/London'))
            ->toBe([
                '2026-03-27 00:00',
                '2026-03-28 00:00',
                '2026-03-29 00:00',
                '2026-03-30 00:00',
                '2026-03-31 00:00',
            ]);
    });

    it('spends 23 real hours on the period the clocks jump inside', function () {
        // The other half of the same fact: the local time is unchanged precisely
        // because the elapsed time is not. Adding a flat 24 hours in UTC would
        // give 24 here and leave every later boundary an hour early.
        $challenge = challengeOn('2026-03-27 00:00:00', 'Europe/London', totalPeriods: 4);

        $lengths = array_map(
            fn (array $period): int => (int) $period['starts_at']->diffInHours($period['ends_at']),
            $this->materialise->boundaries($challenge),
        );

        expect($lengths)->toBe([24, 24, 23, 24]);
    });

    it('spends 25 real hours on the period the clocks fall back inside', function () {
        // London leaves BST at 02:00 on 25 October 2026, so the local day of the
        // 25th is 25 hours long. Anchored two days earlier, mirroring the test
        // above, so the odd period sits at the same index.
        $challenge = challengeOn('2026-10-22 23:00:00', 'Europe/London', totalPeriods: 4);

        $periods = $this->materialise->boundaries($challenge);

        $lengths = array_map(
            fn (array $period): int => (int) $period['starts_at']->diffInHours($period['ends_at']),
            $periods,
        );

        expect($lengths)->toBe([24, 24, 25, 24])
            ->and(boundariesAsSeenFrom($periods, 'Europe/London'))->toBe([
                '2026-10-23 00:00',
                '2026-10-24 00:00',
                '2026-10-25 00:00',
                '2026-10-26 00:00',
                '2026-10-27 00:00',
            ]);
    });

    it('does not drift a long timeline across two changeovers', function () {
        // A year of daily periods through both shifts. The last boundary landing
        // on the right local midnight is the real assertion: an accumulating
        // one-hour error would be visible by now.
        $challenge = challengeOn('2026-01-01 00:00:00', 'Europe/London', totalPeriods: 365);

        $periods = $this->materialise->boundaries($challenge);
        $last = $periods[364];

        expect($last['ends_at']->setTimezone('Europe/London')->format('Y-m-d H:i'))
            ->toBe('2027-01-01 00:00')
            ->and($last['starts_at']->setTimezone('Europe/London')->format('H:i'))->toBe('00:00');
    });

    it('runs a Tehran challenge at a flat 24 hours, because Iran abolished DST', function () {
        // Iran dropped daylight saving in 2022, so the platform's primary audience
        // has no changeover at all. 22 March used to be one; asserting it is
        // ordinary now documents why Farsi users never see a short period.
        $challenge = challengeOn('2026-03-19 20:30:00', 'Asia/Tehran', totalPeriods: 6);

        $periods = $this->materialise->boundaries($challenge);

        $lengths = array_map(
            fn (array $period): int => (int) $period['starts_at']->diffInHours($period['ends_at']),
            $periods,
        );

        expect($lengths)->toBe([24, 24, 24, 24, 24, 24])
            ->and(boundariesAsSeenFrom($periods, 'Asia/Tehran')[3])->toBe('2026-03-23 00:00');
    });
});

describe('month ends', function () {
    it('clamps a month-end anchor into a shorter month, then recovers it', function () {
        // The reason boundaries are computed from the anchor rather than from the
        // previous boundary. Stepping incrementally would keep February's clamp
        // forever and give 31 Jan → 28 Feb → 28 Mar → 28 Apr.
        $challenge = challengeOn('2026-01-31 00:00:00', 'UTC', PeriodType::Monthly, totalPeriods: 4);

        expect(boundariesAsSeenFrom($this->materialise->boundaries($challenge)))
            ->toBe([
                '2026-01-31 00:00',
                '2026-02-28 00:00',
                '2026-03-31 00:00',
                '2026-04-30 00:00',
                '2026-05-31 00:00',
            ]);
    });

    it('never overflows a short month into the next one', function () {
        // PHP's native "+1 month" on 31 January gives 3 March, skipping February
        // entirely. A monthly challenge would then have no February period.
        $challenge = challengeOn('2026-01-31 00:00:00', 'UTC', PeriodType::Monthly);

        $months = array_map(
            fn (array $period): string => $period['starts_at']->format('M'),
            $this->materialise->boundaries($challenge),
        );

        expect($months)->toBe(['Jan', 'Feb', 'Mar']);
    });

    it('holds a leap day anchor without sliding into March', function () {
        $challenge = challengeOn('2024-02-29 00:00:00', 'UTC', PeriodType::Yearly, totalPeriods: 4);

        expect(boundariesAsSeenFrom($this->materialise->boundaries($challenge)))
            ->toBe([
                '2024-02-29 00:00',
                '2025-02-28 00:00',
                '2026-02-28 00:00',
                '2027-02-28 00:00',
                '2028-02-29 00:00',
            ]);
    });

    it('clamps a seasonal timeline anchored on new year’s eve', function () {
        $challenge = challengeOn('2025-12-31 00:00:00', 'UTC', PeriodType::Seasonal, totalPeriods: 4);

        expect(boundariesAsSeenFrom($this->materialise->boundaries($challenge)))
            ->toBe([
                '2025-12-31 00:00',
                '2026-03-31 00:00',
                '2026-06-30 00:00',
                '2026-09-30 00:00',
                '2026-12-31 00:00',
            ]);
    });
});

describe('refusing a timeline it cannot build', function () {
    it('refuses a challenge with no periods', function (int $total) {
        $challenge = challengeOn('2026-01-15 00:00:00', 'UTC');
        $challenge->total_periods = $total;

        expect(fn () => $this->materialise->boundaries($challenge))
            ->toThrow(InvalidArgumentException::class);
    })->with([
        'zero' => 0,
        'negative' => -1,
    ]);

    it('refuses a custom challenge that never says how long a period is', function (?int $days) {
        $challenge = challengeOn('2026-01-15 00:00:00', 'UTC', PeriodType::Custom, customDays: 5);
        $challenge->custom_period_days = $days;

        expect(fn () => $this->materialise->boundaries($challenge))
            ->toThrow(InvalidArgumentException::class);
    })->with([
        'missing' => null,
        'zero' => 0,
        'negative' => -3,
    ]);

    it('refuses a timezone that is not a timezone', function () {
        $challenge = challengeOn('2026-01-15 00:00:00', 'UTC');
        $challenge->timezone = 'Mars/Olympus_Mons';

        expect(fn () => $this->materialise->boundaries($challenge))
            ->toThrow(InvalidArgumentException::class);
    });

    it('writes nothing when it refuses', function () {
        $challenge = challengeOn('2026-01-15 00:00:00', 'UTC');
        $challenge->total_periods = 0;

        expect(fn () => $this->materialise->handle($challenge))
            ->toThrow(InvalidArgumentException::class);

        expect($challenge->periods()->count())->toBe(0);
    });
});

describe('materialising is idempotent', function () {
    it('adds nothing on a second run', function () {
        $challenge = challengeOn('2026-01-15 00:00:00', 'UTC', totalPeriods: 10);

        $first = $this->materialise->handle($challenge);
        $second = $this->materialise->handle($challenge);

        expect($second)->toHaveCount(10)
            ->and($second->pluck('id')->all())->toBe($first->pluck('id')->all());
    });

    it('fills a hole rather than duplicating its neighbours', function () {
        // What a partially-failed run leaves behind. Also what two callers racing
        // looks like from the loser's side: the unique index absorbs the collision
        // instead of the action needing to read first.
        $challenge = challengeOn('2026-01-15 00:00:00', 'UTC', totalPeriods: 5);
        $this->materialise->handle($challenge);
        $challenge->periods()->where('index', 3)->delete();

        expect($challenge->periods()->count())->toBe(4);

        $refilled = $this->materialise->handle($challenge);

        expect($refilled)->toHaveCount(5)
            ->and($refilled->pluck('index')->all())->toBe([0, 1, 2, 3, 4]);
    });

    it('appends when the challenge is extended', function () {
        $challenge = challengeOn('2026-01-15 00:00:00', 'UTC', totalPeriods: 3);
        $original = $this->materialise->handle($challenge);

        $challenge->update(['total_periods' => 6]);
        $extended = $this->materialise->handle($challenge);

        expect($extended)->toHaveCount(6)
            // The original three keep their identity, so any check-in attached to
            // them survives the extension.
            ->and($extended->take(3)->pluck('id')->all())->toBe($original->pluck('id')->all())
            ->and($extended[5]->ends_at->toDateTimeString())->toBe('2026-01-21 00:00:00');
    });

    it('refuses to move a boundary a check-in may already hang from', function () {
        // Re-running after the start date changed must not silently reschedule
        // history. Rebuilding a live timeline is a deliberate, separate act.
        $challenge = challengeOn('2026-01-15 00:00:00', 'UTC', totalPeriods: 3);
        $this->materialise->handle($challenge);

        $challenge->update(['starts_at' => '2026-06-01 00:00:00']);
        $periods = $this->materialise->handle($challenge);

        expect($periods)->toHaveCount(3)
            ->and($periods[0]->starts_at->toDateTimeString())->toBe('2026-01-15 00:00:00');
    });

    it('keeps one challenge’s timeline out of another’s', function () {
        $mine = challengeOn('2026-01-15 00:00:00', 'UTC', totalPeriods: 3);
        $theirs = challengeOn('2026-01-15 00:00:00', 'UTC', totalPeriods: 4);

        $this->materialise->handle($mine);
        $this->materialise->handle($theirs);

        expect($mine->periods()->count())->toBe(3)
            ->and($theirs->periods()->count())->toBe(4)
            ->and(ChallengePeriod::query()->count())->toBe(7);
    });

    it('stamps timestamps, since the insert bypasses Eloquent', function () {
        $challenge = challengeOn('2026-01-15 00:00:00', 'UTC');

        $period = $this->materialise->handle($challenge)->first();

        expect($period->created_at)->not->toBeNull()
            ->and($period->updated_at)->not->toBeNull();
    });
});
