<?php

namespace App\Actions\Observability;

use App\Enums\MessagingPlatform;
use App\Enums\SettingKey;
use App\Messaging\PlatformRegistry;
use App\Services\Settings;
use Carbon\CarbonInterval;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one way a critical alert reaches a human (§2.10): a DM into the
 * configured ops chat, through the same `MessengerPlatform` every other
 * outbound message uses — no new channel, no provider HTTP call from here.
 *
 * Fully optional by construction. Three gates before a single byte leaves
 * the app, and every one of them no-ops silently rather than erroring —
 * same discipline as Task 3's external ping:
 *
 * 1. `alerts_enabled` — the kill switch, default off.
 * 2. a resolvable ops platform + a non-zero chat id — half-configured is
 *    unconfigured.
 * 3. the debounce window — `Cache::add` claims the alert's key atomically,
 *    so a burst of the same failure produces one message, not a burst.
 *
 * The message is a closure so an alert that is disabled, unconfigured or
 * debounced never even builds its string.
 *
 * Debounce marks even when the send then fails: a messenger outage must not
 * turn a burst of failures into a burst of retries against a provider that
 * is already refusing. The failure is logged (§2.10) and the next window
 * gets its chance.
 */
class SendCriticalAlert
{
    public function __construct(
        private readonly Settings $settings,
        private readonly PlatformRegistry $platforms,
    ) {}

    /**
     * Try to send one alert.
     *
     * @param  string  $debounceKey  what counts as "the same alert" — an
     *                               exception class, a failed job class, the
     *                               stale-heartbeat check
     * @param  Closure(): string  $message  built only once the gates pass
     * @return bool whether an alert was actually attempted and accepted
     */
    public function send(string $debounceKey, Closure $message): bool
    {
        [$platform, $chatId] = $this->destination();

        if ($platform === null || $chatId === null) {
            return false;
        }

        // Atomic claim: only the first occurrence inside the window gets
        // through. The database cache driver implements `add` as an insert
        // that loses to a duplicate-key race, so concurrent failures still
        // produce one message.
        $claimed = Cache::add(
            $this->cacheKey($debounceKey),
            true,
            $this->cooldown(),
        );

        if (! $claimed) {
            return false;
        }

        try {
            $this->platforms->for($platform)->sendMessage($chatId, $message());

            return true;
        } catch (Throwable $refused) {
            // Every implementation throws MessengerException on refusal or
            // transport failure; the wider catch is for a platform that
            // misbehaves before refusing. Either way the alert itself must
            // never take down the code path that noticed the failure.
            Log::warning('A critical alert could not be delivered to the ops chat.', [
                'debounce_key' => $debounceKey,
                'reason' => $refused::class,
            ]);

            return false;
        }
    }

    /**
     * The cooldown every alert kind shares: at most one message per
     * debounce key per window. A Setting, so an ops chat drowning during an
     * incident can be quieted without a redeploy.
     */
    public function cooldown(): CarbonInterval
    {
        return CarbonInterval::minutes(
            $this->settings->integer(SettingKey::AlertCooldownMinutes),
        );
    }

    /**
     * @return array{0: MessagingPlatform|null, 1: int|null} the configured
     *                                                       destination, or nulls when alerting is off or half-configured
     */
    private function destination(): array
    {
        if (! $this->settings->boolean(SettingKey::AlertsEnabled)) {
            return [null, null];
        }

        $platform = MessagingPlatform::tryFrom(
            $this->settings->string(SettingKey::AlertOpsPlatform),
        );

        $chatId = $this->settings->integer(SettingKey::AlertOpsChatId);

        // Zero is the registry default, not a chat: an unset id means nobody
        // has finished configuring the ops chat, and an unknown platform
        // string means the same.
        if ($platform === null || $chatId <= 0) {
            return [null, null];
        }

        return [$platform, $chatId];
    }

    private function cacheKey(string $debounceKey): string
    {
        return "critical-alert:{$debounceKey}";
    }
}
