<?php

namespace App\Actions\Challenges;

use App\Actions\Entitlements\ConsumeEntitlement;
use App\Enums\ApprovalMode;
use App\Enums\ChallengeStatus;
use App\Enums\ChallengeVisibility;
use App\Enums\EntitlementType;
use App\Enums\FlowType;
use App\Enums\PeriodType;
use App\Enums\ProofType;
use App\Enums\SettingKey;
use App\Enums\StepInputType;
use App\Exceptions\NoEntitlementAvailableException;
use App\Jobs\Challenges\AnnounceChallenge;
use App\Models\Challenge;
use App\Models\User;
use App\Services\Settings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Bring a challenge into existence: row, slot, timeline, announcement.
 *
 * The one place a `challenges` row is written, so the bot wizard, the Mini App and
 * the admin panel cannot disagree about what creating costs or what a new
 * challenge is allowed to look like.
 *
 * **All four steps or none.** The row, the spent create-slot and the materialised
 * timeline go in one transaction, because each of the three failure modes is worse
 * than refusing: a challenge with no slot spent is a free extra challenge, a spent
 * slot with no challenge is a slot stolen, and a challenge with no periods is one
 * that can never be checked into and never reminds anybody. `ConsumeEntitlement`
 * needs the challenge to record the spend against, so the row is written first and
 * rolled back when there is no slot to pay for it.
 *
 * **The announcement is dispatched after the commit, never inside it.** Posting to
 * the announcement channel is a Bot API call: slow, rate-limited, and capable of
 * failing for reasons that have nothing to do with the challenge being valid. Held
 * inside the transaction it would keep row locks open across a network round trip,
 * and a Telegram outage would stop challenges being created at all.
 *
 * Validation here is deliberately not a substitute for a Form Request. It is the
 * floor: what must hold no matter which surface called, so a caller that forgets
 * to validate produces a refusal rather than a broken timeline.
 */
class CreateChallenge
{
    /**
     * Titles are shown in listings, reminders and channel posts, so the bound is
     * about legibility rather than the column's 255.
     */
    private const TITLE_MAX = 120;

    private const DESCRIPTION_MAX = 1000;

    /**
     * `total_periods` is an unsigned smallint, so the database would take 65535.
     * A daily challenge of that length runs for 179 years; the cap is what a
     * person could plausibly mean, and it keeps period materialisation bounded.
     */
    private const TOTAL_PERIODS_MAX = 1000;

    private const CUSTOM_PERIOD_DAYS_MAX = 365;

    /**
     * Descriptive criteria for an AI reviewer, not an essay. Shared with the
     * AI suggestion clamp so the model cannot talk its way past the bound.
     */
    public const APPROVAL_CRITERIA_MAX = 500;

    public function __construct(
        private readonly ConsumeEntitlement $entitlements,
        private readonly MaterialiseChallengePeriods $periods,
        private readonly MintJoinToken $joinTokens,
        private readonly Settings $settings,
        private readonly ValidateChallengeStepDesign $stepDesign,
    ) {}

