<?php

use App\Actions\Ai\RunAiProviderChainAction;
use App\Exceptions\AiProviderNotConfiguredException;
use App\Models\AiCapability;
use App\Models\AiProviderAccount;
use App\Services\Ai\AiClientFactory;
use App\Services\Ai\AiOperationIdentity;
use App\Services\Ai\AiProviderChain;
use App\Services\Ai\AiProviderConfig;
use App\Services\Ai\AiTextClient;
use App\Services\Ai\AiTextResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\InsufficientCreditsException;

uses(RefreshDatabase::class);

const FIRST_ENDPOINT = 'https://first.example/v1';
const SECOND_ENDPOINT = 'https://second.example/v1';

beforeEach(function (): void {
    Http::preventStrayRequests();
});

/**
 * The capability row the seeded keys don't give us: fresh key per call, so
 * tests never share limits or accounts.
 */
function chainCapability(string $key = 'text_capability'): AiCapability
{
    return AiCapability::factory()->active()->create(['key' => $key.'_'.uniqid()]);
}

/**
 * @param  list<array{name: string, model: string, config: array<string, string>}>  $accounts
 * @return list<AiProviderAccount>
 */
function configureAiChain(AiCapability $capability, array $accounts): array
{
    $created = [];

    foreach ($accounts as $index => $definition) {
        $created[] = AiProviderAccount::factory()->create([
            'ai_capability_id' => $capability->getKey(),
            'sort_order' => $index,
            'driver' => 'openai_compatible',
        ] + $definition);
    }

    return $created;
}

/**
 * @param  list<array{name: string, model: string, config: array<string, string>}>  $definitions
 */
function accountsAtBothEndpoints(AiCapability $capability, array $definitions = [
    ['name' => 'primary', 'model' => 'primary-model', 'config' => ['key' => 'first-key', 'url' => FIRST_ENDPOINT]],
    ['name' => 'standby', 'model' => 'standby-model', 'config' => ['key' => 'second-key', 'url' => SECOND_ENDPOINT]],
]): array
{
    return configureAiChain($capability, $definitions);
}

/**
 * A chat-completions success body with a usage block — the wire shape the
 * SDK's openai_compatible gateway actually posts to and parses.
 */
function aiCompletion(?string $model = null): array
{
    return [
        'model' => $model ?? 'primary-model',
        'choices' => [['message' => ['role' => 'assistant', 'content' => 'a considered answer']]],
        'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 40],
    ];
}

function runChainOperation(string $capabilityKey): AiTextResult
{
    return app(RunAiProviderChainAction::class)(
        $capabilityKey,
        AiOperationIdentity::create('text_operation'),
        fn (string $connection, ?string $model): AiTextResult => app(AiTextClient::class)
            ->prompt($connection, $model, 'Say something useful.'),
    );
}

it('offers accounts in the operator order and excludes the switched-off, unconfigured, and cooling', function (): void {
    $capability = chainCapability();

    [$first, $second] = accountsAtBothEndpoints($capability);
    unset($first, $second);

    AiProviderAccount::factory()->create([ // switched off
        'ai_capability_id' => $capability->getKey(), 'name' => 'off', 'sort_order' => 3,
        'driver' => 'openai_compatible', 'model' => 'x', 'config' => ['key' => 'k', 'url' => FIRST_ENDPOINT],
        'is_active' => false,
    ]);

    AiProviderAccount::factory()->unconfigured()->create([ // missing the required URL
        'ai_capability_id' => $capability->getKey(), 'name' => 'half-filled', 'sort_order' => 5,
    ]);

    $cooling = AiProviderAccount::factory()->configured()->coolingDown()->create([
        'ai_capability_id' => $capability->getKey(), 'name' => 'benched', 'sort_order' => 0,
    ]);

    $chain = AiProviderChain::forKey($capability->key);

    expect($chain)->not->toBeNull()
        ->and($chain->configs)->toHaveCount(2)
        ->and($chain->configs[0]->name)->toBe('primary')
        ->and($chain->configs[1]->name)->toBe('standby')
        ->and($chain->findByConnectionName($chain->configs[1]->connectionName())->name)->toBe('standby')
        ->and($cooling->isCoolingDown())->toBeTrue();

    expect(AiProviderChain::forKey('no_such_capability'))->toBeNull();
});

it('returns null for the chain when the capability switch is off, even with live accounts', function (): void {
    $capability = AiCapability::factory()->create(['key' => 'dark_capability_'.uniqid(), 'is_active' => false]);

    accountsAtBothEndpoints($capability);

    expect(AiProviderChain::forKey($capability->key))->toBeNull();
});

it('still tries every account when all of them are benched', function (): void {
    $capability = chainCapability();

    [$primary, $standby] = accountsAtBothEndpoints($capability);
    $primary->update(['unavailable_until' => now()->addMinutes(30)]);
    $standby->update(['unavailable_until' => now()->addMinutes(30)]);

    $chain = AiProviderChain::forKey($capability->key);

    expect($chain)->not->toBeNull()->and($chain->configs)->toHaveCount(2);
});

it('answers through the standby account when the first has no balance left', function (): void {
    $capability = chainCapability();

    [$primary, $standby] = accountsAtBothEndpoints($capability);

    Http::fake([
        'first.example/*' => Http::response(['error' => ['message' => 'Insufficient balance.']], 402),
        'second.example/*' => Http::response(aiCompletion('standby-model')),
    ]);

    $result = runChainOperation($capability->key);

    expect($result->text)->toBe('a considered answer');

    Http::assertSentCount(2); // it really did try both

    // Provenance names the account that ANSWED, not the one aimed at.
    expect($standby->refresh()->last_succeeded_at)->not->toBeNull()
        ->and($primary->refresh()->last_succeeded_at)->toBeNull()
        ->and($primary->refresh()->last_failure_reason)->toBe('InsufficientCreditsException')
        ->and($primary->refresh()->unavailable_until->diffInMinutes(now()->addMinutes(60)))->toBeLessThan(2);
});

