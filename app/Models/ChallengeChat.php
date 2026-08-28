<?php

namespace App\Models;

use App\Enums\TelegramChatType;
use Carbon\CarbonImmutable;
use Database\Factories\ChallengeChatFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A creator-owned channel or group registered as a challenge's broadcast
 * surface.
 *
 * The row exists in two states: *discovered* (a forwarded message named the
 * chat) and *verified* (both admin checks passed, `is_active`). Only the
 * second ever receives a post, and a lazy re-check that fails moves the row
 * back out of that state rather than deleting it — the creator is told their
 * chat went quiet, which a missing row could never explain.
 *
 * @property int $id
 * @property int $challenge_id
 * @property int $telegram_chat_id
 * @property TelegramChatType $chat_type
 * @property string $title
 * @property CarbonImmutable|null $bot_admin_verified_at
 * @property CarbonImmutable|null $creator_admin_verified_at
 * @property bool $is_active
 * @property bool $share_proof_media
 * @property bool $post_checkin_announcements
 * @property bool $post_daily_leaderboard
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Challenge $challenge
 */
#[Fillable([
    'challenge_id',
    'telegram_chat_id',
    'chat_type',
    'title',
    'bot_admin_verified_at',
    'creator_admin_verified_at',
    'is_active',
    'share_proof_media',
    'post_checkin_announcements',
    'post_daily_leaderboard',
])]
class ChallengeChat extends Model
{
    /** @use HasFactory<ChallengeChatFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Challenge, $this>
     */
    public function challenge(): BelongsTo
    {
        return $this->belongsTo(Challenge::class);
    }

    /**
     * Whether both admin checks have passed and the row may receive posts.
     */
    public function isVerified(): bool
    {
        return $this->bot_admin_verified_at !== null
            && $this->creator_admin_verified_at !== null
            && $this->is_active;
    }

    /**
     * When the older of the two admin stamps was taken — the freshness clock
     * a lazy re-verification is measured against.
     */
    public function lastVerifiedAt(): ?CarbonImmutable
    {
        return $this->bot_admin_verified_at !== null && $this->creator_admin_verified_at !== null
            ? $this->bot_admin_verified_at->min($this->creator_admin_verified_at)
            : null;
    }

    /**
     * Verified chats still allowed to receive posts.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true)
            ->whereNotNull('bot_admin_verified_at')
            ->whereNotNull('creator_admin_verified_at');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'telegram_chat_id' => 'integer',
            'chat_type' => TelegramChatType::class,
            'bot_admin_verified_at' => 'datetime',
            'creator_admin_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'share_proof_media' => 'boolean',
            'post_checkin_announcements' => 'boolean',
            'post_daily_leaderboard' => 'boolean',
        ];
    }
}
