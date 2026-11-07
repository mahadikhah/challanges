<?php

use App\Enums\ChallengeStatus;
use App\Enums\ChallengeVisibility;
use App\Enums\CheckInStatus;
use App\Enums\ParticipantStatus;
use App\Enums\PeriodType;
use App\Enums\ProofType;
use App\Models\Challenge;
use App\Models\ChallengeParticipant;
use App\Models\ChallengePeriod;
use App\Models\CheckIn;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

describe('users carry a Telegram identity', function () {
    it('stores a Telegram id larger than 32 bits', function () {
        // Telegram ids passed 2^32 years ago; a plain integer column would wrap.
        $user = User::factory()->telegram(7_123_456_789)->create();

        expect($user->fresh()?->platform_user_id)->toBe(7_123_456_789);
    });

    it('lets a bot user exist with no credentials at all', function () {
        $user = User::factory()->telegram()->create();

        expect($user->email)->toBeNull()
            ->and($user->password)->toBeNull()
            ->and($user->isTelegramUser())->toBeTrue();
    });

    it('keeps admins and bot users as separate identities', function () {
        $admin = User::factory()->admin()->create();

        expect($admin->is_admin)->toBeTrue()
            ->and($admin->isTelegramUser())->toBeFalse()
            ->and($admin->email)->not->toBeNull();
    });

    it('allows many credential-less users despite the unique email index', function () {
        // MySQL permits repeated NULLs in a unique index; the whole bot-user
        // design depends on that being true.
        User::factory()->telegram()->count(3)->create();

        expect(User::query()->whereNull('email')->count())->toBe(3);
    });

    it('still rejects a duplicate email', function () {
        User::factory()->create(['email' => 'admin@example.com']);

        expect(fn () => User::factory()->create(['email' => 'admin@example.com']))
            ->toThrow(QueryException::class);
    });

    it('rejects a duplicate telegram id, so one account cannot be created twice', function () {
        User::factory()->telegram(555)->create();

        expect(fn () => User::factory()->telegram(555)->create())
            ->toThrow(QueryException::class);
    });

    it('is not mass-assignable into an admin', function () {
        $user = User::query()->create([
            'name' => 'Nice Try',
            'email' => 'nice@example.com',
            'is_admin' => true,
        ]);

        expect($user->fresh()?->is_admin)->toBeFalse();
    });

    it('exposes the locale preference the SetLocale middleware honours', function () {
        expect(User::factory()->preferring('fa')->create()->preferredLocale())->toBe('fa')
            ->and(User::factory()->create()->preferredLocale())->toBeNull();
    });

    it('keeps the reported language separate from the chosen locale', function () {
        // Telegram may report en-US while the user has deliberately picked Farsi.
        $user = User::factory()->telegram()->preferring('fa')->create(['language_code' => 'en-US']);

        expect($user->language_code)->toBe('en-US')
            ->and($user->preferredLocale())->toBe('fa');
    });

    it('links a referred user to their referrer in both directions', function () {
        $inviter = User::factory()->telegram()->create();
        $invited = User::factory()->telegram()->referredBy($inviter)->create();

        expect($invited->referrer?->id)->toBe($inviter->id)
            ->and($inviter->referrals->pluck('id')->all())->toBe([$invited->id]);
    });

    it('keeps referred users when their referrer is deleted', function () {
        $inviter = User::factory()->telegram()->create();
        $invited = User::factory()->telegram()->referredBy($inviter)->create();

        $inviter->delete();

        expect($invited->fresh())->not->toBeNull()
            ->and($invited->fresh()?->referred_by_user_id)->toBeNull();
    });
});

