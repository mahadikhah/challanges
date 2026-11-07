<?php

namespace App\Models;

use App\Enums\StepInputType;
use Carbon\CarbonImmutable;
use Database\Factories\ChallengeStepFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One step in a timed-session challenge's design.
 *
 * The row is what was asked, at design time, and is immutable in practice —
 * a design cannot be edited once the challenge has participants — so
 * submissions referencing it reference the original instruction, not a moving
 * target.
 *
 * @property int $id
 * @property int $challenge_id
 * @property int $step_order
 * @property StepInputType $input_type
 * @property int $min_wait_seconds
 * @property int|null $voice_max_seconds
 * @property string|null $label
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Challenge $challenge
 * @property-read Collection<int, CheckInStepSubmission> $submissions
 */
#[Fillable([
    'challenge_id',
    'step_order',
    'input_type',
    'min_wait_seconds',
    'voice_max_seconds',
    'label',
])]
class ChallengeStep extends Model
{
    /** @use HasFactory<ChallengeStepFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Challenge, $this>
     */
    public function challenge(): BelongsTo
    {
        return $this->belongsTo(Challenge::class);
    }

    /**
     * @return HasMany<CheckInStepSubmission, $this>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(CheckInStepSubmission::class);
    }

    /**
     * The instant this step's gate opens.
     *
     * A step is satisfied no earlier than `min_wait_seconds` after the previous
     * step was submitted — or after the session started, for step 1.
     */
    public function opensAt(CarbonImmutable $sessionStartedAt, ?CarbonImmutable $previousSubmittedAt): CarbonImmutable
    {
        return ($previousSubmittedAt ?? $sessionStartedAt)->addSeconds($this->min_wait_seconds);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'step_order' => 'integer',
            'input_type' => StepInputType::class,
            'min_wait_seconds' => 'integer',
            'voice_max_seconds' => 'integer',
        ];
    }
}
