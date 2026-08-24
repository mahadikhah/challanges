<?php

namespace App\Models;

use App\Enums\InviteStatus;
use Carbon\CarbonImmutable;
use Database\Factories\InviteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One invite code, claimable exactly once.
 *
 * @property int $id
 * @property int $inviter_id
 * @property string $code
 * @property int|null $invited_user_id
 * @property CarbonImmutable|null $credited_at
 * @property InviteStatus $status
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $inviter
 * @property-read User|null $invitedUser
 */
#[Fillable(['inviter_id', 'code', 'invited_user_id', 'credited_at', 'status'])]
class Invite extends Model
{
    /** @use HasFactory<InviteFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inviter_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function invitedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_user_id');
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    public function isClaimed(): bool
    {
        return $this->status->isClaimed();
    }

    /**
     * Whether the inviter was paid for this invite.
     */
    public function wasPaid(): bool
    {
        return $this->status->wasPaid();
    }

    /**
     * The deep link that delivers this code to the bot.
     *
     * `?start=` rather than `?startapp=`, because attribution happens on the
     * bot's first `/start` — before the Mini App is ever opened.
     */
    public function deepLink(): string
    {
        $username = ltrim(config()->string('services.telegram.bot_username'), '@');

        return "https://t.me/{$username}?start={$this->code}";
    }

    /**
     * Codes still open to a new arrival.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->where('status', InviteStatus::Pending)->whereNull('invited_user_id');
    }

    /**
     * Invites that earned their inviter coins.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function credited(Builder $query): void
    {
        $query->where('status', InviteStatus::Credited);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'credited_at' => 'datetime',
            'status' => InviteStatus::class,
        ];
    }
}