describe('challenges', function () {
    it('casts every status and type to a backed enum', function () {
        $challenge = Challenge::factory()->create()->fresh();

        expect($challenge?->period_type)->toBeInstanceOf(PeriodType::class)
            ->and($challenge?->visibility)->toBeInstanceOf(ChallengeVisibility::class)
            ->and($challenge?->proof_type)->toBeInstanceOf(ProofType::class)
            ->and($challenge?->status)->toBeInstanceOf(ChallengeStatus::class)
            ->and($challenge?->proof_is_public)->toBeBool();
    });

    it('keeps the timezone it was created with, not the server one', function () {
        $challenge = Challenge::factory()->timeline('2026-03-01 00:00:00', 'Asia/Tehran')->create();

        expect($challenge->fresh()?->timezone)->toBe('Asia/Tehran');
    });

    it('reports a custom day count only for the custom period type', function () {
        $custom = Challenge::factory()->every(PeriodType::Custom, 5)->create();
        $daily = Challenge::factory()->every(PeriodType::Daily)->create();

        expect($custom->customPeriodDays())->toBe(5)
            ->and($daily->customPeriodDays())->toBeNull();
    });

    it('publishes proof only when the creator opted in and the type can honour it', function (ProofType $proofType, bool $optedIn, bool $expected) {
        $challenge = Challenge::factory()->provenBy($proofType, $optedIn)->create();

        expect($challenge->sharesProofPublicly())->toBe($expected);
    })->with([
        'image proof, opted in' => [ProofType::ImageApproval, true, true],
        'image proof, not opted in' => [ProofType::ImageApproval, false, false],
        // A tap has nothing to show.
        'button, opted in' => [ProofType::Button, true, false],
        // Publishing the phrase would hand everyone else the answer.
        'autogen phrase, opted in' => [ProofType::TextAutogen, true, false],
    ]);

    it('needs announcing only while public and unannounced', function () {
        expect(Challenge::factory()->publiclyVisible()->create()->awaitsAnnouncement())->toBeTrue()
            ->and(Challenge::factory()->announced()->create()->awaitsAnnouncement())->toBeFalse()
            ->and(Challenge::factory()->create()->awaitsAnnouncement())->toBeFalse();
    });

    it('scopes to the challenges a user may still join', function () {
        Challenge::factory()->create();
        Challenge::factory()->active()->create();
        Challenge::factory()->completed()->create();
        Challenge::factory()->cancelled()->create();

        expect(Challenge::query()->joinable()->count())->toBe(2)
            ->and(Challenge::query()->active()->count())->toBe(1);
    });

    it('cascades its timeline and roster away when deleted', function () {
        $challenge = Challenge::factory()->active()->create();
        $period = ChallengePeriod::factory()->for($challenge)->create();
        $participant = ChallengeParticipant::factory()->for($challenge)->create();
        CheckIn::factory()->on($participant, $period)->create();

        $challenge->delete();

        expect(ChallengePeriod::query()->count())->toBe(0)
            ->and(ChallengeParticipant::query()->count())->toBe(0)
            ->and(CheckIn::query()->count())->toBe(0);
    });

    it('is reachable from its creator', function () {
        $creator = User::factory()->telegram()->create();
        Challenge::factory()->for($creator, 'creator')->count(2)->create();

        expect($creator->createdChallenges)->toHaveCount(2);
    });
});

