<?php

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use App\Enums\Contracts\HasTranslatedLabel as HasTranslatedLabelContract;
use Illuminate\Support\Facades\Config;

/**
 * Which messenger a user, chat, or update arrived through.
 *
 * The case set is the platform vocabulary, so the facts that are pure
 * functions of *which* messenger — where its public channel URLs live, what a
 * `?start=` deep link looks like, whether a native payment rail is wired —
 * belong here rather than inside the Actions that use them. A platform branch
 * in an Action is the smell Phase 11 exists to remove; a `match` on the
 * platform enum is the seam.
 *
 * Credentials and per-platform config live in the `services.<platform>.*`
 * block each case names via `configKey()`.
 */
enum MessagingPlatform: string implements HasTranslatedLabelContract
{
    use HasTranslatedLabel;

    case Telegram = 'telegram';
    case Bale = 'bale';

    /**
     * The `services.*` config block this platform's credentials live in.
     */
    public function configKey(): string
    {
        return "services.{$this->value}";
    }

    /**
     * The label catalogue key, exposed the same way every other enum exposes
     * it: the bot resolves labels in the recipient's locale, not the ambient
     * one, and needs the key to do that.
     */
    public function labelKey(): string
    {
        return "platform.{$this->value}";
    }

    /**
     * The setting that names this platform's must-join announcement channel.
     *
     * One channel per platform, not one for everybody: a Bale user cannot join
     * a Telegram channel and vice versa, so the gate asks about the channel
     * that exists on the platform the user is actually standing on.
     */
    public function requiredChannelSetting(): SettingKey
    {
        return match ($this) {
            self::Telegram => SettingKey::RequiredChannel,
            self::Bale => SettingKey::RequiredChannelBale,
        };
    }

    /**
     * The public URL of an `@username` channel, or null when none can exist.
     *
     * Only a `@username` channel is publicly linkable on either platform; a
     * numeric `-100…` id is a private chat with no URL to send anybody to.
     */
    public function channelUrl(string $channel): ?string
    {
        if (! str_starts_with($channel, '@')) {
            return null;
        }

        $base = match ($this) {
            self::Telegram => 'https://t.me/',
            self::Bale => 'https://ble.ir/',
        };

        return $base.substr($channel, 1);
    }

    /**
     * The deep link that opens the bot carrying a `?start=` payload.
     *
     * `?start=` rather than `?startapp=`: joining and invite attribution are
     * bot conversations, which can refuse and re-check the channel gate before
     * a Mini App has been opened at all. The bot username is read from this
     * platform's own config block, because the two bots are not each other.
     */
    public function startLink(string $startParam): string
    {
        $username = ltrim(Config::string($this->configKey().'.bot_username'), '@');

        $base = match ($this) {
            self::Telegram => 'https://t.me/',
            self::Bale => 'https://ble.ir/',
        };

        return "{$base}{$username}?start={$startParam}";
    }

    /**
     * Whether this platform's native payment rail is wired into the platform.
     *
     * Both are: Telegram Stars since Phase 4, Bale Pay since Phase 11 Task 3.
     * The shop reads this before offering packages, so a user on a platform
     * without a rail hears "not available yet" rather than tapping a button
     * that can only fail. (A rail being *wired* is not the same as *priced*:
     * Bale shelves exist only while the package table carries Rial prices and
     * `BALE_PROVIDER_TOKEN` is set — both checked when the shelves are built.)
     */
    public function supportsNativePayments(): bool
    {
        return match ($this) {
            self::Telegram, self::Bale => true,
        };
    }
}
