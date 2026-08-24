<?php

namespace App\Models;

use App\Enums\ParticipantStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ChallengeParticipantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One user's participation in one challenge.
 *
 * @property int $id
 * @property int $challenge_id
 * @property int $user_id
 * @property CarbonImmutable $joined_at
 * @property int $joined_period_index
 * @property ParticipantStatus $status
 * @property int $current_streak
 * @property int $longest_streak
 * @property int $freezes_total
 * @property int $freezes_used
 * @property int $streak_resets_count
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Challenge $challenge
 * @property-read User $user
 * @property-read Collection<int, CheckIn> $checkIns
 * @property-read Collection<int, ReminderDispatch> $reminderDispatches
 */
#[Fillable([
    'challenge_id',
    'user_id',
    'joined_at',
    'joined_period_index',
    'status',
    'current_streak',
    'longest_streak',
    'freezes_total',
    'freezes_used',
    'streak_resets_count',
])]
class ChallengeParticipant extends Model
{
    /** @use HasFactory<ChallengeParticipantFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Challenge, $this>
     */
    public function challenge(): BelongsTo
    {
        return $this->belongsTo(Challenge::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
     * Freezes still available to absorb a missed period.
     */
    public function freezesRemaining(): int
    {
        return max(0, $this->freezes_total - $this->freezes_used);
    }

    public function hasFreezeAvailable(): bool
    {
        return $this->freezesRemaining() > 0;
    }

    /**
     * Whether this participant owes a check-in for the given period.
     *
     * False for periods that closed before they joined: a late joiner catches up
     * on the shared timeline but is not judged on history they were absent for.
     */
    public function owesPeriod(ChallengePeriod $period): bool
    {
        return $this->status->owesCheckIns()
            && $period->index >= $this->joined_period_index;
    }

    /**
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', ParticipantStatus::Active);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'joined_period_index' => 'integer',
            'status' => ParticipantStatus::class,
            'current_streak' => 'integer',
            'longest_streak' => 'integer',
            'freezes_total' => 'integer',
            'freezes_used' => 'integer',
            'streak_resets_count' => 'integer',
        ];
    }
}