it('benches a rate-limited or overloaded account briefly', function (int $status, string $reason): void {
    $capability = chainCapability();

    [$primary] = accountsAtBothEndpoints($capability, [
        ['name' => 'primary', 'model' => 'primary-model', 'config' => ['key' => 'first-key', 'url' => FIRST_ENDPOINT]],
        ['name' => 'standby', 'model' => 'standby-model', 'config' => ['key' => 'second-key', 'url' => SECOND_ENDPOINT]],
    ]);

    Http::fake([
        'first.example/*' => Http::response(['error' => ['message' => 'slow down']], $status),
        'second.example/*' => Http::response(aiCompletion('standby-model')),
    ]);

    runChainOperation($capability->key);

    expect($primary->refresh()->last_failure_reason)->toBe($reason)
        ->and($primary->refresh()->unavailable_until->diffInMinutes(now()->addMinutes(2)))->toBeLessThan(1);
})->with([
    'rate limited' => [429, 'RateLimitedException'],
    'overloaded' => [503, 'ProviderOverloadedException'],
]);

it('keeps the cooldown across calls: the second run sends nothing to the benched host', function (): void {
    $capability = chainCapability();

    accountsAtBothEndpoints($capability);

    Http::fake([
        'first.example/*' => Http::response(['error' => ['message' => 'Insufficient balance.']], 402),
        'second.example/*' => Http::response(aiCompletion('standby-model')),
    ]);

    runChainOperation($capability->key);
    runChainOperation($capability->key);

    // The first run probed first.example once and paid for it with a 402;
    // the second run must go straight to the standby without re-probing.
    $probes = collect(Http::recorded())
        ->filter(fn (array $pair): bool => str_contains($pair[0]->url(), 'first.example'))
        ->count();

    expect($probes)->toBe(1);
});

it('scopes a cooldown to its own capability', function (): void {
    $capabilityA = chainCapability('cap_a');
    $capabilityB = chainCapability('cap_b');

    [$benched] = accountsAtBothEndpoints($capabilityA);
    $benched->update(['unavailable_until' => now()->addMinutes(30)]);

    $accountsB = accountsAtBothEndpoints($capabilityB);

    $chainB = AiProviderChain::forKey($capabilityB->key);

    expect($chainB)->not->toBeNull()
        ->and($chainB->configs)->toHaveCount(2)
        ->and($chainB->configs[0]->name)->toBe($accountsB[0]->name);
});

it('refuses without spending when nothing is configured at all', function (): void {
    $capability = chainCapability('empty_capability');

    expect(fn () => runChainOperation($capability->key))
        ->toThrow(AiProviderNotConfiguredException::class);

    Http::assertNothingSent();
});

it('re-throws the provider error after a total outage, never a generic not-configured', function (): void {
    $capability = chainCapability();

    accountsAtBothEndpoints($capability);

    Http::fake([
        'first.example/*' => Http::response(['error' => ['message' => 'Insufficient balance.']], 402),
        'second.example/*' => Http::response(['error' => ['message' => 'Insufficient balance.']], 402),
    ]);

    expect(fn () => runChainOperation($capability->key))
        ->toThrow(InsufficientCreditsException::class);
});

it('registers one connection per account, credentials decrypted, key always present', function (): void {
    $capability = chainCapability();

    [$primary, $standby] = accountsAtBothEndpoints($capability);

    $factory = app(AiClientFactory::class);
    $chain = AiProviderChain::forKey($capability->key);

    $names = [];

    foreach ($chain->configs as $index => $config) {
        $name = $factory->connectAccount($config);

        expect($name)->toBe($config->connectionName());

        $registered = config('ai.providers.'.$name);

        expect($registered['driver'])->toBe('openai_compatible')
            ->and($registered['key'])->toBe($index === 0 ? 'first-key' : 'second-key')
            ->and($registered['url'])->toBe($index === 0 ? FIRST_ENDPOINT : SECOND_ENDPOINT);

        $names[] = $name;
    }

    expect($names)->toHaveCount(2)
        ->and(AiProviderConfig::accountIdFromConnectionName($names[0]))->toBe($primary->getKey())
        ->and(AiProviderConfig::accountIdFromConnectionName($names[1]))->toBe($standby->getKey())
        ->and(AiProviderConfig::accountIdFromConnectionName('openai'))->toBeNull()
        ->and(AiProviderConfig::accountIdFromConnectionName(null))->toBeNull();
});

it('drops unknown keys from an account config instead of leaking them to the SDK', function (): void {
    $capability = chainCapability();

    AiProviderAccount::factory()->create([
        'ai_capability_id' => $capability->getKey(),
        'name' => 'noisy',
        'sort_order' => 0,
        'driver' => 'openai_compatible',
        'model' => 'm',
        'config' => ['key' => 'k', 'url' => FIRST_ENDPOINT, 'honey' => 'pot'],
    ]);

    $chain = AiProviderChain::forKey($capability->key);

    // The `models` key is ours, not leaked: openai_compatible needs a
    // transcription default registered or every speech-to-text call dies
    // (Phase 14 Task 3). Only known credentials ride along otherwise.
    expect(array_keys($chain->configs[0]->toConnectionConfig()))->toBe(['driver', 'key', 'url', 'models'])
        ->and($chain->configs[0]->toConnectionConfig()['models'])->toBe(['transcription' => ['default' => 'whisper-1']])
        ->and($chain->configs[0]->toConnectionConfig())->not->toHaveKey('honey');
});
