<?php

namespace App\Services\Ai\Adapters;

use App\Services\Ai\AiUsageAdapter;
use Laravel\Ai\Responses\TextResponse;

/**
 * The native `laravel/ai` response: a `Usage` value object plus `Meta`
 * carrying the provider (our connection name) and model.
 */
class LaravelAiResponseAdapter implements AiUsageAdapter
{
    public function handles(mixed $response): bool
    {
        return $response instanceof TextResponse;
    }

    public function extract(mixed $response): array
    {
        /** @var TextResponse $response */
        return [
            'usage' => $response->usage,
            'model' => $response->meta->model,
            'metadata' => [
                'provider' => $response->meta->provider,
                'model' => $response->meta->model,
            ],
        ];
    }
}
