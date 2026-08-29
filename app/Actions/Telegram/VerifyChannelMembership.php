<?php

namespace App\Actions\Telegram;

use App\Enums\ChatMemberStatus;
use App\Enums\MessagingPlatform;
use App\Enums\SettingKey;
use App\Exceptions\ChannelGateException;
use App\Messaging\Contracts\MessengerException;
use App\Messaging\Contracts\MessengerPlatform;
use App\Messaging\PlatformRegistry;
use App\Models\User;
use App\Services\Settings;

/**
 * The access gate: is this user in the announcement channel?
 *
 * Every user must join before using the bot, and privileged actions — creating,
 * joining, spending — re-verify rather than trusting an old answer. Both readings
 * live here so no surface invents its own version of "close enough".
 *
 * **`handle()` always asks Telegram. `ensure()` asks only when the last answer
 * has gone stale.** The distinction is the whole design:
 *
 * - `/start` uses `handle()`, because the user has very likely *just* joined in
 *   response to the button we sent them; a cached "no" would send them round the
 *   loop again.
 * - Privileged actions use `ensure()`, because at ~30 Bot API calls a second
 *   globally, one `getChatMember` per action is a budget the fan-out cannot
 *   afford — and `channel_verified_at` is a cache with a short, admin-tunable
 *   life, not a permanent grant.
 *
 * **The stamp is cleared on a "no".** A user who leaves the channel must lose
 * access, so the cached yes cannot be allowed to outlive the fact it recorded.
 *
 * Nothing here is catchable into an "allow" path. A Telegram error — chat not
 * found, bot demoted out of the channel — propagates as `TelegramSDKException`,
 * which from the webhook leaves the update unprocessed and retried. A verdict we
 * could not obtain is not a verdict of yes.
 */
class VerifyChannelMembership
{
    public function __construct(
        private readonly PlatformRegistry $platforms,
        private readonly Settings $settings,
    ) {}

    /**
     * Ask Telegram now, record the answer, and return it.
     *
     * @throws ChannelGateException when no channel is configured, or the user has
     *                              no messenger identity to look up
     * @throws MessengerException when the platform cannot be asked, or refuses
     */
    public function handle(User $user): bool
    {
        // Identity first, channel second: a user with no messenger identity is
        // refused before any platform is asked anything, and a web-only admin
        // reading the Telegram channel setting would be a category error.
        $platformUserId = $user->platform_user_id;

        if ($platformUserId === null) {
            throw ChannelGateException::notATelegramUser($user);
        }

        $channel = $this->channel($user);

        // The snapshot keeps the platform's own status token, judged by the
        // enum; absent booleans stay `null` rather than becoming false.
        $member = $this->platformFor($user)->getChatMember($channel, $platformUserId);

        $isMember = ChatMemberStatus::fromTelegram($member->status)
            ->grantsAccess($member->isMember);

        // Not fillable on the model — this is privilege state, and mass
        // assignment must never be able to reach it from a request payload.
        $user->forceFill(['channel_verified_at' => $isMember ? now() : null])->save();

        return $isMember;
    }

    /**
     * Confirm membership, reusing a recent answer rather than asking again.
     *
     * For the privileged paths named in the product rules. A TTL of zero turns
     * the cache off and makes this identical to `handle()`.
     *
     * @throws ChannelGateException
     * @throws MessengerException
     */
    public function ensure(User $user): bool
    {
        $verifiedAt = $user->channel_verified_at;

        if ($verifiedAt !== null && $verifiedAt->gt(now()->subMinutes($this->freshnessMinutes()))) {
            return true;
        }

        return $this->handle($user);
    }

    /**
     * The channel every user has to be in — the one on *their* platform.
     *
     * A Bale user cannot join a Telegram channel, so the gate asks about the
     * channel that exists where the user is standing. Admin-overridable per
     * platform, seeded from env through config, and **never allowed to be
     * empty**: see `ChannelGateException::notConfigured()`.
     *
     * @throws ChannelGateException
     */
    public function channel(User $user): string
    {
        // A user standing on no messenger at all (a web-only admin, reachable
        // here through the announcement path) is read as the default surface,
        // which is what they were before there were two.
        $platform = $user->platform ?? MessagingPlatform::Telegram;
        $channel = trim($this->settings->string($platform->requiredChannelSetting()));

        if ($channel === '') {
            throw ChannelGateException::notConfigured();
        }

        return $channel;
    }

    /**
     * A link a user can tap to join, when the channel has one.
     *
     * Only an `@username` channel is publicly linkable. A numeric `-100…` id is a
     * private chat with no public URL, so there is genuinely no button to offer
     * and the caller has to say so rather than send a broken link.
     *
     * @throws ChannelGateException
     */
    public function joinUrl(User $user): ?string
    {
        $platform = $user->platform ?? MessagingPlatform::Telegram;

        return $platform->channelUrl($this->channel($user));
    }

    /**
     * The platform implementation this user stands on.
     *
     * Callers on the `handle()` path have already refused a user with no
     * messenger identity, so `platform` is never null here.
     */
    private function platformFor(User $user): MessengerPlatform
    {
        return $this->platforms->for($user->platform ?? MessagingPlatform::Telegram);
    }

    /**
     * How long a recorded "yes" stays good for.
     */
    private function freshnessMinutes(): int
    {
        return max(0, $this->settings->integer(SettingKey::ChannelVerificationTtlMinutes));
    }
}