    /**
     * Create the challenge, spending one of the creator's create-slots.
     *
     * `$startsAt` is an instant, not a date: the caller owns the decision about
     * what "starting on the 3rd" means in the challenge's timezone, because only
     * the caller knows whether the creator picked a date or a precise time. It is
     * stored as UTC, as everything is.
     *
     * @param  int|null  $defaultFreezes  null takes the admin-configured default
     * @param  list<array{input_type: StepInputType, min_wait_seconds: int, voice_max_seconds?: int|null, label?: string|null}>|null  $steps
     *                                                                                                                                        the timed-session step list; required when `$flowType` is `timed_session`, ignored otherwise
     * @param  ApprovalMode|null  $approvalMode  null keeps `manual`; `ai` additionally requires a non-empty `$approvalCriteria`
     * @param  string|null  $approvalCriteria  pre-screened criteria; never re-screened here — the floor is that it exists, not that it is trustworthy
     *
     * @throws InvalidArgumentException when the challenge could not be a coherent challenge
     * @throws NoEntitlementAvailableException when the creator holds no create-slot
     */
    public function handle(
        User $creator,
        string $title,
        ?string $description,
        PeriodType $periodType,
        ?int $customPeriodDays,
        CarbonInterface $startsAt,
        int $totalPeriods,
        string $timezone,
        ProofType $proofType,
        ChallengeVisibility $visibility,
        bool $proofIsPublic = false,
        ?int $defaultFreezes = null,
        FlowType $flowType = FlowType::Simple,
        ?array $steps = null,
        ?ApprovalMode $approvalMode = null,
        ?string $approvalCriteria = null,
    ): Challenge {
        $title = trim($title);
        $description = $description === null ? null : trim($description);
        $approvalCriteria = $approvalCriteria === null ? null : trim($approvalCriteria);

        $this->assertCoherent($title, $description, $periodType, $customPeriodDays, $totalPeriods, $timezone);

        // AI review is an attribute of `image_approval` only: a tap or a typed
        // phrase has no photo to look at, and criteria next to them is prose
        // with nothing to constrain. The mode degrades to manual rather than
        // refusing, because a caller that asked for AI review on a button
        // challenge has built a working challenge with a stray parameter.
        $approvalMode = $proofType === ProofType::ImageApproval ? $approvalMode : null;
        $approvalCriteria = $approvalMode === ApprovalMode::Ai ? $approvalCriteria : null;

        if ($approvalMode === ApprovalMode::Ai && ($approvalCriteria === null || $approvalCriteria === '')) {
            throw new InvalidArgumentException('An AI-reviewed challenge needs approval criteria.');
        }

        if ($approvalCriteria !== null && mb_strlen($approvalCriteria) > self::APPROVAL_CRITERIA_MAX) {
            throw new InvalidArgumentException(
                'Approval criteria may not exceed '.self::APPROVAL_CRITERIA_MAX.' characters.',
            );
        }

        // A timed session is a way to arrive at a check-in, so its design must
        // be provably completable inside one period before anything is spent
        // on it — a slot, a timeline, a single participant. Simple challenges
        // have no step list to check, by definition.
        if ($flowType === FlowType::TimedSession) {
            $this->stepDesign->handle($periodType, $customPeriodDays, $startsAt, $timezone, $steps ?? []);
        }

        $startsAt = CarbonImmutable::instance($startsAt)->utc();

        $challenge = DB::transaction(function () use (
            $creator,
            $title,
            $description,
            $periodType,
            $customPeriodDays,
            $startsAt,
            $totalPeriods,
            $timezone,
            $proofType,
            $visibility,
            $proofIsPublic,
            $defaultFreezes,
            $flowType,
            $steps,
            $approvalMode,
            $approvalCriteria
        ): Challenge {
            $challenge = Challenge::query()->create([
                'creator_id' => $creator->getKey(),
                'title' => $title,
                'description' => $description === '' ? null : $description,

                // Every challenge gets one, public ones included: the token is how
                // a challenge is addressed in a link, and a public challenge is
                // shared by link at least as often as it is found in the channel.
                'join_token' => $this->joinTokens->handle(),

                'period_type' => $periodType,
                'custom_period_days' => $periodType->requiresCustomDays() ? $customPeriodDays : null,
                'starts_at' => $startsAt,
                'total_periods' => $totalPeriods,
                'timezone' => $timezone,
                'visibility' => $visibility,
                'proof_type' => $proofType,
                'approval_mode' => $approvalMode ?? ApprovalMode::Manual,
                'approval_criteria' => $approvalCriteria,
                'flow_type' => $flowType,

                // Two gates, and the type's is not negotiable: publishing an
                // autogenerated phrase would hand every participant the answer,
                // and a tap has nothing to show. A caller asking for public
                // proofs on a type that cannot honour it gets private ones
                // rather than an error, because it is a display preference.
                'proof_is_public' => $proofIsPublic && $proofType->supportsPublicProof(),

                'default_freezes' => $defaultFreezes ?? $this->settings->integer(SettingKey::DefaultChallengeFreezes),
                'status' => $startsAt->isFuture() ? ChallengeStatus::Scheduled : ChallengeStatus::Active,
            ]);

            // After the row exists, because a spend is recorded *against* a
            // challenge — that is what makes a double-tapped create idempotent
            // rather than twice as expensive.
            $this->entitlements->handle($creator, EntitlementType::CreateSlot, $challenge);

            $this->periods->handle($challenge);

            // The step rows are part of the creation, all-or-nothing with it: a
            // timed-session challenge whose steps failed to persist would be a
            // session that can never start. Order is the list position, 1-based.
            if ($flowType === FlowType::TimedSession) {
                $challenge->steps()->createMany(array_map(
                    static fn (int $index): array => [
                        'step_order' => $index + 1,
                        'input_type' => $steps[$index]['input_type'],
                        'min_wait_seconds' => $steps[$index]['min_wait_seconds'],
                        'voice_max_seconds' => $steps[$index]['voice_max_seconds'] ?? null,
                        'label' => $steps[$index]['label'] ?? null,
                    ],
                    array_keys($steps),
                ));
            }

            return $challenge;
        });

        if ($challenge->awaitsAnnouncement()) {
            AnnounceChallenge::dispatch($challenge)->afterCommit();
        }

        return $challenge;
    }

