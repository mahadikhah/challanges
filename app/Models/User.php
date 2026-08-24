<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * There are two disjoint kinds of user in this one table, and the difference
 * matters everywhere: an **admin** signs in with email + password through
 * Fortify and may have no Telegram account, while a **bot user** is identified
 * only by `telegram_id` and has no credentials at all. Never treat one auth path
 * as a fallback for the other.
 *
 * @property int $id
 * @property int|null $telegram_id
 * @property string|null $telegram_username
 * @property string $name
 * @property string|null $first_name
 * @property string|null $language_code
 * @property string|null $locale
 * @property int|null $referred_by_user_id
 * @property string|null $email
 * @property CarbonImmutable|null $email_verified_at
 * @property CarbonImmutable|null $channel_verified_at
 * @property string|null $password
 * @property bool $is_admin
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property CarbonImmutable|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $referrer
 * @property-read Collection<int, User> $referrals
 * @property-read Collection<int, Challenge> $createdChallenges
 * @property-read Collection<int, ChallengeParticipant> $participations
 * @property-read Collection<int, CoinTransaction> $coinTransactions
 * @property-read Collection<int, Entitlement> $entitlements
 * @property-read Collection<int, Invite> $sentInvites
 * @property-read Invite|null $claimedInvite
 * @property-read Collection<int, StarPayment> $starPayments
 * @property-read BotConversation|null $conversation
 */
/*
| `is_admin` and `channel_verified_at` are deliberately *not* fillable. Both are
| privilege state, and neither should ever be reachable by mass assignment even
| by accident — grant them explicitly, from code that meant to.
*/
#[Fillable([
    'telegram_id',
    'telegram_username',
    'name',
    'first_name',
    'language_code',
    'locale',
    'referred_by_user_id',
    'email',
    'password',
])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements HasLocalePreference
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The user this one arrived through, if an invite brought them in.
     *
     * @return BelongsTo<self, $this>
     */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'referred_by_user_id');
    }

    /**
     * Everyone who arrived through this user's invites.
     *
     * @return HasMany<self, $this>
     */
    public function referrals(): HasMany
    {
        return $this->hasMany(self::class, 'referred_by_user_id');
    }

    /**
     * @return HasMany<Challenge, $this>
     */
    public function createdChallenges(): HasMany
    {
        return $this->hasMany(Challenge::class, 'creator_id');
    }

    /**
     * @return HasMany<ChallengeParticipant, $this>
     */
    public function participations(): HasMany
    {
        return $this->hasMany(ChallengeParticipant::class);
    }

    /**
     * The coin ledger for this user, newest entry first.
     *
     * @return HasMany<CoinTransaction, $this>
     */
    public function coinTransactions(): HasMany
    {
        return $this->hasMany(CoinTransaction::class)->orderByDesc('id');
    }

    /**
     * @return HasMany<Entitlement, $this>
     */
    public function entitlements(): HasMany
    {
        return $this->hasMany(Entitlement::class);
    }

    /**
     * Invite codes this user has minted.
     *
     * @return HasMany<Invite, $this>
     */
    public function sentInvites(): HasMany
    {
        return $this->hasMany(Invite::class, 'inviter_id');
    }

    /**
     * The invite this user arrived through, if any.
     *
     * `hasOne` because `invites.invited_user_id` is unique: a user can be
     * attributed to at most one inviter, for their whole life.
     *
     * @return HasOne<Invite, $this>
     */
    public function claimedInvite(): HasOne
    {
        return $this->hasOne(Invite::class, 'invited_user_id');
    }

    /**
     * @return HasMany<StarPayment, $this>
     */
    public function starPayments(): HasMany
    {
        return $this->hasMany(StarPayment::class);
    }

    /**
     * The bot flow this user is currently in, if any.
     *
     * `hasOne` because `bot_conversations.user_id` is unique — a user is in at
     * most one wizard at a time.
     *
     * @return HasOne<BotConversation, $this>
     */
    public function conversation(): HasOne
    {
        return $this->hasOne(BotConversation::class);
    }

    /**
     * The locale this user chose, honoured by the SetLocale middleware.
     *
     * Returns null when they have never chosen one, which lets the middleware
     * fall through to the cookie and then `Accept-Language` rather than pinning
     * everyone to the fallback.
     */
    public function preferredLocale(): ?string
    {
        return $this->locale;
    }

    /**
     * Whether this user reached us through Telegram rather than a login form.
     */
    public function isTelegramUser(): bool
    {
        return $this->telegram_id !== null;
    }

    /**
     * Whether announcement-channel membership has been confirmed.
     *
     * A cached answer, so it is re-verified on privileged actions rather than
     * trusted forever — a user can leave the channel at any time.
     */
    public function hasVerifiedChannel(): bool
    {
        return $this->channel_verified_at !== null;
    }

    /**
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function admins(Builder $query): void
    {
        $query->where('is_admin', true);
    }

    /**
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function telegram(Builder $query): void
    {
        $query->whereNotNull('telegram_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'telegram_id' => 'integer',
            'email_verified_at' => 'datetime',
            'channel_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }
}
