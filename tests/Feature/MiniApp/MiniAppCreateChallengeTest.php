<?php

use App\Actions\Challenges\CreateChallenge;
use App\Actions\Entitlements\ConsumeEntitlement;
use App\Enums\ChallengeVisibility;
use App\Enums\CoinTransactionReason;
use App\Enums\EntitlementType;
use App\Enums\FlowType;
use App\Enums\MessagingPlatform;
use App\Enums\PeriodType;
use App\Enums\ProofType;
use App\Enums\SettingKey;
use App\Jobs\Challenges\AnnounceChallenge;
use App\Models\Challenge;
use App\Models\ChallengeParticipant;
use App\Models\ChallengePeriod;
use App\Models\Entitlement;
use App\Models\User;
use App\Services\CoinLedger;
use App\Services\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
 * Creating a challenge from the Mini App.
 *
 * The endpoint carries no creation rule of its own — it calls the same
 * `CreateChallenge` action the bot's wizard and the admin panel call — so what
 * is under test here is the wiring: auth, the re-verified channel gate, the
 * refusals as reasons, and the promise that a spent slot is always accompanied
 * by a challenge and a materialised timeline.
 *
 * Three of these are worth more than the rest because they are the properties a
 * later "fix" at the controller layer would quietly break: **nothing is written
 * when the create is refused** (no challenge, no spent slot), **the options
 * endpoint never spends** (it is a read, and a form that charged for being
 * looked at would be a slot stolen), and **the creator is not enrolled** by the
 * act of creating.
 *
 * The media-cap bullets are split deliberately. "A `voice_approval` challenge
 * with no caps is refused by the action" is already pinned at the domain layer
 * by `tests/Feature/Domain/ProofMediaCapsTest.php` — `it('refuses a recording
 * challenge with no caps to enforce')` — and that is where it belongs: through
 * this endpoint it can never be reached, because the Form Request is the
 * friendly layer in front of the action and refuses a missing cap with a 422
 * first. What is asserted here is the half this endpoint owns: the ceiling the
 * options payload reports, and the over-ceiling refusal that 422s and writes
 * nothing.
 */

/**
 * A user with a working Mini App bearer token and a spendable create-slot.
 *
 * Named apart from `aMiniAppJoiner`/`aMiniAppActor`/`aMiniAppUser` on purpose:
 * these are global functions, and two files declaring the same one is a fatal
 * redeclare rather than a test failure. The same applies to every helper below.
 */
function aMiniAppCreator(int $telegramId = 777_004_0, bool $channelVerified = true): User
{
    $user = User::factory()
        ->telegram($telegramId)
        ->when($channelVerified, fn ($factory) => $factory->channelVerified())
        ->preferring('en')
        ->create();

    test()->withToken($user->createToken('miniapp', ['miniapp'])->plainTextToken);

    return $user;
}

/**
 * Hand the user a create-slot without buying it.
 *
 * `withCreateSlots` and `grantCreateSlots` both exist in other files, and a
 * global function may only be declared once.
 */
function aCreateSlotFor(User $user): void
{
    Entitlement::factory()->createSlot()->create(['user_id' => $user->getKey()]);
}

/**
 * The create body, naming only what the test is about.
 *
 * Every field the Form Request requires is present, because a test about one
 * field should not have to restate the other fourteen. The defaults describe a
 * plain daily challenge starting tomorrow in UTC — the least interesting
 * challenge there is, so that whatever a test overrides is what it is about.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function createChallengeBody(array $overrides = []): array
{
    return array_replace([
        'title' => 'Read every day',
        'description' => 'Twenty pages, no excuses.',
        'period_type' => PeriodType::Daily->value,
        'timezone' => 'UTC',
        'starts_at' => CarbonImmutable::now('UTC')->addDay()->toDateString(),
        'total_periods' => 30,
        'proof_type' => ProofType::Button->value,
        'visibility' => ChallengeVisibility::InviteOnly->value,
        'scoring_type' => 'binary',
    ], $overrides);
}

function createPath(): string
{
    return '/api/v1/miniapp/challenges';
}

/**
 * Telegram's `getChatMember` answer for the required channel.
 *
 * Local to this file because `chatMemberSays()` is already declared globally by
 * `MiniAppJoinTest`.
 */
