<?php

namespace App\Actions\Payments;

use App\Models\StarPayment;

/**
 * What `CreateStarsInvoice` hands back: the row the purchase lives on, and the
 * link the payer follows. A class rather than a tuple so call sites read
 * `$invoice->link` instead of unpacking positional array keys.
 */
final readonly class StarsInvoice
{
    public function __construct(
        public StarPayment $payment,
        public string $link,
    ) {}
}
