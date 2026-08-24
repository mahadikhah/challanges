<?php

namespace App\Models;

use App\Enums\ReminderKind;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\ReminderDispatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One reminder owed to one participant for one period.
 *
 * @property int $id
 * @property int $challenge_participant_id
 * @property int $challenge_period_id
 * @property ReminderKind $kind
 * @property CarbonImmutable $scheduled_for
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read ChallengeParticipant $participant
 * @property-read ChallengePeriod $period
 */
#[Fillable([
    'challenge_participant_id',
    'challenge_period_id',
    'kind',
    'scheduled_for',
    'sent_at',
])]
class ReminderDispatch extends Model
{
    /** @use HasFactory<ReminderDispatchFactory> */
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

    public function isSent(): bool
    {
        return $this->sent_at !== null;
    }

    /**
     * Claimed but never confirmed sent — a failed send, not a missing one.
     */
    public function hasStalled(?CarbonInterface $now = null): bool
    {
        return ! $this->isSent() && $this->scheduled_for->lt($now ?? now());
    }

    /**
     * Reminders due to go out and not yet sent.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function due(Builder $query, ?CarbonInterface $now = null): void
    {
        $query->whereNull('sent_at')
            ->where('scheduled_for', '<=', $now ?? now())
            ->orderBy('scheduled_for');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ReminderKind::class,
            'scheduled_for' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }
}