function createGateAnswers(string $status, int $telegramId = 777_004_0): void
{
    Http::fake([
        '*getChatMember*' => Http::response([
            'ok' => true,
            'result' => [
                'status' => $status,
                'user' => ['id' => $telegramId, 'is_bot' => false, 'first_name' => 'Sara'],
            ],
        ]),
    ]);
}

beforeEach(function () {
    // A `public` create dispatches the announcement after commit, and the
    // guardrail is that no test ever reaches a real Telegram endpoint.
    Bus::fake([AnnounceChallenge::class]);

    config(['services.telegram.bot_token' => '123456:TEST-TOKEN']);
    config(['services.telegram.bot_username' => 'ChallengesBot']);

    app(Settings::class)->set(SettingKey::RequiredChannel, '@challenges');
});

it('refuses a create with no token', function () {
    test()->postJson(createPath(), createChallengeBody())->assertStatus(401);
});

describe('creating', function () {
    it('creates the challenge, spending exactly one create-slot', function () {
        $user = aMiniAppCreator();
        aCreateSlotFor($user);

        $response = test()->postJson(createPath(), createChallengeBody([
            'title' => '  Read every day  ',
            'timezone' => 'Asia/Tehran',
            'starts_at' => CarbonImmutable::now('Asia/Tehran')->addDay()->toDateString(),
            'total_periods' => 12,
            'proof_type' => ProofType::TextAutogen->value,
        ]))->assertStatus(201);

        $challenge = Challenge::query()->sole();

        // Every field the creator chose, as stored — the trimmed title and the
        // timeline the action materialised are the two a caller most often
        // assumes rather than checks.
        expect($challenge->title)->toBe('Read every day')
            ->and($challenge->creator_id)->toBe($user->getKey())
            ->and($challenge->timezone)->toBe('Asia/Tehran')
            ->and($challenge->total_periods)->toBe(12)
            ->and($challenge->proof_type)->toBe(ProofType::TextAutogen)
            ->and(ChallengePeriod::query()->where('challenge_id', $challenge->getKey())->count())->toBe(12);

        // A slot spent with no challenge is a slot stolen; a challenge with no
        // slot spent is a free extra one. Both directions, one assertion each.
        $spent = Entitlement::query()->whereNotNull('consumed_at')->get();

        expect($spent)->toHaveCount(1)
            ->and($spent->first()?->type)->toBe(EntitlementType::CreateSlot)
            ->and($spent->first()?->challenge_id)->toBe($challenge->getKey())
            ->and(app(ConsumeEntitlement::class)->available($user, EntitlementType::CreateSlot))->toBe(0);

        // The response is the new state, not a patch to merge.
        $response->assertJsonPath('data.id', $challenge->getKey())
            ->assertJsonPath('data.is_creator', true)
            ->assertJsonPath('data.proof_type.value', ProofType::TextAutogen->value);
    });

    it('stores the start date as the instant that date names in the challenge timezone', function () {
        $user = aMiniAppCreator();
        aCreateSlotFor($user);

        // Noon in Tehran on this date is already the same day in UTC, so a
        // controller that forgot the timezone and parsed the bare date as UTC
        // would pass a lazier assertion. 00:30 Tehran is 21:00 the *previous*
        // day UTC, which only the correct conversion produces.
        $today = CarbonImmutable::now('Asia/Tehran')->startOfDay();

        test()->postJson(createPath(), createChallengeBody([
            'timezone' => 'Asia/Tehran',
            'starts_at' => $today->addDay()->toDateString(),
        ]))->assertStatus(201);

        expect(Challenge::query()->sole()->starts_at->equalTo(
            CarbonImmutable::parse($today->addDay()->toDateString(), 'Asia/Tehran')->startOfDay()->utc(),
        ))->toBeTrue();
    });

    it('refuses a start date before today in the challenge own timezone, writing nothing', function () {
        $user = aMiniAppCreator();
        aCreateSlotFor($user);

        // Still yesterday in Tehran while it is already tomorrow in Auckland:
        // the refusal is measured against the challenge's zone, not the
        // server's and not the reader's.
        $response = test()->postJson(createPath(), createChallengeBody([
            'timezone' => 'Asia/Tehran',
            'starts_at' => CarbonImmutable::now('Asia/Tehran')->subDay()->toDateString(),
        ]))->assertStatus(422);

        expect($response->json('reason'))->toBe('past_start_date')
            ->and(Challenge::query()->count())->toBe(0)
            ->and(Entitlement::query()->whereNotNull('consumed_at')->count())->toBe(0);
    });

    it('refuses a start date that names no day at all, writing nothing', function () {
        $user = aMiniAppCreator();
        aCreateSlotFor($user);

        // 31 April. Carbon would roll it into 1 May, so a server that let the
        // string reach `CarbonImmutable::parse()` unchecked would silently move
        // the creator a day. The request's `date_format` rule catches it first
        // — the controller's `checkdate` backstop is behind that, and this
        // asserts the refusal rather than which layer produced it.
        test()->postJson(createPath(), createChallengeBody([
            'starts_at' => '2027-04-31',
        ]))->assertStatus(422)->assertJsonValidationErrors('starts_at');

        expect(Challenge::query()->count())->toBe(0)
            ->and(Entitlement::query()->whereNotNull('consumed_at')->count())->toBe(0);
    });

    it('does not enrol the creator in the challenge they just made', function () {
        $user = aMiniAppCreator();
        aCreateSlotFor($user);

        test()->postJson(createPath(), createChallengeBody())
            ->assertStatus(201)
            // Both flags at once, which is the whole point: running a challenge
            // and being in it are different questions, and creating answers
            // only the first.
            ->assertJsonPath('data.is_creator', true)
            ->assertJsonPath('data.has_joined', false)
            ->assertJsonPath('data.me', null);

        expect(ChallengeParticipant::query()->count())->toBe(0);
    });

    it('shows the created challenge to its creator in the listing', function () {
        $user = aMiniAppCreator();
        aCreateSlotFor($user);

        $created = test()->postJson(createPath(), createChallengeBody())->assertStatus(201);

        // Task 2's union — created challenges are listed alongside joined ones —
        // is what makes "the created challenge lands in the list" true without
        // a second lookup, and this is the two tasks' contract asserted
        // together rather than assumed.
        $listed = test()->getJson(createPath())->assertOk();

        expect($listed->json('data'))->toHaveCount(1)
            ->and($listed->json('data.0.id'))->toBe($created->json('data.id'))
            ->and($listed->json('data.0.is_creator'))->toBeTrue()
            ->and($listed->json('data.0.has_joined'))->toBeFalse();
    });

    it('announces a public challenge and stays quiet about a private one', function () {
        $user = aMiniAppCreator();
        aCreateSlotFor($user);
        aCreateSlotFor($user);

        test()->postJson(createPath(), createChallengeBody([
            'visibility' => ChallengeVisibility::InviteOnly->value,
        ]))->assertStatus(201);

        Bus::assertNotDispatched(AnnounceChallenge::class);

        test()->postJson(createPath(), createChallengeBody([
            'title' => 'Read even more',
            'visibility' => ChallengeVisibility::Public->value,
        ]))->assertStatus(201);

        Bus::assertDispatched(AnnounceChallenge::class);
    });
});

