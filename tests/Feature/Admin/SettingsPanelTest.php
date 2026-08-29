<?php

use App\Enums\AiCapabilityPurpose;
use App\Enums\SettingKey;
use App\Models\AiCapability;
use App\Models\AiProviderAccount;
use App\Models\Setting;
use App\Models\User;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

function anAdminPanelUser(): User
{
    return User::factory()->admin()->create();
}

function theSettingsService(): Settings
{
    return app(Settings::class);
}

it('redirects a guest to the login page instead of showing the panel', function (): void {
    $this->get('/admin/settings')->assertRedirect(route('login'));
});

it('refuses a non-admin on every settings route', function (string $method, string $uri): void {
    $user = User::factory()->create();

    $this->actingAs($user)->{$method}($uri)->assertForbidden();
})->with([
    'list' => ['get', '/admin/settings'],
    'update' => ['putJson', '/admin/settings/invite_coin_reward'],
    'reset' => ['deleteJson', '/admin/settings/invite_coin_reward'],
]);

it('shows every registry tunable, grouped, with its default and override state', function (): void {
    $response = $this->actingAs(anAdminPanelUser())->get('/admin/settings');

    $response->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('Admin/Settings')
            ->has('settings', 26)
            ->where('settings.0.key', 'invite_coin_reward')
            ->where('settings.0.type', 'integer')
            ->where('settings.0.group', 'economy')
            ->where('settings.0.label', 'Coins per credited invite')
            ->where('settings.0.value', SettingKey::InviteCoinReward->default())
            ->where('settings.0.default', SettingKey::InviteCoinReward->default())
            ->where('settings.0.overridden', false)
            // The Stars packages travel as the editable table they are, not a
            // serialised string the page would have to parse.
            ->where('settings.5.key', 'stars_packages')
            ->where('settings.5.type', 'json')
            ->where('settings.5.value', SettingKey::StarsPackages->default())
            ->where('settings.9.key', 'required_channel')
            ->where('settings.9.type', 'text')
            ->where('settings.13.key', 'conversation_ttl_minutes')
            ->where('settings.13.group', 'access')
            ->where('settings.14.key', 'reminder_ending_lead_hours')
            ->where('settings.14.group', 'reminders')
            // The proof-media trio: ceiling, ceiling, retention window.
            ->where('settings.15.key', 'proof_media_max_seconds')
            ->where('settings.15.group', 'proofs')
            ->where('settings.16.key', 'proof_media_max_size_kb')
            ->where('settings.17.key', 'proof_media_retention_days')
            ->where('settings.17.value', SettingKey::ProofMediaRetentionDays->default())
            // The AI approval gates, off by default on every axis, and the
            // decision threshold that finally joins them in the panel.
            ->where('settings.18.key', 'ai_approval_globally_enabled')
            ->where('settings.18.type', 'boolean')
            ->where('settings.18.value', false)
            ->where('settings.19.key', 'ai_approval_allowed_image')
            ->where('settings.20.key', 'ai_approval_allowed_voice')
            ->where('settings.21.key', 'ai_approval_allowed_video')
            ->where('settings.22.key', 'ai_approval_confidence_threshold')
            ->where('settings.22.group', 'ai')
            // Image answers from the account rows now. Voice answers too, as
            // of Phase 14 Task 3: the readout demands a moderation account
            // whose driver can also transcribe. Video answers from the
            // environment itself (Task 4) — with no accounts at all the
            // readout names the remedy an admin can act on, deterministically
            // (no provider fails before the host's ffmpeg is even asked).
            ->where('aiCapabilities.image.available', false)
            ->where('aiCapabilities.voice.available', false)
            ->where('aiCapabilities.video.available', false)
            ->where('aiCapabilities.video.reason', 'no_provider'),
    );
});

it('persists an override that is immediately readable with the cache flushed', function (): void {
    $this->actingAs(anAdminPanelUser())
        ->from('/admin/settings')
        ->put('/admin/settings/invite_coin_reward', ['value' => 25])
        ->assertRedirect('/admin/settings');

    expect(theSettingsService()->integer(SettingKey::InviteCoinReward))->toBe(25)
        ->and(Setting::query()->where('key', 'invite_coin_reward')->exists())->toBeTrue();
});

