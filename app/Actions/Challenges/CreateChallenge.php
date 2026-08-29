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
use App\Enums\ScoringStrategy;
use App\Enums\ScoringType;
use App\Enums\SettingKey;
use App\Enums\StepInputType;
use App\Exceptions\NoEntitlementAvailableException;
use App\Jobs\Challenges\AnnounceChallenge;
use App\Models\Challenge;
use App\Models\User;
use App\Services\Ai\AiApprovalGate;
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
     * A unit label rides in prompts ("How many pushups?") and leaderboard
     * lines; the bound keeps both legible, not the column short.
     */
    private const UNIT_LABEL_MAX = 64;

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
        private readonly AiApprovalGate $aiApprovalGate,
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
     * @param  ApprovalMode|null  $approvalMode  null keeps `manual`; `ai` is allowed only for media types the admin has enabled (see `AiApprovalGate`) and additionally requires a non-empty `$approvalCriteria`
     * @param  string|null  $approvalCriteria  pre-screened criteria; never re-screened here — the floor is that it exists, not that it is trustworthy
     * @param  int|null  $proofMediaMaxSeconds  cap on a voice/video submission's duration; required when the proof type or a step demands a recording, and never above the admin ceiling
     * @param  int|null  $proofMediaMaxSizeKb  cap on a voice/video submission's size in KB, same requirements as the duration cap
     * @param  ScoringType  $scoringType  `binary` (default) leaves the challenge exactly as every challenge before it; `quantity` requires the whole scoring block below
     * @param  int|float|string|null  $targetValue  the value a full score is awarded for; positive, required for `quantity`, forbidden otherwise
     * @param  string|null  $unitLabel  what the participant counts ("pushups", "seconds"); required for `quantity`, forbidden otherwise
     * @param  int|float|string|null  $basePoints  the score awarded at exactly `targetValue`; positive, required for `quantity`, forbidden otherwise
     * @param  bool  $quantityPartialCountsAsDone  when true, a below-target report still settles the period (streak continues, lower score); default false — falling short is a miss
     * @param  ScoringStrategy|null  $scoringStrategy  how a report becomes a score; fixed enum, never a formula. Defaults to `proportional`, the only strategy, so callers need not say it
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
        ?int $proofMediaMaxSeconds = null,
        ?int $proofMediaMaxSizeKb = null,
        ScoringType $scoringType = ScoringType::Binary,
        int|float|string|null $targetValue = null,
        ?string $unitLabel = null,
        int|float|string|null $basePoints = null,
        bool $quantityPartialCountsAsDone = false,
        ?ScoringStrategy $scoringStrategy = null,
    ): Challenge {
        $title = trim($title);
        $description = $description === null ? null : trim($description);
        $approvalCriteria = $approvalCriteria === null ? null : trim($approvalCriteria);

        $this->assertCoherent($title, $description, $periodType, $customPeriodDays, $totalPeriods, $timezone);

        // AI review is an attribute of media-proof challenges — a tap or a
        // typed phrase has nothing to look at, and criteria next to them is
        // prose with nothing to constrain. The mode degrades to manual rather
        // than refusing, because a caller that asked for AI review on a button
        // challenge has built a working challenge with a stray parameter.
        $approvalMode = $proofType->isMediaApproval() ? $approvalMode : null;
        $approvalCriteria = $approvalMode === ApprovalMode::Ai ? $approvalCriteria : null;

        // Within the media types, AI review exists only where this
        // deployment's admin has allowed it (§2.11) — refused, not degraded:
        // the caller offered a participant AI review the platform never
        // promised, and silently switching to the creator's own queue would
        // make the manual queue fill with challenges whose creators never
        // volunteered to review them.
        if ($approvalMode === ApprovalMode::Ai && ! $this->aiApprovalGate->allows($proofType)) {
            throw new InvalidArgumentException(
                "AI review is not available for {$proofType->value} proof on this deployment.",
            );
        }

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

        [$proofMediaMaxSeconds, $proofMediaMaxSizeKb] = $this->assertMediaCaps(
            $proofType,
            $flowType,
            $steps ?? [],
            $proofMediaMaxSeconds,
            $proofMediaMaxSizeKb,
        );

        [$targetValue, $unitLabel, $basePoints, $scoringStrategy] = $this->assertScoring(
            $scoringType,
            $targetValue,
            $unitLabel,
            $basePoints,
            $quantityPartialCountsAsDone,
            $scoringStrategy,
        );

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
            $proofMediaMaxSeconds,
            $proofMediaMaxSizeKb,
            $defaultFreezes,
            $flowType,
            $steps,
            $approvalMode,
            $approvalCriteria,
            $scoringType,
            $targetValue,
            $unitLabel,
            $basePoints,
            $quantityPartialCountsAsDone,
            $scoringStrategy
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

                'proof_media_max_seconds' => $proofMediaMaxSeconds,
                'proof_media_max_size_kb' => $proofMediaMaxSizeKb,

                'scoring_type' => $scoringType,
                'target_value' => $targetValue,
                'unit_label' => $unitLabel,
                'scoring_strategy' => $scoringStrategy,
                'base_points' => $basePoints,
                'quantity_partial_counts_as_done' => $quantityPartialCountsAsDone,

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
     * The media caps a challenge needs, or a refusal when they are missing or
     * beyond what the platform allows.
     *
     * Caps are required the moment a *recording* is involved: a voice or video
     * proof type, or a video step. Image proof and voice steps are exempt —
     * not because their bytes are free, but because they shipped without caps
     * and the platform's promise to them is already made.
     *
     * A cap on a challenge that needs none degrades to null, exactly as a
     * stray `approvalMode` does: the caller has built a working challenge
     * with a stray parameter. A cap *on* a challenge that needs one may sit
     * below the admin ceiling but never above it — the ceiling exists because
     * disk is the platform's to run out of, not the creator's.
     *
     * @param  list<array{input_type: StepInputType, ...}>  $steps
     * @return array{0: int|null, 1: int|null} the seconds and KB caps to store
     *
     * @throws InvalidArgumentException
     */
    private function assertMediaCaps(
        ProofType $proofType,
        FlowType $flowType,
        array $steps,
        ?int $seconds,
        ?int $sizeKb,
    ): array {
        $wantsDuration = $proofType->expectsDuration()
            || ($flowType === FlowType::TimedSession
                && in_array(StepInputType::Video, array_column($steps, 'input_type'), true));

        if (! $wantsDuration) {
            return [null, null];
        }

        $ceilingSeconds = $this->settings->integer(SettingKey::ProofMediaMaxSeconds);
        $ceilingKb = $this->settings->integer(SettingKey::ProofMediaMaxSizeKb);

        foreach ([['duration', $seconds, $ceilingSeconds, 'seconds'], ['size', $sizeKb, $ceilingKb, 'KB']] as [$name, $value, $ceiling, $unit]) {
            if ($value === null || $value < 1) {
                throw new InvalidArgumentException(
                    "A {$proofType->value} challenge needs a proof media {$name} cap; none was given.",
                );
            }

            if ($value > $ceiling) {
                throw new InvalidArgumentException(
                    "The proof media {$name} cap may not exceed the admin ceiling of {$ceiling} {$unit}, got {$value}.",
                );
            }
        }

        return [$seconds, $sizeKb];
    }

    /**
     * The scoring block: all of it for a quantity challenge, none of it for a
     * binary one — the same required/forbidden pattern the media caps use, so
     * a stray parameter on either side of the line is a refusal rather than a
     * silently half-configured challenge.
     *
     * `scoring_strategy` is the one exception: it defaults to `proportional`
     * rather than being asked for, because it is the only strategy — exposing
     * a choice of one is not a choice. The default still applies only to
     * quantity challenges; binary ones store null.
     *
     * @return array{0: string|null, 1: string|null, 2: string|null, 3: ScoringStrategy|null} the values to store, as the decimal columns' strings
     *
     * @throws InvalidArgumentException
     */
    private function assertScoring(
        ScoringType $scoringType,
        int|float|string|null $targetValue,
        ?string $unitLabel,
        int|float|string|null $basePoints,
        bool $quantityPartialCountsAsDone,
        ?ScoringStrategy $scoringStrategy,
    ): array {
        if (! $scoringType->isQuantity()) {
            // Forbidden rather than ignored: a target on a binary challenge is
            // a caller bug, and storing it would be a half-configured scoring
            // block waiting to surprise whoever flips the type later.
            if ($targetValue !== null || $unitLabel !== null || $basePoints !== null
                || $scoringStrategy !== null || $quantityPartialCountsAsDone) {
                throw new InvalidArgumentException(
                    "A {$scoringType->value} challenge takes no scoring configuration; leave it out.",
                );
            }

            return [null, null, null, null];
        }

        foreach ([['target_value', $targetValue], ['base_points', $basePoints]] as [$name, $value]) {
            if ($value === null || (float) $value <= 0) {
                throw new InvalidArgumentException(
                    "A quantity challenge needs a positive {$name}; got ".var_export($value, true).'.',
                );
            }
        }

        if ($unitLabel === null || trim($unitLabel) === '') {
            throw new InvalidArgumentException('A quantity challenge needs a unit label.');
        }

        if (mb_strlen(trim($unitLabel)) > self::UNIT_LABEL_MAX) {
            throw new InvalidArgumentException(
                'A unit label may not exceed '.self::UNIT_LABEL_MAX.' characters.',
            );
        }

        // The strategy has a default and keeps it: `proportional` is the only
        // branch `CalculateQuantityScore` (Task 2) implements, so the wizard
        // never asks and the column still says which arithmetic scored a
        // period — the point of storing a strategy at all.
        return [
            number_format((float) $targetValue, 2, '.', ''),
            trim($unitLabel),
            number_format((float) $basePoints, 2, '.', ''),
            $scoringStrategy ?? ScoringStrategy::Proportional,
        ];
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
            'unit_label_max' => self::UNIT_LABEL_MAX,
        ];
    }
}
