<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\ChallengePeriodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One period on a challenge's timeline.
 *
 * The boundaries are a half-open interval — `starts_at` inclusive, `ends_at`
 * exclusive — so consecutive periods touch without overlapping and no instant
 * belongs to two of them.
 *
 * @property int $id
 * @property int $challenge_id
 * @property int $index
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property CarbonImmutable|null $rolled_over_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Challenge $challenge
 * @property-read Collection<int, CheckIn> $checkIns
 * @property-read Collection<int, ReminderDispatch> $reminderDispatches
 */
#[Fillable(['challenge_id', 'index', 'starts_at', 'ends_at', 'rolled_over_at'])]
class ChallengePeriod extends Model
{
    /** @use HasFactory<ChallengePeriodFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Challenge, $this>
     */
    public function challenge(): BelongsTo
    {
        return $this->belongsTo(Challenge::class);
    }

    /**
     * @return HasMany<CheckIn, $this>
     */
    public function checkIns(): HasMany
    {
        return $this->hasMany(CheckIn::class);
    }

    /**
     * @return HasMany<ReminderDispatch, $this>
     */
    public function reminderDispatches(): HasMany
    {
        return $this->hasMany(ReminderDispatch::class);
    }

    /**
     * Whether the given instant falls inside this period.
     *
     * `ends_at` is exclusive, hence `lt` rather than `lte`: the boundary instant
     * belongs to the next period.
     */
    public function contains(CarbonInterface $moment): bool
    {
        return $moment->gte($this->starts_at) && $moment->lt($this->ends_at);
    }

    /**
     * Whether the period has elapsed and can be settled.
     */
    public function hasEnded(?CarbonInterface $now = null): bool
    {
        return ($now ?? now())->gte($this->ends_at);
    }

    /**
     * Whether the period has already been settled. Rollover skips these, which
     * is what makes it safe to re-run.
     */
    public function isRolledOver(): bool
    {
        return $this->rolled_over_at !== null;
    }

    /**
     * The period that a moment falls inside — the one a check-in submitted now
     * belongs to.
     *
     * The SQL mirrors `contains()` exactly, `ends_at` exclusive included, so the
     * question "which period is open?" cannot be answered one way in PHP and
     * another way in the database.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function containing(Builder $query, ?CarbonInterface $moment = null): void
    {
        $moment ??= now();

        $query->where('starts_at', '<=', $moment)
            ->where('ends_at', '>', $moment);
    }

    /**
     * Periods that have elapsed but have not been settled yet — the rollover
     * sweep's work queue.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function awaitingRollover(Builder $query, ?CarbonInterface $now = null): void
    {
        $query->whereNull('rolled_over_at')
            ->where('ends_at', '<=', $now ?? now());
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'index' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'rolled_over_at' => 'datetime',
        ];
    }
}
