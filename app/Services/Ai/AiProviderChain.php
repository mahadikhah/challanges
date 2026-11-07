<?php

namespace App\Services\Ai;

use App\Models\AiCapability;
use App\Models\AiProviderAccount;
use Illuminate\Support\Collection;

/**
 * Who to try, in order: `available()` first (cooldown-aware), fall back to
 * `usable()` if every account is benched, null only if both are empty.
 *
 * Ordering is `sort_order` — strict priority, no weights, no round-robin.
 * Accounts are not equivalent (one is cheaper, one is the paid fallback), and
 * strict order + "skip whoever is broken" needs no shared counter, so it
 * works unchanged across many workers with no coordination.
 */
final class AiProviderChain
{
    /**
     * @param  list<AiProviderConfig>  $configs
     */
    private function __construct(
        public readonly string $key,
        public readonly array $configs,
    ) {}

    /**
     * Null when the capability has nothing to call at all — the one case a
     * caller must refuse.
     */
    public static function forKey(string $key): ?self
    {
        $capability = AiCapability::query()->where('key', $key)->first();

        if ($capability === null) {
            return null;
        }

        $configs = self::configsFrom($capability, $capability->accounts()->available()->get());

        // Every account benched at once is a DIFFERENT situation from having
        // none: the credentials are there. Refusing here keeps the feature
        // dead until a timer expires, behind a message that sends the
        // operator looking for a setting that is already correct. Spend the
        // request and let the provider's own error surface.
        if ($configs === []) {
            $configs = self::configsFrom($capability, $capability->accounts()->usable()->get());
        }

        return $configs === [] ? null : new self($key, $configs);
    }

    /**
     * Which account answered, given the connection name the SDK reports back.
     */
    public function findByConnectionName(?string $name): ?AiProviderConfig
    {
        if (blank($name)) {
            return null;
        }

        foreach ($this->configs as $config) {
            if ($config->connectionName() === $name) {
                return $config;
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, AiProviderAccount>  $accounts
     * @return list<AiProviderConfig>
     */
    private static function configsFrom(AiCapability $capability, Collection $accounts): array
    {
        $configs = [];

        foreach ($accounts as $account) {
            // Hand the parent capability down rather than re-querying it per
            // account — fromAccount() must check the master switch, and the
            // caller already has the answer.
            $account->setRelation('capability', $capability);

            $config = AiProviderConfig::fromAccount($account);

            if ($config !== null) {
                $configs[] = $config;
            }
        }

        return $configs;
    }
}
