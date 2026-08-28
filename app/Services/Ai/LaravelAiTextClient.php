<?php

namespace App\Services\Ai;

use Laravel\Ai\AnonymousAgent;

/**
 * The `laravel/ai` implementation of the client seam.
 *
 * The caller registers the connection first (`AiClientFactory::connectAccount`)
 * and hands over its name; the credentials therefore come from the account row
 * that was just read, never re-derived here.
 *
 * A single named provider is passed to the SDK, so its internal failover walk
 * has a one-entry list: a `FailoverableException` from the provider re-throws
 * on the final attempt *without* an event, and our own consumer loop is what
 * dispatches `ProviderFailedOver` and rotates accounts. Per-attempt budget
 * accounting is why the loop stays ours.
 */
class LaravelAiTextClient implements AiTextClient
{
    public function prompt(string $connection, ?string $model, string $prompt, array $options = []): AiTextResult
    {
        $startedAt = hrtime(true);

        $response = (new AnonymousAgent(
            instructions: (string) ($options['system'] ?? ''),
            messages: [],
            tools: [],
        ))->prompt(
            $prompt,
            attachments: (array) ($options['attachments'] ?? []),
            provider: $connection,
            model: $model,
            timeout: isset($options['timeout']) ? (int) $options['timeout'] : null,
        );

        return new AiTextResult(
            text: $response->text,
            connection: $response->meta->provider ?? $connection,
            model: $response->meta->model ?? $model,
            response: $response,
            durationMs: (int) round((hrtime(true) - $startedAt) / 1e6),
        );
    }
}
