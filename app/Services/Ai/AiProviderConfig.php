<?php

namespace App\Services\Ai;

use App\Models\AiProviderAccount;

/**
 * One resolved, callable account — an immutable value object that resolves to
 * null rather than a half-filled object.
 */
final readonly class AiProviderConfig
{
    public const CONNECTION_PREFIX = 'ai_';

    public function __construct(
        public int $accountId,
        public string $key,
        public string $name,
        public string $driver,
        public ?string $model,
        /** @var array<string, string> */
        public array $credentials,
    ) {}

    public static function fromAccount(?AiProviderAccount $account): ?self
    {
        if ($account === null || ! $account->is_active || ! $account->isConfigured()) {
            return null;
        }

        $capability = $account->capability;

        if ($capability === null || ! $capability->is_active) {
            return null; // master switch
        }

        $driver = trim((string) $account->driver);
        $config = (array) ($account->config ?? []);
        $credentials = [];

        foreach (AiDriverCatalog::knownKeys($driver) as $key) {
            if (filled($config[$key] ?? null)) {
                $credentials[$key] = trim((string) $config[$key]);
            }
        }

        return new self(
            (int) $account->getKey(),
            (string) $capability->key,
            (string) $account->name,
            $driver,
            filled($account->model) ? trim((string) $account->model) : null,
            $credentials,
        );
    }

    /**
     * The name this account is registered under with the SDK.
     */
    public function connectionName(): string
    {
        return self::CONNECTION_PREFIX.$this->key.'_'.$this->accountId;
    }

    /**
     * The account id inside a connection name, or null when the name is not
     * one of ours — the only route from "connection X failed" to "row X must
     * be benched".
     */
    public static function accountIdFromConnectionName(?string $name): ?int
    {
        if (blank($name) || ! str_starts_with($name, self::CONNECTION_PREFIX)) {
            return null;
        }

        $id = mb_substr($name, mb_strrpos($name, '_') + 1);

        return ctype_digit($id) ? (int) $id : null;
    }

    /**
     * The live account row, or null when it has vanished mid-run.
     */
    public function account(): ?AiProviderAccount
    {
        return AiProviderAccount::query()->find($this->accountId);
    }

    /**
     * @return array<string, mixed>
     */
    public function toConnectionConfig(): array
    {
        // `key` is always present, even empty: many SDK base classes read
        // $config['key'] with no null-coalesce, and a local model server is
        // the realistic case where no API key exists.
        $config = ['driver' => $this->driver, 'key' => '', ...$this->credentials];

        // A transcription-capable driver that has no SDK default of its own
        // needs one in the connection config, or every speech-to-text call
        // would die on a missing `models.transcription.default` (Phase 14
        // Task 3's voice review path).
        $transcriptionModel = AiDriverCatalog::transcriptionModelFor($this->driver);

        if ($transcriptionModel !== null) {
            $config['models'] = ['transcription' => ['default' => $transcriptionModel]];
        }

        return $config;
    }
}
