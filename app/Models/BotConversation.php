<?php

namespace App\Models;

use App\Enums\ConversationState;
use Carbon\CarbonImmutable;
use Database\Factories\BotConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;

/**
 * Where a user is inside a multi-step bot flow.
 *
 * @property int $id
 * @property int $user_id
 * @property ConversationState $state
 * @property array<string, mixed>|null $payload
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'state', 'payload', 'expires_at'])]
class BotConversation extends Model
{
    /** @use HasFactory<BotConversationFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether this conversation has gone stale and should be ignored.
     */
    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Whether the next message from this user belongs to this flow.
     */
    public function isLive(): bool
    {
        return ! $this->hasExpired();
    }

    /**
     * Read one gathered answer.
     */
    public function answer(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->payload ?? [], $key, $default);
    }

    /**
     * Record an answer and move to the next state, without saving.
     *
     * Returns `$this` so a wizard step reads as one statement. Merging rather
     * than replacing means a step can be revisited without losing the answers
     * gathered around it.
     *
     * @param  array<string, mixed>  $answers
     */
    public function advanceTo(ConversationState $state, array $answers = []): static
    {
        $this->state = $state;
        $this->payload = [...$this->payload ?? [], ...$answers];

        return $this;
    }

    /**
     * Conversations still accepting input.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function live(Builder $query): void
    {
        $query->where(function (Builder $query): void {
            $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }

    /**
     * Abandoned conversations — the pruning sweep's work queue.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function expired(Builder $query): void
    {
        $query->whereNotNull('expires_at')->where('expires_at', '<=', now());
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => ConversationState::class,
            'payload' => 'array',
            'expires_at' => 'datetime',
        ];
    }
}
