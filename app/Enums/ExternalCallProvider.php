<?php

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use App\Enums\Contracts\HasTranslatedLabel as HasTranslatedLabelContract;

/**
 * Which outside system a call went out to.
 *
 * The System Health page's per-provider counters (§3.9): one row per provider
 * per day per outcome, incremented from the actual call sites. Deliberately a
 * separate dimension from `MessagingPlatform`/`PaymentProvider` — a provider
 * here is "a system we depend on whose failure rate an admin should see",
 * which is why the payment rails and the AI providers appear beside the
 * messengers rather than being derived from another enum's cases.
 */
enum ExternalCallProvider: string implements HasTranslatedLabelContract
{
    use HasTranslatedLabel;

    case Telegram = 'telegram';

    case Bale = 'bale';

    case TelegramStars = 'telegram_stars';

    case BalePay = 'bale_pay';

    case AiProvider = 'ai_provider';

    /**
     * The counter a platform's messaging calls increment.
     */
    public static function forMessaging(MessagingPlatform $platform): self
    {
        return match ($platform) {
            MessagingPlatform::Telegram => self::Telegram,
            MessagingPlatform::Bale => self::Bale,
        };
    }

    /**
     * The counter a rail's payment calls increment.
     */
    public static function forPayment(PaymentProvider $provider): self
    {
        return match ($provider) {
            PaymentProvider::TelegramStars => self::TelegramStars,
            PaymentProvider::BalePay => self::BalePay,
        };
    }
}
