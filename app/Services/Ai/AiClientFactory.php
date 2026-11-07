<?php

namespace App\Services\Ai;

use Laravel\Ai\Ai;

/**
 * Registers an account's credentials with the SDK at call time, then forgets
 * the memoised instance.
 *
 * Managers read `ai.providers.<name>` lazily, when a provider is first
 * resolved, so writing runtime config immediately before the call is enough —
 * no boot-order problem, and credentials never touch a config file on disk.
 *
 * The manager memoises resolved instances, and the same worker process runs
 * many queued jobs: without `forgetInstance`, a stale client survives an
 * operator editing credentials mid-process. Forget on every configure call;
 * the DB row stays authoritative.
 */
class AiClientFactory
{
    /**
     * Register the account under its connection name and return that name.
     */
    public function connectAccount(AiProviderConfig $config): string
    {
        $name = $config->connectionName();

        config()->set('ai.providers.'.$name, $config->toConnectionConfig());
        Ai::forgetInstance($name);

        return $name;
    }
}
