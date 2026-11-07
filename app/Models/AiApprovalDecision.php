<?php

namespace App\Models;

use App\Enums\AiDecisionOutcome;
use App\Enums\AiReviewPath;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One AI moderation call on one submitted photo, applied or not.
 *
 * The audit trail for AI review: what was asked of which account, what came
 * back, how confident it claimed to be, and whether the answer was trusted
 * enough to act on. A row exists for every call — including the ones that fell
 * back to the manual queue — because "the AI decided nothing" must be visible
 * as a recorded event, not implied by absence.
 *
 * `reason`, `transcript` and `raw_response` are display and audit data only.
 * Nothing anywhere may act on their contents (§2.8): they are never
 * evaluated, templated, or queried against.
 *
 * @property int $id
 * @property int $check_in_id
 * @property string|null $connection
 * @property string|null $model
 * @property AiReviewPath|null $review_path
 * @property AiDecisionOutcome $outcome
 * @property bool|null $approved
 * @property float|null $confidence
 * @property string|null $reason
 * @property string|null $transcript
 * @property int|null $latency_ms
 * @property string|null $raw_response
 */
class AiApprovalDecision extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'outcome' => AiDecisionOutcome::class,
            'review_path' => AiReviewPath::class,
            'approved' => 'boolean',
            'confidence' => 'float',
        ];
    }

    /**
     * @return BelongsTo<CheckIn, $this>
     */
    public function checkIn(): BelongsTo
    {
        return $this->belongsTo(CheckIn::class);
    }
}