describe('challenge periods', function () {
    it('cannot materialise the same index twice for one challenge', function () {
        // This unique index is what makes period materialisation safe to re-run.
        $challenge = Challenge::factory()->active()->create();
        ChallengePeriod::factory()->for($challenge)->atIndex(3)->create();

        expect(fn () => ChallengePeriod::factory()->for($challenge)->atIndex(3)->create())
            ->toThrow(QueryException::class);
    });

    it('allows the same index on a different challenge', function () {
        ChallengePeriod::factory()->atIndex(0)->create();
        ChallengePeriod::factory()->atIndex(0)->create();

        expect(ChallengePeriod::query()->where('index', 0)->count())->toBe(2);
    });

    it('treats the window as half-open, so no instant belongs to two periods', function () {
        $challenge = Challenge::factory()->active()->create();
        $first = ChallengePeriod::factory()->for($challenge)->atIndex(0)->create();
        $second = ChallengePeriod::factory()->for($challenge)->atIndex(1)->create();

        // The shared boundary instant belongs to the later period only.
        $boundary = $first->ends_at;

        expect($second->starts_at->equalTo($boundary))->toBeTrue()
            ->and($first->contains($boundary))->toBeFalse()
            ->and($second->contains($boundary))->toBeTrue()
            ->and($first->contains($first->starts_at))->toBeTrue();
    });

    it('reads back the reserved-word index column', function () {
        // `index` is a MySQL keyword; the query builder has to quote it.
        $period = ChallengePeriod::factory()->atIndex(7)->create();

        expect($period->fresh()?->index)->toBe(7)
            ->and(ChallengePeriod::query()->where('index', 7)->orderBy('index')->exists())->toBeTrue();
    });

    it('orders a challenge timeline by index, not insertion order', function () {
        $challenge = Challenge::factory()->active()->create();

        foreach ([2, 0, 1] as $index) {
            ChallengePeriod::factory()->for($challenge)->atIndex($index)->create();
        }

        expect($challenge->periods->pluck('index')->all())->toBe([0, 1, 2]);
    });

    it('queues only elapsed, unsettled periods for rollover', function () {
        ChallengePeriod::factory()->ended()->create();
        ChallengePeriod::factory()->rolledOver()->create();
        ChallengePeriod::factory()->create();

        expect(ChallengePeriod::query()->awaitingRollover()->count())->toBe(1);
    });

    it('knows whether it has ended and whether it has been settled', function () {
        $open = ChallengePeriod::factory()->create();
        $ended = ChallengePeriod::factory()->ended()->create();
        $settled = ChallengePeriod::factory()->rolledOver()->create();

        expect($open->hasEnded())->toBeFalse()
            ->and($ended->hasEnded())->toBeTrue()
            ->and($ended->isRolledOver())->toBeFalse()
            ->and($settled->isRolledOver())->toBeTrue();
    });
});

describe('challenge participants', function () {
    it('cannot join the same challenge twice', function () {
        $challenge = Challenge::factory()->active()->create();
        $user = User::factory()->telegram()->create();
        ChallengeParticipant::factory()->for($challenge)->for($user)->create();

        expect(fn () => ChallengeParticipant::factory()->for($challenge)->for($user)->create())
            ->toThrow(QueryException::class);
    });

    it('reports freezes remaining without going negative', function () {
        expect(ChallengeParticipant::factory()->withFreezes(3, 1)->create()->freezesRemaining())->toBe(2)
            ->and(ChallengeParticipant::factory()->withFreezes(2, 2)->create()->hasFreezeAvailable())->toBeFalse()
            // Defensive: a hand-edited row must not produce a negative count.
            ->and(ChallengeParticipant::factory()->withFreezes(1, 5)->create()->freezesRemaining())->toBe(0);
    });

    it('does not owe periods that closed before the participant joined', function () {
        $challenge = Challenge::factory()->active()->create();
        $participant = ChallengeParticipant::factory()->for($challenge)->joinedAtPeriod(2)->create();

        $before = ChallengePeriod::factory()->for($challenge)->atIndex(1)->create();
        $onJoin = ChallengePeriod::factory()->for($challenge)->atIndex(2)->create();
        $after = ChallengePeriod::factory()->for($challenge)->atIndex(3)->create();

        expect($participant->owesPeriod($before))->toBeFalse()
            ->and($participant->owesPeriod($onJoin))->toBeTrue()
            ->and($participant->owesPeriod($after))->toBeTrue();
    });

    it('owes nothing once the participation has ended', function (ParticipantStatus $status) {
        $challenge = Challenge::factory()->active()->create();
        $participant = ChallengeParticipant::factory()->for($challenge)->create(['status' => $status]);
        $period = ChallengePeriod::factory()->for($challenge)->atIndex(0)->create();

        expect($participant->owesPeriod($period))->toBe($status === ParticipantStatus::Active);
    })->with([
        'active' => [ParticipantStatus::Active],
        'completed' => [ParticipantStatus::Completed],
        'left' => [ParticipantStatus::Left],
        'removed' => [ParticipantStatus::Removed],
    ]);

    it('starts every counter at zero, including the reset tally', function () {
        $participant = ChallengeParticipant::factory()->create()->fresh();

        expect($participant?->current_streak)->toBe(0)
            ->and($participant?->longest_streak)->toBe(0)
            ->and($participant?->streak_resets_count)->toBe(0)
            ->and($participant?->status)->toBe(ParticipantStatus::Active);
    });
});

