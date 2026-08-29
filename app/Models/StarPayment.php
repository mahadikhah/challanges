<?php

namespace App\Models;

use App\Enums\PaymentProvider;
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
 * A coin purchase over a native payment rail.
 *
 * `provider` names the rail: Telegram Stars (Phase 4) or Bale Pay (Phase 11
 * Task 3). The rail prices in its own currency — Stars for Telegram, Rial for
 * Bale — so a row carries the price in the column its rail reads and leaves
 * the other null. The shared columns (`invoice_payload`, `coin_amount`,
 * `status`, the charge id) behave identically on both rails, which is what
 * lets one webhook path settle either.
 *
 * @property int $id
 * @property int $user_id
 * @property PaymentProvider $provider
 * @property string|null $telegram_payment_charge_id
 * @property string $invoice_payload
 * @property int|null $stars_amount
 * @property int|null $rial_amount
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
    'provider',
    'telegram_payment_charge_id',
    'invoice_payload',
    'stars_amount',
    'rial_amount',
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
     * Requires a charge id and a paid status — the refund call needs the id —
     * and a rail that documents a refund path at all. Bale Pay does not (see
     * `PaymentProvider::supportsRefunds()`), so a paid Bale row is a settled
     * one; the admin panel reads this and shows no refund lever for it.
     */
    public function isRefundable(): bool
    {
        return $this->status->isRefundable()
            && $this->provider->supportsRefunds()
            && $this->telegram_payment_charge_id !== null;
    }

    /**
     * The price this row was issued at, in its own rail's currency.
     *
     * Stars for a Telegram row, Rial for a Bale row — never converted, because
     * the two are not exchange rates of each other. Null only on a row whose
     * rail was never written, which cannot have been issued.
     */
    public function price(): ?int
    {
        return match ($this->provider) {
            PaymentProvider::TelegramStars => $this->stars_amount,
            PaymentProvider::BalePay => $this->rial_amount,
        };
    }

    /**
     * Whether a pre-checkout query matches this invoice.
     *
     * Both facts are checked on the row, not trusted from the payload: the
     * currency tag is the rail's own, and the total is the price this row was
     * issued at. A disagreement means the query is not about our invoice.
     */
    public function acceptsPreCheckout(mixed $currency, mixed $totalAmount): bool
    {
        return $currency === $this->provider->currency()
            && $totalAmount !== null
            && $totalAmount === $this->price();
    }

    /**
     * The idempotency key for crediting coins for this purchase.
     *
     * Derived from the rail's charge id and namespaced per rail, so a replayed
     * payment update resolves to the same key and the ledger refuses the
     * second write — and the two rails' id spaces can never collide into one
     * key.
     */
    public function creditIdempotencyKey(): string
    {
        return match ($this->provider) {
            PaymentProvider::TelegramStars => 'star_payment:credit:'.$this->telegram_payment_charge_id,
            PaymentProvider::BalePay => 'bale_payment:credit:'.$this->telegram_payment_charge_id,
        };
    }

    /**
     * The idempotency key for clawing those coins back.
     *
     * Deliberately distinct from the credit key: a refund must be able to write
     * even though the credit already did. Only meaningful on a rail with a
     * refund path.
     */
    public function refundIdempotencyKey(): string
    {
        return match ($this->provider) {
            PaymentProvider::TelegramStars => 'star_payment:refund:'.$this->telegram_payment_charge_id,
            PaymentProvider::BalePay => 'bale_payment:refund:'.$this->telegram_payment_charge_id,
        };
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
            'provider' => PaymentProvider::class,
            'stars_amount' => 'integer',
            'rial_amount' => 'integer',
            'coin_amount' => 'integer',
            'status' => StarPaymentStatus::class,
            'payload' => 'array',
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }
}
