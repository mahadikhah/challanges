<?php

namespace App\Services\Ai;

use App\Enums\AiUsageQuality;

/**
 * The normalised token accounting for one attempt.
 *
 * All of the normalizer's judgements land here as plain integers plus a
 * quality tag; `providerReportedTotalTokens` keeps a provider's own total
 * alive even when it disagrees with input+output — both numbers are
 * preserved, never silently reconciled.
 */
final readonly class AiUsage
{
    public function __construct(
        public int $inputTokens,
        public int $outputTokens,
        public int $totalTokens,
        public ?int $providerReportedTotalTokens,
        public AiUsageQuality $quality,
        public string $driver,
        public ?string $model,
        public string $operation,
    ) {}

    public static function missing(string $driver, ?string $model, string $operation): self
    {
        return new self(0, 0, 0, null, AiUsageQuality::Missing, $driver, $model, $operation);
    }
}
