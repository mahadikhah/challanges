<?php

use App\Models\AiCapability;
use App\Models\AiProviderAccount;
use App\Models\AiUsageRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function anAiPanelAdmin(): User
{
    return User::factory()->admin()->create();
}

function theModerationCapability(): AiCapability
{
    return AiCapability::query()->firstOrCreate(
        ['key' => AiCapability::KEY_PROOF_MODERATION],
        ['label' => 'Proof moderation'],
    );
}

it('redirects a guest away from every AI account route', function (string $method, string $uri): void {
    $this->{$method}($uri)->assertRedirect(route('login'));
})->with([
    'list' => ['get', '/admin/ai-accounts'],
    'create' => ['get', '/admin/ai-accounts/create'],
    'store' => ['post', '/admin/ai-accounts'],
]);

it('refuses a non-admin on every AI account route', function (string $method, string $uri): void {
    $this->actingAs(User::factory()->create())->{$method}($uri)->assertForbidden();
})->with([
    'list' => ['get', '/admin/ai-accounts'],
    'create' => ['get', '/admin/ai-accounts/create'],
    'store' => ['post', '/admin/ai-accounts'],
]);

it('never returns a stored credential to the browser — not in the list, not on the edit page', function (): void {
    $capability = theModerationCapability();

    $account = AiProviderAccount::factory()->configured()->create([
        'ai_capability_id' => $capability->getKey(),
        'config' => ['key' => 'sk-test-plaintext-secret', 'url' => 'https://gateway.example/v1'],
    ]);

    $list = $this->actingAs(anAiPanelAdmin())->get('/admin/ai-accounts');
    $edit = $this->actingAs(anAiPanelAdmin())->get("/admin/ai-accounts/{$account->id}/edit");

    // The rendered payloads AND the raw page HTML: a secret anywhere in the
    // response — props, JSON, HTML — is the failure this test exists to
    // prevent. (Inertia props are embedded in the page HTML.)
    foreach ([$list, $edit] as $response) {
        $response->assertOk();

        expect($response->getContent())->not->toContain('sk-test-plaintext-secret');
    }

    $edit->assertInertia(
        fn ($page) => $page
            ->where('account.config.key', '__set__')
            // Non-secret connection fields pass through: the admin needs to
            // see and fix the URL.
            ->where('account.config.url', 'https://gateway.example/v1'),
    );

    // The database column is ciphertext, not the credential. The query
    // builder is deliberate: an Eloquent `value()` hydrates through the
    // `encrypted:array` cast and would hand back the decrypted config.
    expect(
        DB::table('ai_provider_accounts')->where('id', $account->id)->value('config')
    )->not->toContain('sk-test-plaintext-secret');
});

it('creates an account through the shared save action with an encrypted credential', function (): void {
    $capability = theModerationCapability();

    $this->actingAs(anAiPanelAdmin())->post('/admin/ai-accounts', [
        'name' => 'Primary reviewer',
        'ai_capability_id' => $capability->id,
        'driver' => 'openai_compatible',
        'model' => 'primary-model',
        'is_active' => true,
        'sort_order' => 5,
        'config' => ['key' => 'sk-live-first-secret', 'url' => 'https://gateway.example/v1'],
        'limit_period' => 'monthly',
        'limit_timezone' => 'UTC',
    ])->assertRedirect();

    $account = AiProviderAccount::query()->sole();

    expect($account->name)->toBe('Primary reviewer')
        ->and($account->config)->toBe([
            'key' => 'sk-live-first-secret',
            'url' => 'https://gateway.example/v1',
        ])
        ->and($account->isConfigured())->toBeTrue();

    // encrypted:array — the stored text is not the key (raw column, not the
    // decrypted cast).
    expect(
        DB::table('ai_provider_accounts')->where('id', $account->id)->value('config')
    )->not->toContain('sk-live-first-secret');
});

it('keeps the stored credential when the edit form submits a blank secret', function (): void {
    $account = AiProviderAccount::factory()->configured()->create([
        'ai_capability_id' => theModerationCapability()->getKey(),
        'config' => ['key' => 'sk-kept-secret', 'url' => 'https://old.example/v1'],
    ]);

    // Blank key means "keep it"; the URL is not a secret and is overwritten.
    $this->actingAs(anAiPanelAdmin())
        ->from("/admin/ai-accounts/{$account->id}/edit")
        ->put("/admin/ai-accounts/{$account->id}", [
            'name' => $account->name,
            'ai_capability_id' => $account->ai_capability_id,
            'driver' => 'openai_compatible',
            'model' => 'primary-model',
            'is_active' => true,
            'sort_order' => 0,
            'config' => ['key' => '', 'url' => 'https://new.example/v1'],
            'limit_period' => 'monthly',
            'limit_timezone' => 'UTC',
        ])->assertRedirect();

    $account->refresh();

    expect($account->config['key'])->toBe('sk-kept-secret')
        ->and($account->config['url'])->toBe('https://new.example/v1');
});

