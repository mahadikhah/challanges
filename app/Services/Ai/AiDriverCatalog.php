<?php

namespace App\Services\Ai;

use App\Enums\AiCapabilityPurpose;

/**
 * The whole provider abstraction as one constant array.
 *
 * One source of truth for what each driver needs, so the admin form and
 * `AiProviderAccount::isConfigured()` can never disagree: `required` drives
 * validation, the form, and the chain's config check alike.
 */
final class AiDriverCatalog
{
    /** @var array<string, array{label: string, fields: list<array<string, mixed>>, supports_video: bool}> */
    private const DRIVERS = [
        'openai' => [
            'label' => 'OpenAI',
            'supports_video' => false,
            'fields' => [
                ['key' => 'key', 'label' => 'API key', 'required' => true, 'secret' => true],
                ['key' => 'url', 'label' => 'Base URL', 'placeholder' => 'https://api.openai.com/v1',
                    'helper' => 'Leave empty to call the vendor directly. Set to route through a proxy.'],
            ],
        ],
        'anthropic' => [
            'label' => 'Anthropic',
            'supports_video' => false,
            'fields' => [
                ['key' => 'key', 'label' => 'API key', 'required' => true, 'secret' => true],
                ['key' => 'url', 'label' => 'Base URL', 'placeholder' => 'https://api.anthropic.com'],
            ],
        ],
        'ollama' => [
            // A local server: no API key at all — which is why `key` must
            // tolerate being empty in the connection config.
            'label' => 'Ollama',
            'supports_video' => false,
            'fields' => [
                ['key' => 'url', 'label' => 'Base URL', 'required' => true, 'placeholder' => 'http://127.0.0.1:11434'],
            ],
        ],
        'openai_compatible' => [
            'label' => 'OpenAI-compatible endpoint',
            'supports_video' => false,
            'fields' => [
                ['key' => 'key', 'label' => 'API key', 'required' => false, 'secret' => true,
                    'helper' => 'Leave empty for endpoints that need no auth header.'],
                ['key' => 'url', 'label' => 'Base URL', 'required' => true, 'placeholder' => 'https://gateway.example/v1'],
            ],
        ],
    ];

    /**
     * The drivers whose provider class implements the SDK's transcription
     * contract (`TranscriptionProvider`): OpenAI and the OpenAI-compatible
     * gateway can turn audio into text. Anthropic and Ollama cannot — a voice
     * review routed to them has no way to hear the recording at all, so the
     * voice capability readout and the review path both consult this.
     *
     * @var list<string>
     */
    private const TRANSCRIPTION_DRIVERS = ['openai', 'openai_compatible'];

    /**
     * The transcription model to register per driver when the driver has no
     * SDK default of its own. The `openai_compatible` provider throws without
     * a configured `models.transcription.default`, so the connection config
     * carries one; the SDK's own default covers `openai`.
     */
    private const TRANSCRIPTION_MODEL_DEFAULTS = [
        'openai_compatible' => 'whisper-1',
    ];

    /**
     * Whether the driver's gateway would accept video bytes as a prompt
     * attachment. False across the catalog today, and verified rather than
     * assumed: in the SDK only Gemini's and OpenRouter's `MapsAttachments`
     * handle video, and neither driver is in this catalog. The day one joins,
     * its `supports_video` flag flips — and video review gains a native path
     * alongside the frame-sampling one.
     */
    public static function supportsVideoInput(string $driver): bool
    {
        return self::DRIVERS[$driver]['supports_video'] ?? false;
    }

    /**
     * Whether the driver's provider can transcribe audio at all.
     */
    public static function supportsTranscription(string $driver): bool
    {
        return in_array($driver, self::TRANSCRIPTION_DRIVERS, true);
    }

    /**
     * The transcription model a connection for this driver should carry, or
     * null when the SDK's own default is enough.
     */
    public static function transcriptionModelFor(string $driver): ?string
    {
        return self::TRANSCRIPTION_MODEL_DEFAULTS[$driver] ?? null;
    }

    /** @return list<string> */
    public static function drivers(): array
    {
        return array_keys(self::DRIVERS);
    }

    /**
     * Every known driver is offered for every purpose today: all four can
     * prompt with text and take image inputs. The moment a driver that
     * cannot serve a purpose is added, a `purposes` map keyed from the SDK's
     * provider contracts lands here — derived from what the SDK implements,
     * never hand-guessed.
     *
     * @return list<string>
     */
    public static function driversFor(AiCapabilityPurpose $purpose): array
    {
        return self::drivers();
    }

    public static function knows(string $driver): bool
    {
        return isset(self::DRIVERS[$driver]);
    }

    public static function labelFor(string $driver): string
    {
        return self::DRIVERS[$driver]['label'] ?? $driver;
    }

    /** @return list<array<string, mixed>> */
    public static function fieldsFor(string $driver): array
    {
        return self::DRIVERS[$driver]['fields'] ?? [];
    }

    /** @return list<string> */
    public static function knownKeys(string $driver): array
    {
        return array_column(self::fieldsFor($driver), 'key');
    }

    /** @return list<string> */
    public static function requiredKeys(string $driver): array
    {
        return array_column(array_filter(
            self::fieldsFor($driver),
            static fn (array $field): bool => ($field['required'] ?? false) === true,
        ), 'key');
    }
}
