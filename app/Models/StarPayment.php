<?php

namespace App\Models;

use App\Enums\StarPaymentStatus;
use Carbon\CarbonImmutable;
use Database\Factories\StarPaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Telegram Stars purchase.
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $telegram_payment_charge_id
 * @property string $invoice_payload
 * @property int $stars_amount
 * @property int $coin_amount
 * @property StarPaymentStatus $status
 * @property array<string, mixed>|null $payload
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $refunded_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 */
#[Fillable([
    'user_id',
    'telegram_payment_charge_id',
    'invoice_payload',
    'stars_amount',
    'coin_amount',
    'status',
    'payload',
    'paid_at',
    'refunded_at',
])]
class StarPayment extends Model
{
    /** @use HasFactory<StarPaymentFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPaid(): bool
    {
        return $this->status->isPaid();
    }

    /**
     * Whether a refund can still be issued against this purchase.
     *
     * Requires a charge id as well as a paid status: `refundStarPayment` needs
     * the id, so a paid row without one cannot be refunded through the API.
     */
    public function isRefundable(): bool
    {
        return $this->status->isRefundable() && $this->telegram_payment_charge_id !== null;
    }

    /**
     * The idempotency key for crediting coins for this purchase.
     *
     * Derived from Telegram's charge id, so a replayed `successful_payment`
     * update resolves to the same key and the ledger refuses the second write.
     */
    public function creditIdempotencyKey(): string
    {
        return 'star_payment:credit:'.$this->telegram_payment_charge_id;
    }

    /**
     * The idempotency key for clawing those coins back.
     *
     * Deliberately distinct from the credit key: a refund must be able to write
     * even though the credit already did.
     */
    public function refundIdempotencyKey(): string
    {
        return 'star_payment:refund:'.$this->telegram_payment_charge_id;
    }

    /**
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function paid(Builder $query): void
    {
        $query->where('status', StarPaymentStatus::Paid);
    }

    /**
     * Invoices created but never resolved — abandoned carts, mostly.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->where('status', StarPaymentStatus::Pending);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stars_amount' => 'integer',
            'coin_amount' => 'integer',
            'status' => StarPaymentStatus::class,
            'payload' => 'array',
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }
}