    /**
     * Refuse anything that could not be a working challenge.
     *
     * @throws InvalidArgumentException
     */
    private function assertCoherent(
        string $title,
        ?string $description,
        PeriodType $periodType,
        ?int $customPeriodDays,
        int $totalPeriods,
        string $timezone,
    ): void {
        if ($title === '') {
            throw new InvalidArgumentException('A challenge needs a title.');
        }

        // Counted in characters rather than bytes: a Farsi title is legible at
        // the same length as an English one and would otherwise be cut in half.
        if (mb_strlen($title) > self::TITLE_MAX) {
            throw new InvalidArgumentException('A challenge title may not exceed '.self::TITLE_MAX.' characters.');
        }

        if ($description !== null && mb_strlen($description) > self::DESCRIPTION_MAX) {
            throw new InvalidArgumentException(
                'A challenge description may not exceed '.self::DESCRIPTION_MAX.' characters.',
            );
        }

        if ($totalPeriods < 1 || $totalPeriods > self::TOTAL_PERIODS_MAX) {
            throw new InvalidArgumentException(
                'total_periods must be between 1 and '.self::TOTAL_PERIODS_MAX.", got {$totalPeriods}.",
            );
        }

        if ($periodType->requiresCustomDays()
            && ($customPeriodDays === null || $customPeriodDays < 1 || $customPeriodDays > self::CUSTOM_PERIOD_DAYS_MAX)
        ) {
            throw new InvalidArgumentException(
                "A {$periodType->value} challenge needs custom_period_days between 1 and "
                .self::CUSTOM_PERIOD_DAYS_MAX.', got '.var_export($customPeriodDays, true).'.',
            );
        }

        // Every period boundary is computed in this zone. An unknown name would
        // surface much later, as a Carbon exception inside the materialiser or a
        // reminder scheduled at the wrong hour.
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException("'{$timezone}' is not a known timezone identifier.");
        }
    }

    /**
     * The bounds a surface should validate against before it asks the user twice.
     *
     * @return array{title_max: int, description_max: int, total_periods_max: int, custom_period_days_max: int, approval_criteria_max: int}
     */
    public static function limits(): array
    {
        return [
            'title_max' => self::TITLE_MAX,
            'description_max' => self::DESCRIPTION_MAX,
            'total_periods_max' => self::TOTAL_PERIODS_MAX,
            'custom_period_days_max' => self::CUSTOM_PERIOD_DAYS_MAX,
            'approval_criteria_max' => self::APPROVAL_CRITERIA_MAX,
        ];
    }
}
