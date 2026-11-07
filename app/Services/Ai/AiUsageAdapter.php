<?php

namespace App\Services\Ai;

/**
 * Dispatches a provider response to whichever usage adapter recognises it.
 * One implementation per response shape; the recorder stays shape-agnostic.
 */
interface AiUsageAdapter
{
    public function handles(mixed $response): bool;

    /**
     * @return array{usage: mixed, model: ?string, metadata: array<string, mixed>}
     */
    public function extract(mixed $response): array;
}
