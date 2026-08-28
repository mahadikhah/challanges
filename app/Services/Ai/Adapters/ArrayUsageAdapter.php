<?php

namespace App\Services\Ai\Adapters;

use App\Services\Ai\AiUsageAdapter;

/**
 * Provider-shaped JSON arrays (OpenAI-compatible bodies and the like):
 * `['usage' => [...], 'model' => ...]`.
 */
class ArrayUsageAdapter implements AiUsageAdapter
{
    public function handles(mixed $response): bool
    {
        return is_array($response) && array_is_list($response) === false;
    }

    public function extract(mixed $response): array
    {
        /** @var array<string, mixed> $response */
        return [
            'usage' => $response['usage'] ?? null,
            'model' => isset($response['model']) && is_string($response['model']) ? $response['model'] : null,
            'metadata' => [
                'model' => isset($response['model']) && is_string($response['model']) ? $response['model'] : null,
            ],
        ];
    }
}
