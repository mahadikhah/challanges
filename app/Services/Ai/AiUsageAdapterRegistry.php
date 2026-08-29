<?php

namespace App\Services\Ai;

/**
 * Picks the adapter that recognises a response shape, or reports usage as
 * absent — an unrecognised shape is a missing-usage case, not an error.
 */
class AiUsageAdapterRegistry
{
    /** @var list<AiUsageAdapter> */
    private readonly array $adapters;

    public function __construct()
    {
        $this->adapters = [
            new Adapters\LaravelAiResponseAdapter,
            new Adapters\ArrayUsageAdapter,
        ];
    }

    /**
     * @return array{usage: mixed, model: ?string, metadata: array<string, mixed>}
     */
    public function extract(mixed $response): array
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->handles($response)) {
                return $adapter->extract($response);
            }
        }

        return ['usage' => null, 'model' => null, 'metadata' => []];
    }
}