it('replaces the stored credential when a new secret is typed', function (): void {
    $account = AiProviderAccount::factory()->configured()->create([
        'ai_capability_id' => theModerationCapability()->getKey(),
        'config' => ['key' => 'sk-old-secret', 'url' => 'https://gateway.example/v1'],
    ]);

    $this->actingAs(anAiPanelAdmin())
        ->put("/admin/ai-accounts/{$account->id}", [
            'name' => $account->name,
            'ai_capability_id' => $account->ai_capability_id,
            'driver' => 'openai_compatible',
            'model' => 'primary-model',
            'is_active' => true,
            'sort_order' => 0,
            'config' => ['key' => 'sk-rotated-secret', 'url' => 'https://gateway.example/v1'],
            'limit_period' => 'monthly',
            'limit_timezone' => 'UTC',
        ])->assertRedirect();

    expect($account->refresh()->config['key'])->toBe('sk-rotated-secret');
});

it('drops the old credential when the driver is switched, even between same-named fields', function (): void {
    $account = AiProviderAccount::factory()->configured()->create([
        'ai_capability_id' => theModerationCapability()->getKey(),
        'driver' => 'openai',
        'config' => ['key' => 'sk-openai-secret', 'url' => 'https://api.openai.com/v1'],
    ]);

    // openai_compatible also names its secret `key`; the field names match,
    // the vendors do not — the stored key must not carry across.
    $this->actingAs(anAiPanelAdmin())
        ->put("/admin/ai-accounts/{$account->id}", [
            'name' => $account->name,
            'ai_capability_id' => $account->ai_capability_id,
            'driver' => 'openai_compatible',
            'model' => 'primary-model',
            'is_active' => true,
            'sort_order' => 0,
            'config' => ['key' => '', 'url' => 'https://gateway.example/v1'],
            'limit_period' => 'monthly',
            'limit_timezone' => 'UTC',
        ])->assertRedirect();

    $config = $account->refresh()->config;

    expect($config)->not->toHaveKey('key')
        ->and($config['url'])->toBe('https://gateway.example/v1');
});

it('rejects an unknown driver before anything is written', function (): void {
    $this->actingAs(anAiPanelAdmin())
        ->postJson('/admin/ai-accounts', [
            'name' => 'Broken',
            'ai_capability_id' => theModerationCapability()->id,
            'driver' => 'not_a_driver',
            'model' => 'primary-model',
            'is_active' => true,
            'limit_period' => 'monthly',
            'limit_timezone' => 'UTC',
        ])->assertInvalid('driver');

    expect(AiProviderAccount::query()->count())->toBe(0);
});

it('rejects a missing required connection field for the chosen driver', function (): void {
    // Ollama has no secret at all; its url is required. The config rules are
    // built from the catalog, the same array that renders the form.
    $this->actingAs(anAiPanelAdmin())
        ->postJson('/admin/ai-accounts', [
            'name' => 'Local',
            'ai_capability_id' => theModerationCapability()->id,
            'driver' => 'ollama',
            'model' => 'llama3',
            'is_active' => true,
            'config' => [],
            'limit_period' => 'daily',
            'limit_timezone' => 'UTC',
        ])->assertInvalid('config.url');
});

it('deletes an account while its usage history survives', function (): void {
    $account = AiProviderAccount::factory()->configured()->create([
        'ai_capability_id' => theModerationCapability()->getKey(),
    ]);

    AiUsageRecord::factory()->create(['ai_provider_account_id' => $account->id]);

    $this->actingAs(anAiPanelAdmin())
        ->delete("/admin/ai-accounts/{$account->id}")
        ->assertRedirect('/admin/ai-accounts');

    expect(AiProviderAccount::query()->count())->toBe(0)
        // No foreign key onto the account: the ledger is append-only, the
        // account list is not.
        ->and($account->usageRecords()->count())->toBe(1);
});
