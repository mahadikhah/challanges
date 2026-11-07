<?php

namespace App\Http\Controllers\MiniApp;

use App\Actions\Challenges\CreateChallenge;
use App\Actions\Entitlements\ConsumeEntitlement;
use App\Actions\Entitlements\PurchaseEntitlement;
use App\Actions\Telegram\VerifyChannelMembership;
use App\Enums\ChallengeVisibility;
use App\Enums\Contracts\HasTranslatedLabel;
use App\Enums\EntitlementType;
use App\Enums\FlowType;
use App\Enums\PeriodType;
use App\Enums\ProofType;
use App\Enums\ScoringType;
use App\Enums\SettingKey;
use App\Exceptions\NoEntitlementAvailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\MiniApp\StoreChallengeRequest;
use App\Http\Resources\MiniApp\ChallengeResource;
use App\Models\User;
use App\Services\CoinLedger;
use App\Services\Settings;
use BackedEnum;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Creating a challenge from the Mini App.
 *
 * Two endpoints with deliberately different characters. `options` is a **read**:
 * everything the form needs to render its pickers and quote its price, and
 * nothing about anybody else. `store` is a **privileged write** that calls the
 * very same `CreateChallenge` action the bot's wizard and the admin panel call,
 * so the rules cannot be right on one surface and wrong on another.
 *
 * **The options endpoint does not gate.** A user who has left the announcement
 * channel, or who holds no create-slot, still gets the whole payload — the form
 * is a question, and refusing to answer it is not a refusal of anything the
 * user asked for. Both refusals belong at the create, where the user has
 * actually asked to spend something.
 *
 * **The options endpoint does not spend either**, and that is a property worth
 * asserting rather than assuming: the slot count comes from
 * `ConsumeEntitlement::available()`, which is a scoped count, and the price from
 * `PurchaseEntitlement::priceOf()`, which is a `Setting` read. Nothing here
 * touches a balance or a slot row.
 *
 * **The gate is re-verified at the create**, not inherited from the token's
 * mint time. CLAUDE.md pins "re-verify on privileged actions", and creating
 * spends a slot. Same call, in the same order — before anything is spent — as
 * the join endpoint makes.
 */
class CreateChallengeController extends Controller
{
    /**
     * The refusals this endpoint answers with a *reason* rather than a message.
     *
     * The start date's two failures are the ones the Form Request cannot own:
     * "not before today" is measured in the challenge's own timezone, which is
     * a sibling field, so the comparison happens here where both are known.
     * They are reasons rather than a 422 message bag for the same reason every
     * other refusal on this surface is — the sentence lives in the SPA's own
     * catalogue, in the user's locale, and the endpoint stays locale-free.
     *
     * `INVALID_START_DATE` is a backstop rather than the usual path:
     * `date_format:Y-m-d` on the request already refuses both a malformed string
     * and a rolled-over date like 31 April. It stays because the shape check is
     * the *request's*, and a later relaxation of that rule must not be able to
     * hand Carbon a date it would silently move a day. Same regex-then-
     * `checkdate` order as `CreateChallengeWizard::readDate()`.
     */
    private const string INVALID_START_DATE = 'invalid_start_date';

    private const string PAST_START_DATE = 'past_start_date';

    public function __construct(
        private readonly VerifyChannelMembership $gate,
        private readonly CreateChallenge $create,
        private readonly ConsumeEntitlement $entitlements,
        private readonly PurchaseEntitlement $purchases,
        private readonly CoinLedger $ledger,
        private readonly Settings $settings,
    ) {}