describe('the form request', function () {
    it('refuses malformed input with 422 and writes nothing', function (array $overrides, string $field) {
        $user = aMiniAppCreator();
        aCreateSlotFor($user);

        test()->postJson(createPath(), createChallengeBody($overrides))
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);

        // A 422 that had already written a row would be worse than a 500: the
        // caller sees a refusal and has been charged for it.
        expect(Challenge::query()->count())->toBe(0)
            ->and(Entitlement::query()->whereNotNull('consumed_at')->count())->toBe(0);
    })->with([
        'a blank title' => [['title' => ''], 'title'],
        'a one-character title' => [['title' => 'a'], 'title'],
        'too many periods' => [['total_periods' => 1001], 'total_periods'],
        'no periods at all' => [['total_periods' => 0], 'total_periods'],
        'an unknown timezone' => [['timezone' => 'Nowhere/Nowhere'], 'timezone'],
        'a custom period with no days' => [
            ['period_type' => PeriodType::Custom->value], 'custom_period_days',
        ],
        'a day count past the ceiling' => [
            ['period_type' => PeriodType::Custom->value, 'custom_period_days' => 366], 'custom_period_days',
        ],
        'a localised date string' => [['starts_at' => '01/10/2026'], 'starts_at'],
        'a quantity challenge with no target' => [
            ['scoring_type' => 'quantity', 'unit_label' => 'pages', 'base_points' => 10], 'target_value',
        ],
        'a quantity challenge with no unit' => [
            ['scoring_type' => 'quantity', 'target_value' => 20, 'base_points' => 10], 'unit_label',
        ],
        'a scoring block on a binary challenge' => [
            ['scoring_type' => 'binary', 'target_value' => 20], 'target_value',
        ],
        'a voice challenge with no duration cap' => [
            ['proof_type' => ProofType::VoiceApproval->value, 'proof_media_max_size_kb' => 5120],
            'proof_media_max_seconds',
        ],
        'a voice challenge with no size cap' => [
            ['proof_type' => ProofType::VoiceApproval->value, 'proof_media_max_seconds' => 120],
            'proof_media_max_size_kb',
        ],
        'an unknown proof type' => [['proof_type' => 'telepathy'], 'proof_type'],
        'an unknown visibility' => [['visibility' => 'secret'], 'visibility'],
    ]);

    it('drops the fields a challenge has no use for rather than refusing them', function () {
        $user = aMiniAppCreator();
        aCreateSlotFor($user);

        // Both are leftovers a real form produces: the creator picked a custom
        // cadence and a voice proof, filled the fields that appeared, then
        // changed their mind. The action degrades them to null exactly as
        // `assertMediaCaps()` documents, and refusing here would block a
        // submission over a field the screen no longer shows.
        test()->postJson(createPath(), createChallengeBody([
            'period_type' => PeriodType::Daily->value,
            'custom_period_days' => 3,
            'proof_type' => ProofType::Button->value,
            'proof_media_max_seconds' => 120,
            'proof_media_max_size_kb' => 8192,
        ]))->assertStatus(201);

        $challenge = Challenge::query()->sole();

        expect($challenge->custom_period_days)->toBeNull()
            ->and($challenge->proof_media_max_seconds)->toBeNull()
            ->and($challenge->proof_media_max_size_kb)->toBeNull();
    });
});

