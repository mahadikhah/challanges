<?php

namespace App\Models;

use App\Enums\ChatPostKind;
use Carbon\CarbonImmutable;
use Database\Factories\ChallengeChatPostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One post that has already gone out into a linked chat.
 *
 * The table exists to make a re-dispatched job a no-op: a queued send that
 * ran twice, a scheduled command that fired twice, an operator replaying a
 * stalled batch — all of them converge on this row's unique key instead of a
 * duplicate message in front of an audience. The row is written before the
 * send and rolled back with the throw when the send fails, so a row means
 * "sent", never "attempted".
 *
 * @property int $id
 * @property int $challenge_chat_id
 * @property ChatPostKind $post_kind
 * @property int|null $challenge_period_id
 * @property int|null $challenge_participant_id
 * @property CarbonImmutable|null $post_date
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read ChallengeChat $chat
 * @property-read ChallengePeriod|null $period
 * @property-read ChallengeParticipant|null $participant
 */
#[Fillable([
    'challenge_chat_id',
    'post_kind',
    'challenge_period_id',
    'challenge_participant_id',
    'post_date',
])]
class ChallengeChatPost extends Model
{
    /** @use HasFactory<ChallengeChatPostFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<ChallengeChat, $this>
     */
    public function chat(): BelongsTo
    {
        return $this->belongsTo(ChallengeChat::class, 'challenge_chat_id');
    }

    /**
     * @return BelongsTo<ChallengePeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(ChallengePeriod::class, 'challenge_period_id');
    }

    /**
     * @return BelongsTo<ChallengeParticipant, $this>
     */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(ChallengeParticipant::class, 'challenge_participant_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'post_kind' => ChatPostKind::class,
            'post_date' => 'date',
        ];
    }
}
