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
    /** @var array<string, array{label: string, fields: list<array<string, mixed>>}> */
    private const DRIVERS = [
        'openai' => [
            'label' => 'OpenAI',
            'fields' => [
                ['key' => 'key', 'label' => 'API key', 'required' => true, 'secret' => true],
                ['key' => 'url', 'label' => 'Base URL', 'placeholder' => 'https://api.openai.com/v1',
                    'helper' => 'Leave empty to call the vendor directly. Set to route through a proxy.'],
            ],
        ],
        'anthropic' => [
            'label' => 'Anthropic',
            'fields' => [
                ['key' => 'key', 'label' => 'API key', 'required' => true, 'secret' => true],
                ['key' => 'url', 'label' => 'Base URL', 'placeholder' => 'https://api.anthropic.com'],
            ],
        ],
        'ollama' => [
            // A local server: no API key at all — which is why `key` must
            // tolerate being empty in the connection config.
            'label' => 'Ollama',
            'fields' => [
                ['key' => 'url', 'label' => 'Base URL', 'required' => true, 'placeholder' => 'http://127.0.0.1:11434'],
            ],
        ],
        'openai_compatible' => [
            'label' => 'OpenAI-compatible endpoint',
            'fields' => [
                ['key' => 'key', 'label' => 'API key', 'required' => false, 'secret' => true,
                    'helper' => 'Leave empty for endpoints that need no auth header.'],
                ['key' => 'url', 'label' => 'Base URL', 'required' => true, 'placeholder' => 'https://gateway.example/v1'],
            ],
        ],
    ];

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
