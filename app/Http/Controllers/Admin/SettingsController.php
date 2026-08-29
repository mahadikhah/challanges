<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SettingKey;
use App\Enums\SettingType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSettingRequest;
use App\Models\AiCapability;
use App\Models\AiProviderAccount;
use App\Services\Ai\AiDriverCatalog;
use App\Services\Ai\VideoReviewCapability;
use App\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
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
        'ai' => [
            SettingKey::AiApprovalGloballyEnabled,
            SettingKey::AiApprovalAllowedImage,
            SettingKey::AiApprovalAllowedVoice,
            SettingKey::AiApprovalAllowedVideo,
            SettingKey::AiApprovalConfidenceThreshold,
        ],
    ];

    public function __construct(private readonly Settings $settings) {}

    public function index(VideoReviewCapability $videoReview): Response
    {
        return Inertia::render('Admin/Settings', [
            'settings' => $this->settingRows(),
            'aiCapabilities' => $this->aiCapabilities($videoReview),
        ]);
    }

    public function update(UpdateSettingRequest $request, string $setting, VideoReviewCapability $videoReview): RedirectResponse
    {
        $key = SettingKey::from($setting);

        $value = $request->validated('value');

        // The video allow-toggle is the one setting whose promise the
        // environment can veto: without a video-capable provider or ffmpeg,
        // an enabled toggle would silently route every video to the manual
        // queue — a switch that looks on and does nothing, which is worse
        // than no switch (Phase 14 Task 4). Refusing here keeps the panel
        // and the runtime saying the same thing; the runtime re-checks
        // anyway for hosts that lose ffmpeg later.
        if ($key === SettingKey::AiApprovalAllowedVideo && $value && ! $videoReview->available()) {
            throw ValidationException::withMessages([
                'value' => __('admin.settings.video_review_unavailable'),
            ]);
        }

        // Booleans are the one shape the transport mangles: a form-encoded
        // checkbox arrives as "1"/"0", which `Settings::set()` correctly
        // refuses to coerce for itself. Converting here is not that coercion
        // gone astray — the request already validated it *is* a boolean.
        if ($key->type() === SettingType::Boolean) {
            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        $this->settings->set($key, $value);

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
            SettingType::Integer => $this->settings->integer($key),
            SettingType::Boolean => $this->settings->boolean($key),
            SettingType::Json => $this->settings->array($key),
        };
    }

    /**
     * What each media type's AI review actually rests on, read-only, so an
     * admin flipping an allow-toggle can see whether anything would answer.
     *
     * Image answers today: a capability row with at least one active,
     * configured account is what Phase 10's moderation calls run on. Voice
     * answers from the same rows plus a transcription-capable driver (Task 3).
     * Video answers from `VideoReviewCapability` (Task 4): the environment
     * itself — a video-accepting provider, or ffmpeg on this host — has to
     * be able to get the recording to a model. A transient cooldown is
     * deliberately ignored: this is the deployment's capability, not its
     * health right now.
     *
     * @return array<string, array{available: bool|null, reason?: string}>
     */
    private function aiCapabilities(VideoReviewCapability $videoReview): array
    {
        $accounts = AiProviderAccount::query()
            ->where('ai_provider_accounts.is_active', true)
            ->whereHas('capability', fn ($capability) => $capability
                ->where('key', AiCapability::KEY_PROOF_MODERATION)
                ->where('is_active', true))
            ->get()
            ->filter(fn (AiProviderAccount $account) => $account->isConfigured());

        $video = ['available' => $videoReview->available()];

        if (! $video['available']) {
            $video['reason'] = (string) $videoReview->unavailableReason();
        }

        return [
            'image' => ['available' => $accounts->isNotEmpty()],
            // A voice review needs the moderation account to also transcribe:
            // no catalog driver accepts audio as a prompt attachment, so the
            // recording is transcribed first (Phase 14 Task 3) — an account
            // whose driver cannot transcribe cannot review a voice at all.
            'voice' => [
                'available' => $accounts->contains(
                    fn (AiProviderAccount $account) => AiDriverCatalog::supportsTranscription((string) $account->driver),
                ),
            ],
            'video' => $video,
        ];
    }
}