describe('check-ins', function () {
    it('allows exactly one row per participant per period', function () {
        // The single most important constraint in the schema: it is what makes a
        // double-tap, a retried webhook and a re-run rollover converge.
        $challenge = Challenge::factory()->active()->create();
        $participant = ChallengeParticipant::factory()->for($challenge)->create();
        $period = ChallengePeriod::factory()->for($challenge)->atIndex(0)->create();

        CheckIn::factory()->on($participant, $period)->create();

        expect(fn () => CheckIn::factory()->on($participant, $period)->create())
            ->toThrow(QueryException::class);
    });

    it('lets one participant check in across many periods', function () {
        $challenge = Challenge::factory()->active()->create();
        $participant = ChallengeParticipant::factory()->for($challenge)->create();

        foreach ([0, 1, 2] as $index) {
            $period = ChallengePeriod::factory()->for($challenge)->atIndex($index)->create();
            CheckIn::factory()->on($participant, $period)->approved()->create();
        }

        expect($participant->checkIns()->count())->toBe(3);
    });

    it('resolves both sides of the obligation', function () {
        $challenge = Challenge::factory()->active()->create();
        $participant = ChallengeParticipant::factory()->for($challenge)->create();
        $period = ChallengePeriod::factory()->for($challenge)->atIndex(0)->create();

        $checkIn = CheckIn::factory()->on($participant, $period)->create();

        expect($checkIn->participant->id)->toBe($participant->id)
            ->and($checkIn->period->id)->toBe($period->id)
            ->and($checkIn->reviewer)->toBeNull();
    });

    it('keeps the check-in when its reviewer is deleted', function () {
        $reviewer = User::factory()->telegram()->create();
        $checkIn = CheckIn::factory()->approved($reviewer)->create();

        $reviewer->delete();

        expect($checkIn->fresh())->not->toBeNull()
            ->and($checkIn->fresh()?->reviewed_by)->toBeNull()
            ->and($checkIn->fresh()?->status)->toBe(CheckInStatus::Approved);
    });

    it('scopes the review queue and the rollover queue', function () {
        CheckIn::factory()->submitted()->create();
        CheckIn::factory()->rejected()->create();
        CheckIn::factory()->create();
        CheckIn::factory()->approved()->create();
        CheckIn::factory()->missed()->create();
        CheckIn::factory()->frozen()->create();

        expect(CheckIn::query()->awaitingReview()->count())->toBe(1)
            // Pending, submitted and rejected are all still the rollover's problem.
            ->and(CheckIn::query()->unsettled()->count())->toBe(3);
    });

    it('keeps what the participant typed even when it does not match', function () {
        $checkIn = CheckIn::factory()->withPhrase('blue harbour lantern')->create([
            'submitted_text' => 'blue harbor lantern',
        ]);

        expect($checkIn->fresh()?->submitted_text)->toBe('blue harbor lantern')
            ->and($checkIn->matchesExpectedPhrase('blue harbor lantern'))->toBeFalse();
    });

    it('stores a storage path for proof, never a URL', function () {
        $checkIn = CheckIn::factory()->submitted()->create();

        expect($checkIn->proof_path)->toStartWith('proofs/')
            ->and($checkIn->proof_path)->not->toContain('http');
    });
});

