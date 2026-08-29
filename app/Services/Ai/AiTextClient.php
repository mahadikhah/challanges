<?php

namespace App\Services\Ai;

/**
 * One narrow seam for every prompt the app makes — one implementation per
 * SDK, a fake in tests. No SDK calls scattered through the domain.
 */
interface AiTextClient
{
    /**
     * @param  array<string, mixed>  $options  `system` (string), `attachments` (list), `timeout` (int),
     *                                         `schema` (Closure(Illuminate\Contracts\JsonSchema\JsonSchema): array — forces a
     *                                         structured response, delivered as `AiTextResult::$structured`),
     *                                         `image` (array{path: string, disk?: string|null} — attached as the photo under review)
     */
    public function prompt(string $connection, ?string $model, string $prompt, array $options = []): AiTextResult;

    /**
     * Transcribe a stored audio file to text on the named connection.
     *
     * The model is deliberately not a parameter: the connection's own
     * transcription default is the one the catalog guaranteed when the
     * account was registered, and a caller passing its *chat* model here
     * would ask a transcription endpoint for a completion model.
     */
    public function transcribe(string $connection, string $path, string $disk = 'local'): string;
}
