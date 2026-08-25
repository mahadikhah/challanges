<?php

namespace App\Actions\Challenges;

use App\Actions\Entitlements\ConsumeEntitlement;
use App\Enums\ChallengeStatus;
use App\Enums\ChallengeVisibility;
use App\Enums\EntitlementType;
use App\Enums\PeriodType;
use App\Enums\ProofType;
use App\Enums\SettingKey;
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

    public function __construct(
        private readonly ConsumeEntitlement $entitlements,
        private readonly MaterialiseChallengePeriods $periods,
        private readonly Settings $settings,
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
    ): Challenge {
        $title = trim($title);
        $description = $description === null ? null : trim($description);

        $this->assertCoherent($title, $description, $periodType, $customPeriodDays, $totalPeriods, $timezone);

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
        ): Challenge {
            $challenge = Challenge::query()->create([
                'creator_id' => $creator->getKey(),
                'title' => $title,
                'description' => $description === '' ? null : $description,
                'period_type' => $periodType,
                'custom_period_days' => $periodType->requiresCustomDays() ? $customPeriodDays : null,
                'starts_at' => $startsAt,
                'total_periods' => $totalPeriods,
                'timezone' => $timezone,
                'visibility' => $visibility,
                'proof_type' => $proofType,

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
     * @return array{title_max: int, description_max: int, total_periods_max: int, custom_period_days_max: int}
     */
    public static function limits(): array
    {
        return [
            'title_max' => self::TITLE_MAX,
            'description_max' => self::DESCRIPTION_MAX,
            'total_periods_max' => self::TOTAL_PERIODS_MAX,
            'custom_period_days_max' => self::CUSTOM_PERIOD_DAYS_MAX,
        ];
    }
}