describe('phrase matching', function () {
    it('accepts the phrase back exactly', function () {
        $checkIn = CheckIn::factory()->withPhrase('quiet copper river')->make();

        expect($checkIn->matchesExpectedPhrase('quiet copper river'))->toBeTrue();
    });

    it('forgives only what should not decide a check-in', function (string $submitted, bool $expected) {
        $checkIn = CheckIn::factory()->withPhrase('quiet copper river')->make();

        expect($checkIn->matchesExpectedPhrase($submitted))->toBe($expected);
    })->with([
        'surrounding whitespace' => ['  quiet copper river  ', true],
        'repeated inner whitespace' => ['quiet   copper  river', true],
        'a newline instead of a space' => ["quiet copper\nriver", true],
        'different casing' => ['Quiet Copper River', true],
        'a near miss' => ['quiet copper rivers', false],
        'a missing word' => ['quiet river', false],
        'reordered words' => ['river copper quiet', false],
        'empty' => ['', false],
    ]);

    it('folds the Arabic and Persian glyphs that keyboards disagree about', function () {
        // A Farsi speaker typing on an Arabic keyboard sends ي and ك where we
        // issued ی and ک. That is a keyboard difference, not a wrong answer.
        $checkIn = CheckIn::factory()->withPhrase('یک کبوتر آبی')->make();

        expect($checkIn->matchesExpectedPhrase('يك كبوتر آبي'))->toBeTrue();
    });

    it('folds Eastern Arabic digits to their ASCII equivalents', function () {
        $checkIn = CheckIn::factory()->withPhrase('lantern 42')->make();

        expect($checkIn->matchesExpectedPhrase('lantern ۴۲'))->toBeTrue()
            ->and($checkIn->matchesExpectedPhrase('lantern ٤٢'))->toBeTrue()
            ->and($checkIn->matchesExpectedPhrase('lantern 43'))->toBeFalse();
    });

    it('treats the zero-width non-joiner as a space', function () {
        // Farsi text is full of ZWNJ; whether one was typed must not decide this.
        $checkIn = CheckIn::factory()->withPhrase('می روم')->make();

        expect($checkIn->matchesExpectedPhrase("می\u{200C}روم"))->toBeTrue();
    });

    it('never matches when no phrase was issued', function () {
        $checkIn = CheckIn::factory()->make();

        expect($checkIn->matchesExpectedPhrase('anything'))->toBeFalse()
            ->and($checkIn->matchesExpectedPhrase(null))->toBeFalse();
    });

    it('never matches an empty submission against an empty phrase', function () {
        // A blank `expected_phrase` is corrupt data, not a free pass.
        $checkIn = CheckIn::factory()->withPhrase('   ')->make();

        expect($checkIn->matchesExpectedPhrase('  '))->toBeFalse();
    });
});

describe('enum labels', function () {
    it('translates every case of every domain enum in both locales', function (string $enum) {
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
        PeriodType::class,
        ChallengeVisibility::class,
        ProofType::class,
        ChallengeStatus::class,
        ParticipantStatus::class,
        CheckInStatus::class,
    ]);

    it('derives the translation group from the enum class name', function () {
        app()->setLocale('en');

        expect(CheckInStatus::Approved->label())->toBe(__('enums.check_in_status.approved'))
            ->and(PeriodType::Daily->label())->toBe('Daily');
    });

    it('offers every case as a value => label map for pickers and keyboards', function () {
        app()->setLocale('en');

        expect(PeriodType::options())
            ->toHaveCount(count(PeriodType::cases()))
            ->toHaveKeys(['daily', 'weekly', 'monthly', 'seasonal', 'yearly', 'custom'])
            ->and(PeriodType::options()['custom'])->toBe('Custom');
    });

    it('translates to Farsi when the locale is Farsi', function () {
        app()->setLocale('fa');

        expect(PeriodType::Daily->label())->toBe('روزانه')
            ->and(CheckInStatus::Frozen->label())->toBe('فریز شد');
    });
});

