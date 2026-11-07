<?php

namespace App\Messaging\DTO;

use App\Enums\PaymentTransactionStatus;

/**
 * What a payment rail's inquiry said about one transaction.
 *
 * `status` and `amount` are the fields crediting is decided on — everything
 * else the rail may include stays in its original shape and is not promoted
 * to facts. Optional fields stay `null` rather than being coerced: an amount
 * we could not read is not an amount we verified.
 */
final readonly class PaymentTransaction
{
    public function __construct(
        public string $id,
        public PaymentTransactionStatus $status,
        public ?int $amount,
        public ?int $userId,
    ) {}
}