    /**
     * Everything the create form renders, and the two numbers it quotes.
     *
     * The bounds are `CreateChallenge::limits()` verbatim and the timezones are
     * `DateTimeZone::listIdentifiers()` verbatim — the same set the action
     * validates against — so a form built from this payload cannot offer a
     * value the action would then refuse. The ceilings are `Setting`s, and read
     * here rather than mirrored in the SPA, because an admin who lowers the
     * media cap expects the form to clamp the day they lower it.
     *
     * `flow_types` is a one-element list and not a bare string: it says which
     * flows *this build* can create without offering a choice the API would
     * refuse, and Task 7 extends the same list in place.
     */
    public function options(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'limits' => CreateChallenge::limits(),

            'period_types' => $this->enumList(PeriodType::cases()),
            'proof_types' => $this->enumList(ProofType::cases()),
            'visibilities' => $this->enumList(ChallengeVisibility::cases()),
            'scoring_types' => $this->enumList(ScoringType::cases()),

            // Named rather than omitted: the SPA shows it as a line the creator
            // reads, and a client that hardcoded "simple" would be a client
            // that had to be edited when Task 7 lands.
            'flow_types' => $this->enumList([FlowType::Simple]),

            'timezones' => DateTimeZone::listIdentifiers(),

            'default_freezes' => $this->settings->integer(SettingKey::DefaultChallengeFreezes),

            // The pair a recording challenge must consume together — see
            // `CreateChallenge::assertMediaCaps()`, which requires both the
            // moment a duration is involved. Grouped rather than flat so the
            // form treats them as one unit, and so Task 7's additions extend
            // this object rather than the top level.
            'media_caps' => [
                'max_seconds' => $this->settings->integer(SettingKey::ProofMediaMaxSeconds),
                'max_size_kb' => $this->settings->integer(SettingKey::ProofMediaMaxSizeKb),
            ],

            // A count and a price, both advisory: the form uses them to decide
            // what to quote, and `CreateChallenge` remains the authority on
            // whether a slot is actually there.
            'slots' => [
                'available_create_slots' => $this->entitlements->available($user, EntitlementType::CreateSlot),
                'price' => $this->purchases->priceOf(EntitlementType::CreateSlot),
            ],

            // The same helper `MiniAppUserResource` reads, so the two cannot
            // disagree about where the bot is. Null for a platform whose
            // username is unconfigured — an honest absence rather than a link
            // with a hole in it.
            'bot_url' => $user->platform?->botUrl(),
        ]);
    }

    /**
     * Create the challenge, spending one of the creator's create-slots.
     *
     * The order is the contract, and each step is where it is for a reason:
     * the date is interpreted before the gate because it is validation rather
     * than privilege; the gate comes before the action because a refusal that
     * still spent a slot is a slot stolen; and the slot itself is only ever
     * spent *inside* `CreateChallenge`'s transaction, never here.
     */
    public function store(StoreChallengeRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $timezone = (string) $data['timezone'];
        $startsAt = $this->startsAt((string) $data['starts_at'], $timezone);

        if (is_string($startsAt)) {
            // The reason, machine-readable; the sentence is the SPA's. A bare
            // reason keeps this endpoint locale-independent and the copy
            // single-sourced client-side — the same rule every refusal on this
            // surface follows.
            return response()->json(['reason' => $startsAt], 422);
        }

        if (! $this->gate->ensure($user)) {
            return response()->json([
                'reason' => 'channel_gate',
                // Null for a numeric channel id: there is genuinely no public
                // URL to join with, and the client says so rather than offering
                // a broken button.
                'join_url' => $this->gate->joinUrl($user),
            ], 403);
        }

        $periodType = PeriodType::from((string) $data['period_type']);
        $proofType = ProofType::from((string) $data['proof_type']);
        $scoringType = ScoringType::from((string) $data['scoring_type']);
        $quantity = $scoringType->isQuantity();

        try {
            $challenge = $this->create->handle(
                creator: $user,
                title: (string) $data['title'],
                description: isset($data['description']) ? (string) $data['description'] : null,
                periodType: $periodType,
                customPeriodDays: $periodType->requiresCustomDays()
                    ? (int) $data['custom_period_days']
                    : null,
                startsAt: $startsAt,
                totalPeriods: (int) $data['total_periods'],
                timezone: $timezone,
                proofType: $proofType,
                visibility: ChallengeVisibility::from((string) $data['visibility']),
                proofMediaMaxSeconds: $proofType->expectsDuration()
                    ? (int) $data['proof_media_max_seconds']
                    : null,
                proofMediaMaxSizeKb: $proofType->expectsDuration()
                    ? (int) $data['proof_media_max_size_kb']
                    : null,
                scoringType: $scoringType,
                targetValue: $quantity ? (string) $data['target_value'] : null,
                unitLabel: $quantity ? (string) $data['unit_label'] : null,
                basePoints: $quantity ? (string) $data['base_points'] : null,
                quantityPartialCountsAsDone: $quantity
                    && (bool) ($data['quantity_partial_counts_as_done'] ?? false),
            );
        } catch (NoEntitlementAvailableException $refused) {
            // 402 rather than 422: nothing about the request was wrong. The
            // reader lacks a thing money can buy, and the body is the quote.
            //
            // Both numbers are read at the tap through the shared readers, so
            // a price the admin changed a minute ago is the price quoted — and
            // the type comes off the exception rather than being assumed, so
            // this stays the price of the slot that actually failed.
            return response()->json([
                'reason' => 'no_create_slot',
                'price' => $this->purchases->priceOf($refused->type),
                'balance' => $this->ledger->balanceFor($user),
            ], 402);
        } catch (InvalidArgumentException $refused) {
            // The request validated every field, so the action refusing means
            // our two layers disagreed — our bug, not the user's. Told,
            // logged, and answered generically; the reason goes to the log
            // because a caller who can read it learns how to probe the floor.
            // `CreateChallengeWizard::finish()` treats the same exception the
            // same way.
            Log::error('A Mini App challenge was refused by CreateChallenge.', [
                'user_id' => $user->getKey(),
                'reason' => $refused->getMessage(),
            ]);

            return response()->json(['reason' => 'unexpected'], 500);
        }

        // The action returns a bare model and the resource reads the timeline
        // off it; `preventLazyLoading()` is not enabled, so this is said out
        // loud rather than left to a lazy load nothing would catch.
        $challenge->loadMissing('periods');

        // `$participant` is null because creating does not enrol the creator —
        // a baked-in decision, and what `has_joined: false` on the response
        // states. The response is the SPA's new state, not a patch to merge,
        // exactly as the check-in and join endpoints answer.
        return ChallengeResource::detail($challenge, null, $user)
            ->response()
            ->setStatusCode(201);
    }

    /**
     * The instant a typed date names, or the reason it names none.
     *
     * `Y-m-d` only, and the date is read in the **challenge's** timezone before
     * being stored as UTC — "starting on the 3rd" is not an instant until the
     * zone is known. This is `ChallengeDraft::startsAt()`'s conversion and
     * `CreateChallengeWizard::readDate()`'s checks, kept in that order for the
     * same reason the wizard keeps them: `checkdate` runs before Carbon is
     * asked, because Carbon rolls 31 April over into 1 May rather than refusing
     * it, and a creator who fat-fingered a month should be told rather than
     * quietly moved a day.
     *
     * Yesterday is refused too: it would mean a period that closed before the
     * challenge was created, and a participant already behind on a challenge
     * nobody could have checked into.
     *
     * @return CarbonImmutable|string the instant, or one of the two reason constants
     */
    private function startsAt(string $date, string $timezone): CarbonImmutable|string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) !== 1) {
            return self::INVALID_START_DATE;
        }

        [, $year, $month, $day] = array_map('intval', $parts);

        if (! checkdate($month, $day, $year)) {
            return self::INVALID_START_DATE;
        }

        $start = CarbonImmutable::create($year, $month, $day, 0, 0, 0, $timezone);

        if ($start->lessThan(CarbonImmutable::now($timezone)->startOfDay())) {
            return self::PAST_START_DATE;
        }

        return $start->utc();
    }

    /**
     * Enum cases as the `{value, label}` pairs every other Mini App payload
     * uses, so a picker needs no label table of its own.
     *
     * Built from `cases()` rather than a hand-kept list: a case added to an enum
     * shows up in the picker the day it exists. Labels resolve in the ambient
     * locale `SetLocale` picked from the token's user, the same as
     * `ChallengeResource`'s enums do.
     *
     * @param  list<BackedEnum&HasTranslatedLabel>  $cases
     * @return list<array{value: int|string, label: string}>
     */
    private function enumList(array $cases): array
    {
        // `array_map` over a list is itself a list, so the result encodes as a
        // JSON array without a `array_values()` on top of it.
        return array_map(
            static fn (BackedEnum&HasTranslatedLabel $case): array => [
                'value' => $case->value,
                'label' => $case->label(),
            ],
            $cases,
        );
    }
}
