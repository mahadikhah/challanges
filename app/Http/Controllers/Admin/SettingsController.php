<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SettingKey;
use App\Enums\SettingType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSettingRequest;
use App\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The one place an admin tunes the platform's rates and prices.
 *
 * The form is generated from the `SettingKey` registry — every tunable the
 * platform has is a case there, so the panel can never drift from the
 * codebase's own list. Writes go through `Settings::set()`/`forget()` (never
 * the model directly), which type-checks and flushes the cache; the enum's
 * declared type is what the Form Request validates against, so a
 * wrong-shaped value is rejected twice — once by validation, once by the
 * service that guards the storage layer.
 */
class SettingsController extends Controller
{
    /**
     * Which registry keys belong to which panel section. Presentation only —
     * the registry itself is deliberately order-free.
     *
     * @var array<string, list<SettingKey>>
     */
    private const GROUPS = [
        'economy' => [
            SettingKey::InviteCoinReward,
            SettingKey::CreateSlotCoinPrice,
            SettingKey::JoinSlotCoinPrice,
            SettingKey::FreezeCoinPrice,
            SettingKey::ChallengeCompletionCoinReward,
            SettingKey::StarsPackages,
        ],
        'baseline' => [
            SettingKey::FreeCreateSlots,
            SettingKey::FreeJoinSlots,
            SettingKey::DefaultChallengeFreezes,
        ],
        'access' => [
            SettingKey::RequiredChannel,
            SettingKey::ChannelVerificationTtlMinutes,
            SettingKey::MiniAppTokenTtlMinutes,
            SettingKey::InitDataMaxAgeSeconds,
            SettingKey::ConversationTtlMinutes,
        ],
        'reminders' => [
            SettingKey::ReminderEndingLeadHours,
        ],
        'proofs' => [
            SettingKey::ProofMediaMaxSeconds,
            SettingKey::ProofMediaMaxSizeKb,
            SettingKey::ProofMediaRetentionDays,
        ],
    ];

    public function __construct(private readonly Settings $settings) {}

    public function index(): Response
    {
        return Inertia::render('Admin/Settings', [
            'settings' => $this->settingRows(),
        ]);
    }

    public function update(UpdateSettingRequest $request, string $setting): RedirectResponse
    {
        $key = SettingKey::from($setting);

        $this->settings->set($key, $request->validated('value'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.settings.saved')]);

        return back();
    }

    /**
     * Drop the override and let the registry default apply again.
     */
    public function destroy(string $setting): RedirectResponse
    {
        $key = SettingKey::tryFrom($setting);

        abort_if($key === null, 404);

        $this->settings->forget($key);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.settings.reset_done')]);

        return back();
    }

    /**
     * Every tunable as the panel states it: what it is, what applies now,
     * what would apply without an override, and whether one exists.
     *
     * @return list<array<string, mixed>>
     */
    private function settingRows(): array
    {
        $rows = [];

        foreach (self::GROUPS as $group => $keys) {
            foreach ($keys as $key) {
                $rows[] = [
                    'key' => $key->value,
                    'type' => $key->type()->value,
                    'group' => $group,
                    'label' => __("admin.settings.keys.{$key->value}"),
                    'value' => $this->current($key),
                    'default' => $key->default(),
                    'overridden' => $this->settings->isOverridden($key),
                ];
            }
        }

        return $rows;
    }

    /**
     * The effective value, read the way its consumers read it.
     */
    private function current(SettingKey $key): mixed
    {
        return match ($key->type()) {
            SettingType::Text => $this->settings->string($key),
            SettingType::Integer,
            SettingType::Boolean => $this->settings->integer($key),
            SettingType::Json => $this->settings->array($key),
        };
    }
}
