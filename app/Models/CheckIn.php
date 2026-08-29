<?php

namespace App\Models;

use App\Enums\CheckInStatus;
use App\Services\Localization;
use Carbon\CarbonImmutable;
use Database\Factories\CheckInFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One participant's obligation for one period, and how it turned out.
 *
 * There is exactly one row per (participant, period) — enforced by a unique
 * index — so a double-tap, a retried webhook and a re-run rollover all converge
 * on the same row instead of creating a second one.
 *
 * @property int $id
 * @property int $challenge_participant_id
 * @property int $challenge_period_id
 * @property CheckInStatus $status
 * @property string|null $expected_phrase
 * @property string|null $submitted_text
 * @property string|null $proof_path
 * @property CarbonImmutable|null $submitted_at
 * @property int|null $reviewed_by
 * @property CarbonImmutable|null $reviewed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read ChallengeParticipant $participant
 * @property-read ChallengePeriod $period
 * @property-read User|null $reviewer
 * @property-read AiApprovalDecision|null $aiDecision
 */
#[Fillable([
    'challenge_participant_id',
    'challenge_period_id',
    'status',
    'expected_phrase',
    'submitted_text',
    'proof_path',
    'submitted_at',
    'reviewed_by',
    'reviewed_at',
])]
class CheckIn extends Model
{
    /** @use HasFactory<CheckInFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<ChallengeParticipant, $this>
     */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(ChallengeParticipant::class, 'challenge_participant_id');
    }

    /**
     * @return BelongsTo<ChallengePeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(ChallengePeriod::class, 'challenge_period_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * The most recent AI moderation call on this submission, if any.
     *
     * OfMany picks the latest row, so a resubmission after a fallback or a
     * rejection shows the call that currently matters, not the first one.
     *
     * @return HasOne<AiApprovalDecision, $this>
     */
    public function aiDecision(): HasOne
    {
        return $this->hasOne(AiApprovalDecision::class)->latestOfMany();
    }

    /**
     * What kind of media the stored proof is, from the storage convention the
     * downloaders write (`jpg` photos, `ogg` voice, `mp4` video).
     *
     * The *stored path* is the truth here, not the challenge's proof type: a
     * timed session's evidence is whatever its last proof-bearing step
     * collected, which need not match the challenge-level type. Consumers that
     * only need "is there media" keep using `proof_path`.
     *
     * @return 'image'|'voice'|'video'|null null when no proof is stored (or
     *                                      one with an unknown extension)
     */
    public function proofKind(): ?string
    {
        if ($this->proof_path === null) {
            return null;
        }

        return match (strtolower(pathinfo($this->proof_path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg', 'png', 'webp' => 'image',
            'ogg', 'oga', 'mp3', 'm4a' => 'voice',
            'mp4', 'mov', 'webm' => 'video',
            default => null,
        };
    }

    /**
     * Whether the submitted text is the phrase that was issued to this
     * participant for this period.
     *
     * Comparison is on the normalised forms so that stray whitespace, casing and
     * the Arabic/Persian glyph variants that Farsi keyboards disagree about do
     * not fail an otherwise correct answer. It stays an *exact* match on the
     * normalised strings — the mechanic is worthless if near-misses pass.
     */
    public function matchesExpectedPhrase(?string $submitted): bool
    {
        if ($this->expected_phrase === null || $submitted === null) {
            return false;
        }

        $expected = self::normalisePhrase($this->expected_phrase);

        return $expected !== '' && $expected === self::normalisePhrase($submitted);
    }

    /**
     * Fold away the differences that should not decide a check-in: casing,
     * surrounding and repeated whitespace, Arabic Yeh/Kaf where Persian Yeh/Keheh
     * was issued, and Eastern Arabic digits.
     */
    public static function normalisePhrase(string $phrase): string
    {
        $folded = str_replace(
            ['ي', 'ك'],
            ['ی', 'ک'],
            Localization::foldDigits($phrase),
        );

        // Collapse every run of whitespace, including the ZWNJ Farsi text carries.
        $collapsed = preg_replace('/[\s\x{200C}]+/u', ' ', $folded) ?? $folded;

        return mb_strtolower(trim($collapsed));
    }

    /**
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function awaitingReview(Builder $query): void
    {
        $query->where('status', CheckInStatus::Submitted);
    }

    /**
     * Rows the rollover still has to settle.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function unsettled(Builder $query): void
    {
        $query->whereIn('status', [
            CheckInStatus::Pending,
            CheckInStatus::Submitted,
            CheckInStatus::Rejected,
        ]);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CheckInStatus::class,
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }
}