describe('the media caps', function () {
    it('refuses a cap above the ceiling the options reported, writing nothing', function () {
        $user = aMiniAppCreator();
        aCreateSlotFor($user);

        app(Settings::class)->set(SettingKey::ProofMediaMaxSeconds, 120);
        app(Settings::class)->set(SettingKey::ProofMediaMaxSizeKb, 4096);

        $ceiling = test()->getJson(createPath().'/options')->assertOk();

        test()->postJson(createPath(), createChallengeBody([
            'proof_type' => ProofType::VoiceApproval->value,
            'proof_media_max_seconds' => 121,
            'proof_media_max_size_kb' => 4096,
        ]))->assertStatus(422)->assertJsonValidationErrors('proof_media_max_seconds');

        test()->postJson(createPath(), createChallengeBody([
            'proof_type' => ProofType::VideoApproval->value,
            'proof_media_max_seconds' => 120,
            'proof_media_max_size_kb' => 4097,
        ]))->assertStatus(422)->assertJsonValidationErrors('proof_media_max_size_kb');

        // The form clamps to what this reported, so the two must be the same
        // numbers the validator used — read at the tap, not mirrored anywhere.
        expect($ceiling->json('media_caps.max_seconds'))->toBe(120)
            ->and($ceiling->json('media_caps.max_size_kb'))->toBe(4096)
            ->and(Challenge::query()->count())->toBe(0);
    });

    it('creates a recording challenge that carries its caps', function () {
        $user = aMiniAppCreator();
        aCreateSlotFor($user);

        test()->postJson(createPath(), createChallengeBody([
            'proof_type' => ProofType::VideoApproval->value,
            'proof_media_max_seconds' => 90,
            'proof_media_max_size_kb' => 8192,
        ]))->assertStatus(201);

        $challenge = Challenge::query()->sole();

        expect($challenge->proof_media_max_seconds)->toBe(90)
            ->and($challenge->proof_media_max_size_kb)->toBe(8192);
    });
});

