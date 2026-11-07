<?php

namespace App\Models;

use App\Enums\CoinTransactionReason;
use Carbon\CarbonImmutable;
use Database\Factories\CoinTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One entry in the coin ledger.
 *
 * Append-only by convention: nothing in the app updates or deletes a row here.
 * A mistake is corrected by writing a compensating entry, so the history stays
 * readable as the sequence of things that actually happened.
 *
 * @property int $id
 * @property int $user_id
 * @property int $amount
 * @property CoinTransactionReason $reason
 * @property int $balance_after
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property string $idempotency_key
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 * @property-read Model|null $reference
 */
#[Fillable([
    'user_id',
    'amount',
    'reason',
    'balance_after',
    'reference_type',
    'reference_id',
    'idempotency_key',
])]
class CoinTransaction extends Model
{
    /** @use HasFactory<CoinTransactionFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whatever caused this entry — a `StarPayment`, an `Invite`, a `Challenge`.
     *
     * @return MorphTo<Model, $this>
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function isCredit(): bool
    {
        return $this->amount > 0;
    }

    public function isDebit(): bool
    {
        return $this->amount < 0;
    }

    /**
     * The magnitude, regardless of direction — what a statement line shows next
     * to a `+` or `−`.
     */
    public function magnitude(): int
    {
        return abs($this->amount);
    }

    /**
     * Whether the recorded sign agrees with what the reason permits.
     *
     * A row that fails this is corrupt: it means something wrote to the table
     * without going through `CoinLedger`.
     */
    public function hasConsistentSign(): bool
    {
        return $this->amount !== 0
            && ($this->amount > 0) === $this->reason->isCredit();
    }

    /**
     * A user's statement, newest first.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function forUser(Builder $query, User|int $user): void
    {
        $query->where('user_id', $user instanceof User ? $user->id : $user)
            ->orderByDesc('id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'reason' => CoinTransactionReason::class,
            'balance_after' => 'integer',
        ];
    }
}
