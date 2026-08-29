<?php

namespace App\Models;

use App\Enums\CheckInSessionStatus;
use Carbon\CarbonImmutable;
use Database\Factories\CheckInSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One participant's run through a timed-session challenge's steps, per period.
 *
 * The session is not the check-in. Only a `completed` session means anything
 * to the settlement engine — every other status is bookkeeping, which is what
 * makes the expiry sweep a cleanup job rather than a correctness dependency.
 *
 * @property int $id
 * @property int $challenge_participant_id
 * @property int $challenge_period_id
 * @property CheckInSessionStatus $status
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $completed_at
 * @property int|null $current_step_order
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read ChallengeParticipant $participant
 * @property-read ChallengePeriod $period
 * @property-read Collection<int, CheckInStepSubmission> $submissions
 */
#[Fillable([
    'challenge_participant_id',
    'challenge_period_id',
    'status',
    'started_at',
    'completed_at',
    'current_step_order',
])]
class CheckInSession extends Model
{
    /** @use HasFactory<CheckInSessionFactory> */
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
     * @return HasMany<CheckInStepSubmission, $this>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(CheckInStepSubmission::class, 'check_in_session_id');
    }

    /**
     * Whether this session is still waiting on a step.
     */
    public function isOpen(): bool
    {
        return $this->status === CheckInSessionStatus::InProgress;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CheckInSessionStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'current_step_order' => 'integer',
        ];
    }
}