describe('enum behaviour', function () {
    it('requires a day count only for the custom period type', function (PeriodType $type, bool $expected) {
        expect($type->requiresCustomDays())->toBe($expected);
    })->with([
        'daily' => [PeriodType::Daily, false],
        'weekly' => [PeriodType::Weekly, false],
        'monthly' => [PeriodType::Monthly, false],
        'seasonal' => [PeriodType::Seasonal, false],
        'yearly' => [PeriodType::Yearly, false],
        'custom' => [PeriodType::Custom, true],
    ]);

    it('auto-approves everything except a photo', function () {
        expect(ProofType::Button->isAutoApproved())->toBeTrue()
            ->and(ProofType::TextAutogen->isAutoApproved())->toBeTrue()
            ->and(ProofType::ImageApproval->isAutoApproved())->toBeFalse()
            ->and(ProofType::ImageApproval->requiresReview())->toBeTrue();
    });

    it('knows what a submission is expected to carry', function () {
        expect(ProofType::TextAutogen->expectsText())->toBeTrue()
            ->and(ProofType::TextAutogen->expectsFile())->toBeFalse()
            ->and(ProofType::ImageApproval->expectsFile())->toBeTrue()
            ->and(ProofType::Button->expectsText())->toBeFalse()
            ->and(ProofType::Button->expectsFile())->toBeFalse();
    });

    it('announces public challenges only', function () {
        expect(ChallengeVisibility::Public->shouldAnnounce())->toBeTrue()
            ->and(ChallengeVisibility::InviteOnly->shouldAnnounce())->toBeFalse();
    });

    it('accepts late joins into a running challenge', function () {
        // Late joiners catch up on the shared timeline, so joining mid-flight is
        // allowed on purpose.
        expect(ChallengeStatus::Active->acceptsJoins())->toBeTrue()
            ->and(ChallengeStatus::Scheduled->acceptsJoins())->toBeTrue()
            ->and(ChallengeStatus::Completed->acceptsJoins())->toBeFalse()
            ->and(ChallengeStatus::Cancelled->acceptsJoins())->toBeFalse();
    });

    it('accepts check-ins only while running', function () {
        expect(ChallengeStatus::Active->acceptsCheckIns())->toBeTrue()
            ->and(ChallengeStatus::Scheduled->acceptsCheckIns())->toBeFalse();
    });

    it('treats completed and cancelled as terminal', function () {
        expect(ChallengeStatus::Completed->isTerminal())->toBeTrue()
            ->and(ChallengeStatus::Cancelled->isTerminal())->toBeTrue()
            ->and(ChallengeStatus::Active->isTerminal())->toBeFalse();
    });

    it('has no participant status for repeated failure', function () {
        // Missing periods costs the streak and nothing else. `Removed` is for
        // moderation; there is deliberately no "failed out".
        expect(array_column(ParticipantStatus::cases(), 'value'))
            ->toBe(['active', 'completed', 'left', 'removed']);
    });

    it('settles a check-in only once its outcome is final', function (CheckInStatus $status, bool $settled) {
        expect($status->isSettled())->toBe($settled);
    })->with([
        'pending' => [CheckInStatus::Pending, false],
        'submitted' => [CheckInStatus::Submitted, false],
        // Rejected is not an ending: resubmission is allowed until the period closes.
        'rejected' => [CheckInStatus::Rejected, false],
        'approved' => [CheckInStatus::Approved, true],
        'missed' => [CheckInStatus::Missed, true],
        'frozen' => [CheckInStatus::Frozen, true],
    ]);

    it('lets a participant resubmit after a rejection', function () {
        expect(CheckInStatus::Rejected->allowsSubmission())->toBeTrue()
            ->and(CheckInStatus::Pending->allowsSubmission())->toBeTrue()
            ->and(CheckInStatus::Approved->allowsSubmission())->toBeFalse()
            ->and(CheckInStatus::Missed->allowsSubmission())->toBeFalse();
    });

    it('extends a streak on approval but only protects it on a freeze', function () {
        // A freeze buys you out of the penalty; it does not count as doing the thing.
        expect(CheckInStatus::Approved->incrementsStreak())->toBeTrue()
            ->and(CheckInStatus::Frozen->incrementsStreak())->toBeFalse()
            ->and(CheckInStatus::Frozen->preservesStreak())->toBeTrue()
            ->and(CheckInStatus::Approved->preservesStreak())->toBeTrue();
    });

    it('breaks a streak on a miss and nothing else', function (CheckInStatus $status) {
        expect($status->breaksStreak())->toBe($status === CheckInStatus::Missed);
    })->with([
        'pending' => [CheckInStatus::Pending],
        'submitted' => [CheckInStatus::Submitted],
        'rejected' => [CheckInStatus::Rejected],
        'approved' => [CheckInStatus::Approved],
        'missed' => [CheckInStatus::Missed],
        'frozen' => [CheckInStatus::Frozen],
    ]);
});

describe('enum values are stable', function () {
    it('stores the enum value, not its name, so a rename cannot orphan rows', function () {
        $challenge = Challenge::factory()->every(PeriodType::Seasonal)->active()->create();

        $stored = DB::table('challenges')->where('id', $challenge->id)->first();

        expect($stored?->period_type)->toBe('seasonal')
            ->and($stored?->status)->toBe('active');
    });
});
