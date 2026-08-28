<?php

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use App\Enums\Contracts\HasTranslatedLabel as HasTranslatedLabelContract;

/**
 * Which messenger a user, chat, or update arrived through.
 *
 * Telegram only for now; Bale (Phase 11 Task 2) is the second case, not a
 * schema change — `users.platform` + `users.platform_user_id` and the
 * per-platform config blocks are shaped so adding a case is additive.
 */
enum MessagingPlatform: string implements HasTranslatedLabelContract
{
    use HasTranslatedLabel;

    case Telegram = 'telegram';

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
}
