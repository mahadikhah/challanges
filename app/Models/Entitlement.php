<?php

namespace App\Models;

use App\Enums\EntitlementSource;
use App\Enums\EntitlementType;
use Carbon\CarbonImmutable;
use Database\Factories\EntitlementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One slot: permission to create, or to join, one challenge.
 *
 * @property int $id
 * @property int $user_id
 * @property EntitlementType $type
 * @property EntitlementSource $source
 * @property CarbonImmutable|null $consumed_at
 * @property int|null $challenge_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 * @property-read Challenge|null $challenge
 */
#[Fillable(['user_id', 'type', 'source', 'consumed_at', 'challenge_id'])]
class Entitlement extends Model
{
    /** @use HasFactory<EntitlementFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The challenge this slot was spent on, if it has been spent.
     *
     * @return BelongsTo<Challenge, $this>
     */
    public function challenge(): BelongsTo
    {
        return $this->belongsTo(Challenge::class);
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function isAvailable(): bool
    {
        return ! $this->isConsumed();
    }

    /**
     * Whether a coin transaction should exist for this slot.
     */
    public function wasPaidFor(): bool
    {
        return $this->source->isPaid();
    }

    /**
     * Unspent slots — what a "can I join?" check counts.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function available(Builder $query, ?EntitlementType $type = null): void
    {
        $query->whereNull('consumed_at')
            ->when($type !== null, fn (Builder $query) => $query->where('type', $type));
    }

    /**
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function consumed(Builder $query): void
    {
        $query->whereNotNull('consumed_at');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => EntitlementType::class,
            'source' => EntitlementSource::class,
            'consumed_at' => 'datetime',
        ];
    }
}
