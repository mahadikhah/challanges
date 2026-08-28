<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CheckInStepSubmissionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a participant handed in at one step of one session.
 *
 * `proof_path` is the same storage-path convention as `check_ins.proof_path`
 * — one proof-storage shape platform-wide, deliberately not a second one.
 *
 * @property int $id
 * @property int $check_in_session_id
 * @property int $challenge_step_id
 * @property CarbonImmutable $submitted_at
 * @property string|null $proof_path
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read CheckInSession $session
 * @property-read ChallengeStep $step
 */
#[Fillable([
    'check_in_session_id',
    'challenge_step_id',
    'submitted_at',
    'proof_path',
])]
class CheckInStepSubmission extends Model
{
    /** @use HasFactory<CheckInStepSubmissionFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<CheckInSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(CheckInSession::class, 'check_in_session_id');
    }

    /**
     * @return BelongsTo<ChallengeStep, $this>
     */
    public function step(): BelongsTo
    {
        return $this->belongsTo(ChallengeStep::class, 'challenge_step_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
        ];
    }
}
