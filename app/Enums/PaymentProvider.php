<?php

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use App\Enums\Contracts\HasTranslatedLabel as HasTranslatedLabelContract;

/**
 * Which rail a coin purchase was made on.
 *
 * Phase 4 built Telegram Stars; Phase 11 Task 3 adds Bale Pay. The rail decides
 * everything that is a fact of the rail rather than of the product: the
 * currency tag a pre-checkout query must carry, the `stars_packages` key that
 * prices it, whether the platform documents a refund path. Those facts live
 * here as enum methods for the same reason the messenger facts live on
 * `MessagingPlatform`: a platform branch inside a payment Action is the smell
 * this phase removes, and a `match` on the provider enum is the seam.
 */
enum PaymentProvider: string implements HasTranslatedLabelContract
{
    use HasTranslatedLabel;

    case TelegramStars = 'telegram_stars';

    case BalePay = 'bale_pay';

    /**
     * The rail a user standing on this platform pays through.
     */
    public static function forPlatform(MessagingPlatform $platform): self
    {
        return match ($platform) {
            MessagingPlatform::Telegram => self::TelegramStars,
            MessagingPlatform::Bale => self::BalePay,
        };
    }

    /**
     * The `stars_packages` row key this rail prices from.
     *
     * One package table feeds both rails: a row sells on Telegram through
     * `stars` and on Bale through `rial`. A row without this rail's key is
     * simply not on that rail's shelves — the shop skips it rather than
     * offering a price the rail cannot charge.
     */
    public function priceKey(): string
    {
        return match ($this) {
            self::TelegramStars => 'stars',
            self::BalePay => 'rial',
        };
    }

    /**
     * The currency tag this rail's pre-checkout query must carry.
     *
     * XTR is Stars' tag (CLAUDE.md, verified against core.telegram.org); Bale
     * charges Rial and reports `IRR`.
     */
    public function currency(): string
    {
        return match ($this) {
            self::TelegramStars => 'XTR',
            self::BalePay => 'IRR',
        };
    }

    /**
     * The shop's line for one package on this rail.
     *
     * The two lines price in different units, so they are separate catalogue
     * keys rather than one line with a unit placeholder.
     */
    public function packageLabelKey(): string
    {
        return match ($this) {
            self::TelegramStars => 'bot.shop.package',
            self::BalePay => 'bot.shop.package_rial',
        };
    }

    /**
     * Whether the platform documents a way to return a completed payment.
     *
     * Telegram does (`refundStarPayment`). Bale does not — the verified
     * Bale Pay contract has no refund method — so a Bale purchase cannot be
     * reversed through the API, and `StarPayment::isRefundable()` reports
     * that rather than approximating an endpoint nobody documented.
     */
    public function supportsRefunds(): bool
    {
        return match ($this) {
            self::TelegramStars => true,
            self::BalePay => false,
        };
    }
}
