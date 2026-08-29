<?php

namespace App\Services\Ai;

use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Files\StoredAudio;
use Laravel\Ai\Files\StoredImage;
use Laravel\Ai\StructuredAnonymousAgent;
use Laravel\Ai\Transcription;

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

        $attachments = (array) ($options['attachments'] ?? []);

        if (isset($options['image'])) {
            $attachments[] = new StoredImage(
                (string) $options['image']['path'],
                $options['image']['disk'] ?? null,
            );
        }

        $agent = isset($options['schema'])
            ? new StructuredAnonymousAgent(
                instructions: (string) ($options['system'] ?? ''),
                messages: [],
                tools: [],
                schema: $options['schema'],
            )
            : new AnonymousAgent(
                instructions: (string) ($options['system'] ?? ''),
                messages: [],
                tools: [],
            );

        $response = $agent->prompt(
            $prompt,
            attachments: $attachments,
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
            structured: $this->structuredPayload($response),
        );
    }

    /**
     * Speech-to-text on the stored recording, via the SDK's transcription
     * pipeline. The caller checked the driver can transcribe at all; the
     * connection's own default model is used for the reason documented on
     * the interface.
     */
    public function transcribe(string $connection, string $path, string $disk = 'local'): string
    {
        return Transcription::of(new StoredAudio($path, $disk))
            ->generate($connection)
            ->text;
    }

    /**
     * The validated structured output, when the caller forced one.
     *
     * The agent response class is shared across the plain and structured
     * paths, and `structured` is a typed property only the latter fills — so
     * it is read defensively rather than assumed.
     *
     * @return array<string, mixed>
     */
    private function structuredPayload(mixed $response): array
    {
        $structured = $response->structured ?? null;

        return is_array($structured) ? $structured : [];
    }
}