describe('the refusals', function () {
    it('refuses with the price and the real balance when there is no create-slot', function () {
        $user = aMiniAppCreator();

        app(Settings::class)->set(SettingKey::CreateSlotCoinPrice, 40);
        app(CoinLedger::class)->credit($user, 7, CoinTransactionReason::AdminCredit, "float:{$user->getKey()}");

        $response = test()->postJson(createPath(), createChallengeBody())->assertStatus(402);

        // The exact body, not just its fields: this refusal is a quote, and a
        // field that creeps in here is a field the SPA starts depending on.
        expect($response->json())->toBe([
            'reason' => 'no_create_slot',
            'price' => 40,
            'balance' => 7,
        ]);

        expect(Challenge::query()->count())->toBe(0)
            ->and(Entitlement::query()->whereNotNull('consumed_at')->count())->toBe(0);
    });

    it('quotes the price configured at the tap, not a number in the code', function () {
        $user = aMiniAppCreator();

        app(CoinLedger::class)->credit($user, 1000, CoinTransactionReason::AdminCredit, "float:{$user->getKey()}");

        $before = test()->postJson(createPath(), createChallengeBody())->assertStatus(402);

        app(Settings::class)->set(SettingKey::CreateSlotCoinPrice, 95);

        $after = test()->postJson(createPath(), createChallengeBody())->assertStatus(402);

        expect($before->json('price'))->toBe(50)
            ->and($after->json('price'))->toBe(95)
            ->and($after->json('balance'))->toBe($before->json('balance'));
    });
});

describe('the channel gate', function () {
    it('blocks the create behind the gate, with the join link, and writes nothing', function () {
        $user = aMiniAppCreator(channelVerified: false);
        aCreateSlotFor($user);

        createGateAnswers('left');

        test()->postJson(createPath(), createChallengeBody())
            ->assertStatus(403)
            ->assertJsonPath('reason', 'channel_gate')
            ->assertJsonPath('join_url', 'https://t.me/challenges');

        expect(Challenge::query()->count())->toBe(0)
            ->and(Entitlement::query()->whereNotNull('consumed_at')->count())->toBe(0);
    });

    it('re-verifies a stale gate against Telegram and lets a member through', function () {
        // Verified an hour ago — long past the freshness window — so this only
        // passes if the gate actually asked Telegram again rather than trusting
        // a stamp the bearer token was minted alongside.
        $user = aMiniAppCreator();
        $user->forceFill(['channel_verified_at' => now()->subHour()])->save();
        aCreateSlotFor($user);

        createGateAnswers('member');

        test()->postJson(createPath(), createChallengeBody())->assertStatus(201);

        expect(Challenge::query()->count())->toBe(1);
    });

    it('refuses a gate-blocked create before the slot is even looked at', function () {
        $user = aMiniAppCreator(channelVerified: false);
        aCreateSlotFor($user);

        createGateAnswers('left');

        // No slot is spent and no challenge written, but the refusal is the
        // gate's — a quote for a slot the user never got far enough to need
        // would be the wrong sentence entirely.
        $response = test()->postJson(createPath(), createChallengeBody())->assertStatus(403);

        expect($response->json('reason'))->toBe('channel_gate')
            ->and(app(ConsumeEntitlement::class)->available($user, EntitlementType::CreateSlot))->toBe(1);
    });
});

