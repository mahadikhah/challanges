<?php

namespace App\Models;

use App\Enums\ApprovalCriteriaVerdict;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Every creator-edited criteria text that went through screening, kept so an
 * admin can see what was attempted — flagged texts are not silently discarded.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $challenge_id
 * @property string $submitted_text
 * @property ApprovalCriteriaVerdict $verdict
 * @property string|null $reason
 */
class ApprovalCriteriaScreening extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'verdict' => ApprovalCriteriaVerdict::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Challenge, $this>
     */
    public function challenge(): BelongsTo
    {
        return $this->belongsTo(Challenge::class);
    }
}
