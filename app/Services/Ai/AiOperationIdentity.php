<?php

namespace App\Services\Ai;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Identity of one intentional run of an AI operation.
 *
 * The `runId` is created once per run and must survive queue retries (it is
 * serialized into the job payload) — that is what makes a retry re-find its
 * own rows instead of double-charging.
 */
final readonly class AiOperationIdentity
{
    public function __construct(
        public string $operation,
        public string $runId,
        public int|string|null $subjectId = null,
        public ?string $jobClass = null,
    ) {
        if (trim($this->operation) === '' || $this->operation !== trim($this->operation)) {
            throw new InvalidArgumentException('The AI operation name must be a non-empty, trimmed string.');
        }

        if (! Str::isUuid($this->runId)) {
            throw new InvalidArgumentException('The AI operation run id must be a UUID.');
        }
    }

    public static function create(string $operation, int|string|null $subjectId = null, ?string $jobClass = null): self
    {
        return new self($operation, (string) Str::uuid(), $subjectId, $jobClass);
    }

    /**
     * Two grains, and getting them wrong is the classic bug: the identity key
     * below is shared, but reservations append `:attempt:{n}:account:{id}`
     * (one lease per account tried) while usage records append `:attempt:{n}`
     * (one row per attempt).
     */
    public function idempotencyKey(): string
    {
        return implode(':', array_filter([
            'ai',
            $this->operation,
            $this->subjectId === null ? null : (string) $this->subjectId,
            $this->runId,
        ]));
    }
}
