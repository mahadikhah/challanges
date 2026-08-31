<?php

use App\Enums\AiCapabilityPurpose;
use App\Enums\SettingKey;
use App\Models\AiCapability;
use App\Models\AiProviderAccount;
use App\Models\Setting;
use App\Models\User;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
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

/*
| The five tabs, as the controller declares them. The dataset drives the
| render test below; the key lists double as the assertion, so adding a
| registry key without assigning it to a tab (or duplicating one across
| tabs) is a build failure here, not a silently invisible setting.
*/
function theSettingsTabs(): array
{
    return [
        'economy' => [
            'invite_coin_reward',
            'create_slot_coin_price',
            'join_slot_coin_price',
            'freeze_coin_price',
            'challenge_completion_coin_reward',
            'stars_packages',
        ],
        'challenges' => [
            'free_create_slots',
            'free_join_slots',
            'default_challenge_freezes',
            'reminder_ending_lead_hours',
            'proof_media_max_seconds',
            'proof_media_max_size_kb',
            'proof_media_retention_days',
        ],
        'access' => [
            'required_channel',
            'required_channel_bale',
            'channel_verification_ttl_minutes',
            'chat_verification_ttl_hours',
            'chat_command_cooldown_seconds',
            'miniapp_token_ttl_minutes',
            'initdata_max_age_seconds',
            'conversation_ttl_minutes',
            'leaderboard_hour',
            'leaderboard_top_size',
        ],
        'ai' => [
            'ai_approval_globally_enabled',
            'ai_approval_allowed_image',
            'ai_approval_allowed_voice',
            'ai_approval_allowed_video',
            'ai_approval_confidence_threshold',
        ],
        'observability' => [
            'telescope_slow_query_ms',
            'telescope_prune_hours',
            'heartbeat_staleness_minutes',
            'alerts_enabled',
            'alert_ops_platform',
            'alert_ops_chat_id',
            'alert_cooldown_minutes',
        ],
    ];
}

it('redirects a guest to the login page instead of showing the panel', function (): void {
    $this->get('/admin/settings/economy')->assertRedirect(route('login'));
});

it('refuses a non-admin on every settings route', function (string $method, string $uri): void {
    $user = User::factory()->create();

    $this->actingAs($user)->{$method}($uri)->assertForbidden();
})->with([
    'tab' => ['get', '/admin/settings/economy'],
    'update' => ['putJson', '/admin/settings/invite_coin_reward'],
    'reset' => ['deleteJson', '/admin/settings/invite_coin_reward'],
]);

it('lands the bare settings URL on the first tab', function (): void {
    $this->actingAs(anAdminPanelUser())
        ->get('/admin/settings')
        ->assertRedirect('/admin/settings/economy');
});

it('answers a tab outside the five with a uniform 404', function (): void {
    $this->actingAs(anAdminPanelUser())->get('/admin/settings/not_a_tab')->assertNotFound();
});

it('shows every registry tunable exactly once across the five tabs', function (): void {
    $registry = array_map(fn (SettingKey $key): string => $key->value, SettingKey::cases());

    expect(array_merge(...array_values(theSettingsTabs())))
        ->toEqualCanonicalizing($registry)
        ->toHaveLength(35);
});

it('shows one tab per route with its exact keys, defaults and labels', function (string $tab, array $keys): void {
    $response = $this->actingAs(anAdminPanelUser())->get("/admin/settings/{$tab}");

    $response->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('Admin/Settings/'.ucfirst($tab))
            ->has('settings', count($keys))
            ->where('settings', fn (Collection $rows): bool => $rows->pluck('key')->all() === $keys)
            // Every row carries a resolved label — a missing lang key would
            // surface as its own "admin.settings.keys.*" string on screen.
            ->where('settings', fn (Collection $rows): bool => $rows
                ->every(fn (array $row): bool => $row['label'] !== "admin.settings.keys.{$row['key']}"))
            ->where('settings.0.value', SettingKey::from($keys[0])->default())
            ->where('settings.0.default', SettingKey::from($keys[0])->default())
            ->where('settings.0.overridden', false),
    );
})->with(fn (): array => array_map(
    fn (string $tab, array $keys): array => [$tab, $keys],
    array_keys(theSettingsTabs()),
    array_values(theSettingsTabs()),
));

it('carries the AI capability readout only on the ai tab', function (): void {
    $this->actingAs(anAdminPanelUser())->get('/admin/settings/ai')->assertInertia(
        fn (AssertableInertia $page) => $page
            // With no accounts at all the readout names the remedy an admin
            // can act on, deterministically (no provider fails before the
            // host's ffmpeg is even asked).
            ->where('aiCapabilities.image.available', false)
            ->where('aiCapabilities.voice.available', false)
            ->where('aiCapabilities.video.available', false)
            ->where('aiCapabilities.video.reason', 'no_provider'),
    );

    $this->actingAs(anAdminPanelUser())->get('/admin/settings/economy')->assertInertia(
        fn (AssertableInertia $page) => $page->missing('aiCapabilities'),
    );
});

it('persists an override that is immediately readable with the cache flushed', function (): void {
    $this->actingAs(anAdminPanelUser())
        ->from('/admin/settings/economy')
        ->put('/admin/settings/invite_coin_reward', ['value' => 25])
        ->assertRedirect('/admin/settings/economy');

    expect(theSettingsService()->integer(SettingKey::InviteCoinReward))->toBe(25)
        ->and(Setting::query()->where('key', 'invite_coin_reward')->exists())->toBeTrue();
});

it('marks a setting as overridden once an admin has changed it', function (): void {
    $this->actingAs(anAdminPanelUser())
        ->from('/admin/settings/economy')
        ->put('/admin/settings/freeze_coin_price', ['value' => 20])
        ->assertRedirect('/admin/settings/economy');

    $this->get('/admin/settings/economy')->assertInertia(
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
        ->from('/admin/settings/ai')
        ->put('/admin/settings/ai_approval_allowed_image', ['value' => true])
        ->assertRedirect('/admin/settings/ai');

    expect(theSettingsService()->boolean(SettingKey::AiApprovalAllowedImage))->toBeTrue();

    $this->get('/admin/settings/ai')->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('settings.1.overridden', true)
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
        ->from('/admin/settings/economy')
        ->delete('/admin/settings/join_slot_coin_price')
        ->assertRedirect('/admin/settings/economy');

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
        ->from('/admin/settings/economy')
        ->put('/admin/settings/stars_packages', ['value' => $packages])
        ->assertRedirect('/admin/settings/economy');

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
        ->from('/admin/settings/access')
        ->put('/admin/settings/required_channel', ['value' => '@announcements'])
        ->assertRedirect('/admin/settings/access');

    expect(theSettingsService()->string(SettingKey::RequiredChannel))->toBe('@announcements');
});
