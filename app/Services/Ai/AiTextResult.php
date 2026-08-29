<?php

namespace App\Services\Ai;

/**
 * What one prompt call came back with.
 *
 * `connection` — which account actually answered — is not optional: provenance
 * and cooldown bookkeeping both resolve the account from the SDK-reported
 * connection name, never from the one the caller aimed at.
 */
final readonly class AiTextResult
{
    /**
     * @param  array<string, mixed>  $structured  the validated structured output when the prompt forced one, empty otherwise
     */
    public function __construct(
        public string $text,
        public string $connection,
        public ?string $model,
        /** The SDK response object, for the usage adapters to read. */
        public mixed $response,
        public int $durationMs = 0,
        public readonly array $structured = [],
    ) {}

    public function responseConnection(): string
    {
        return $this->connection;
    }
}
