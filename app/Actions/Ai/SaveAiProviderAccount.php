<?php

namespace App\Actions\Ai;

use App\Models\AiProviderAccount;
use App\Services\Ai\AiDriverCatalog;
use Illuminate\Support\Facades\DB;

/**
 * The single create-and-update path for provider accounts — the panel, and
 * anything else that manages credentials, writes through here.
 *
 * Secrets are write-only by construction: the request validates the shape of
 * each config field, and this action decides the value. A secret field left
 * blank or holding the `__set__` sentinel keeps the stored credential; a new
 * value replaces it. The stored key therefore never has to travel back to
 * the browser for an edit to work.
 */
class SaveAiProviderAccount
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(AiProviderAccount $account, array $attributes): AiProviderAccount
    {
        return DB::transaction(function () use ($account, $attributes): AiProviderAccount {
            $driver = (string) $attributes['driver'];

            $account->fill([
                'ai_capability_id' => (int) $attributes['ai_capability_id'],
                'name' => $attributes['name'],
                'driver' => $driver,
                'model' => $attributes['model'],
                'is_active' => (bool) $attributes['is_active'],
                'sort_order' => (int) ($attributes['sort_order'] ?? 0),
                'input_token_limit' => $attributes['input_token_limit'] ?? null,
                'output_token_limit' => $attributes['output_token_limit'] ?? null,
                'total_token_limit' => $attributes['total_token_limit'] ?? null,
                'limit_period' => $attributes['limit_period'],
                'limit_timezone' => $attributes['limit_timezone'],
                'input_token_price_per_million' => $attributes['input_token_price_per_million'] ?? null,
                'output_token_price_per_million' => $attributes['output_token_price_per_million'] ?? null,
            ]);

            $account->config = $this->mergedConfig($account, $driver, (array) ($attributes['config'] ?? []));

            $account->save();

            return $account->refresh();
        });
    }

    /**
     * Per field: a secret keeps its stored value unless a real new value
     * arrives; a non-secret field always takes what was submitted.
     *
     * @param  array<string, mixed>  $submitted
     * @return array<string, string>
     */
    private function mergedConfig(AiProviderAccount $account, string $driver, array $submitted): array
    {
        $stored = (array) ($account->config ?? []);

        // Switching drivers must not carry the old vendor's key across: the
        // field names happen to match, the credentials do not.
        if ($account->exists && (string) $account->getOriginal('driver') !== $driver) {
            $stored = [];
        }
        $config = [];

        foreach (AiDriverCatalog::knownKeys($driver) as $key) {
            if (in_array($key, AiDriverCatalog::secretKeys($driver), true)) {
                $value = trim((string) ($submitted[$key] ?? ''));

                // Blank or the mask sentinel means "unchanged" — including on
                // create, where there is nothing stored and the field simply
                // stays empty (legitimate for auth-free endpoints).
                $config[$key] = ($value === '' || $value === '__set__')
                    ? ($stored[$key] ?? '')
                    : $value;

                continue;
            }

            $config[$key] = trim((string) ($submitted[$key] ?? ''));
        }

        // Blank fields are dropped rather than stored as empty strings, so
        // `isConfigured()`'s blank() check and the stored shape agree.
        return array_filter($config, static fn (string $value): bool => $value !== '');
    }
}