it('marks a setting as overridden once an admin has changed it', function (): void {
    $this->actingAs(anAdminPanelUser())
        ->from('/admin/settings')
        ->put('/admin/settings/freeze_coin_price', ['value' => 20])
        ->assertRedirect('/admin/settings');

    $this->get('/admin/settings')->assertInertia(
        fn (AssertableInertia $page) => $page->where('settings.3.overridden', true),
    );
});

it('updates an AI approval gate toggle and reports the image capability honestly', function (): void {
    // Self-healing rather than firstOrFail: the Domain concurrency suites
    // truncate every table without re-seeding, so the migration-seeded row
    // may be gone depending on run order. The migration inserts with
    // firstOrCreate — this is the same idempotent shape.
    $capability = AiCapability::query()->firstOrCreate(
        ['key' => AiCapability::KEY_PROOF_MODERATION],
        ['label' => 'Proof moderation', 'purpose' => AiCapabilityPurpose::Vision],
    );
    $capability->update(['is_active' => true]);

    AiProviderAccount::factory()->configured()->create([
        'ai_capability_id' => $capability->getKey(),
    ]);

    // Form-encoded booleans arrive as "1" — the controller converts, the
    // storage layer stays strict.
    $this->actingAs(anAdminPanelUser())
        ->from('/admin/settings')
        ->put('/admin/settings/ai_approval_allowed_image', ['value' => true])
        ->assertRedirect('/admin/settings');

    expect(theSettingsService()->boolean(SettingKey::AiApprovalAllowedImage))->toBeTrue();

    $this->get('/admin/settings')->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('settings.19.overridden', true)
            ->where('aiCapabilities.image.available', true)
            // The factory's openai_compatible driver can transcribe, so the
            // same account carries voice review too.
            ->where('aiCapabilities.voice.available', true),
    );
});

it('rejects a value the registry type does not accept', function (): void {
    $this->actingAs(anAdminPanelUser())
        ->putJson('/admin/settings/invite_coin_reward', ['value' => 'free money'])
        ->assertStatus(422);

    expect(Setting::query()->where('key', 'invite_coin_reward')->exists())->toBeFalse();
});

it('answers a key outside the registry with a uniform 404', function (): void {
    $this->actingAs(anAdminPanelUser())
        ->putJson('/admin/settings/not_a_setting', ['value' => 1])
        ->assertNotFound();
});

it('reverts an override to the registry default on reset', function (): void {
    theSettingsService()->set(SettingKey::JoinSlotCoinPrice, 40);

    $this->actingAs(anAdminPanelUser())
        ->from('/admin/settings')
        ->delete('/admin/settings/join_slot_coin_price')
        ->assertRedirect('/admin/settings');

    expect(Setting::query()->where('key', 'join_slot_coin_price')->exists())->toBeFalse()
        ->and(theSettingsService()->integer(SettingKey::JoinSlotCoinPrice))
        ->toBe(SettingKey::JoinSlotCoinPrice->default());
});

it('accepts a rewritten Stars package table', function (): void {
    $packages = [
        ['stars' => 75, 'coins' => 80],
        ['stars' => 200, 'coins' => 230],
    ];

    $this->actingAs(anAdminPanelUser())
        ->from('/admin/settings')
        ->put('/admin/settings/stars_packages', ['value' => $packages])
        ->assertRedirect('/admin/settings');

    // Row-internal key order is whatever the round-trip through the request
    // and the JSON column produced; the packages themselves must survive.
    $stored = collect(theSettingsService()->array(SettingKey::StarsPackages))
        ->map(fn (array $package): array => [
            'stars' => $package['stars'],
            'coins' => $package['coins'],
        ])
        ->all();

    expect($stored)->toBe($packages);
});

it('rejects a malformed Stars package table with the catalogue message', function (): void {
    $response = $this->actingAs(anAdminPanelUser())
        ->putJson('/admin/settings/stars_packages', ['value' => ['not-a-package']])
        ->assertStatus(422);

    expect(collect($response->json('errors'))->flatten())
        ->toContain(__('admin.settings.invalid_value'));
});

it('updates the required channel as plain text', function (): void {
    $this->actingAs(anAdminPanelUser())
        ->from('/admin/settings')
        ->put('/admin/settings/required_channel', ['value' => '@announcements'])
        ->assertRedirect('/admin/settings');

    expect(theSettingsService()->string(SettingKey::RequiredChannel))->toBe('@announcements');
});