describe('the options endpoint', function () {
    it('never spends, however many times it is asked', function () {
        $user = aMiniAppCreator();
        aCreateSlotFor($user);

        for ($call = 0; $call < 10; $call++) {
            test()->getJson(createPath().'/options')->assertOk();
        }

        // A form that charged for being looked at would be a slot stolen, and
        // the read is the only endpoint in this file a user can reach without
        // asking to spend anything.
        expect(app(ConsumeEntitlement::class)->available($user, EntitlementType::CreateSlot))->toBe(1)
            ->and(Entitlement::query()->whereNotNull('consumed_at')->count())->toBe(0);
    });

    it('serves a full payload to a user with no create-slot at all', function () {
        $user = aMiniAppCreator();

        // The refusal belongs at the create, not at the form: a user with no
        // slot can still read every picker, and is told what an extra costs so
        // that the "no" arrives with a number attached.
        $response = test()->getJson(createPath().'/options')->assertOk();

        expect($response->json('slots.available_create_slots'))->toBe(0)
            ->and($response->json('slots.price'))->toBe(
                app(Settings::class)->integer(SettingKey::CreateSlotCoinPrice),
            )
            ->and($response->json('period_types'))->toHaveCount(count(PeriodType::cases()));
    });

    it('describes the pickers, the ceilings and the bot without hardcoding any of them', function () {
        $user = aMiniAppCreator();
        aCreateSlotFor($user);

        app(Settings::class)->set(SettingKey::ProofMediaMaxSeconds, 45);
        app(Settings::class)->set(SettingKey::ProofMediaMaxSizeKb, 1024);
        app(Settings::class)->set(SettingKey::DefaultChallengeFreezes, 3);

        $response = test()->getJson(createPath().'/options')->assertOk();

        // Every bound is the action's own, so a form built from this payload
        // cannot offer a value the action would then refuse.
        expect($response->json('limits'))->toBe(CreateChallenge::limits())
            ->and($response->json('timezones'))->toContain('Asia/Tehran')->toContain('UTC')
            ->and($response->json('default_freezes'))->toBe(3)
            ->and($response->json('media_caps'))->toBe(['max_seconds' => 45, 'max_size_kb' => 1024])
            // The same helper the boot user reads, so the two cannot disagree
            // about where the bot is.
            ->and($response->json('bot_url'))->toBe(MessagingPlatform::Telegram->botUrl());

        // All five proof types, each with the label the catalogue already
        // carries — a recording challenge created here is satisfiable, because
        // the bot's check-in flow collects voice and video today.
        expect($response->json('proof_types'))->toHaveCount(count(ProofType::cases()))
            ->and($response->json('proof_types.4'))->toBe([
                'value' => ProofType::VideoApproval->value,
                'label' => ProofType::VideoApproval->label(),
            ]);

        // Stated, not offered: this build creates simple challenges only, and
        // Task 7 extends this same list rather than reshaping it.
        expect($response->json('flow_types'))->toBe([
            ['value' => 'simple', 'label' => FlowType::Simple->label()],
        ]);
    });

    it('labels every picker in the language the user chose', function () {
        $user = aMiniAppCreator();
        $user->forceFill(['locale' => 'fa'])->save();

        test()->getJson(createPath().'/options')
            ->assertOk()
            ->assertJsonPath('period_types.0.label', __('enums.period_type.daily', [], 'fa'))
            ->assertJsonPath('proof_types.0.label', __('enums.proof_type.button', [], 'fa'));
    });
});
