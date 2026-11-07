<?php

namespace App\Services\Ai;

use App\Models\AiUsageReservation;

/**
 * What one provider attempt came back as — or that it was never ours to run.
 */
final readonly class AiProviderCallResult
{
    public function __construct(
        public readonly ?AiTextResult $response,
        public readonly AiProviderConfig $config,
        public readonly AiUsageReservation $reservation,
        public readonly int $attempt,
        public readonly bool $alreadyCompleted = false,
    ) {}
}
