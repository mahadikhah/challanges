<?php

namespace App\Services\Telegram\Callbacks;

use App\Models\User;
use App\Services\Localization;
use App\Services\Telegram\BotCallback;
use App\Services\Telegram\BotMessenger;
use App\Services\Telegram\HandlesCallback;

/**
 * Delivers a language-button tap to the user's own preference.
 *
 * The locale on the button is a code from our own allowlist or it is nothing —
 * `isSupported()` decides, not the payload — so a crafted `callback_data` cannot
 * set a locale the platform cannot serve (which would make every later line
 * resolve to the fallback while the user believes they chose something).
 *
 * The confirmation is deliberately sent *after* the change and read through the
 * same per-recipient resolution as everything else: it is the first message the
 * user gets in the language they just picked, which doubles as proof the pick
 * took.
 */
class LanguageCallback implements HandlesCallback
{
    /**
     * The action word on a language button.
     */
    public const ACTION = 'lg';

    public function __construct(
        private readonly BotMessenger $messenger,
        private readonly Localization $localization,
    ) {}

    public function handle(User $user, BotCallback $callback): void
    {
        $locale = $callback->argument(0);

        if ($locale === null || ! $this->localization->isSupported($locale)) {
            $this->messenger->send($user, $this->messenger->line($user, 'bot.fallback.stale_button'));

            return;
        }

        $user->forceFill(['locale' => $locale])->save();

        $this->messenger->send($user, $this->messenger->line(
            $user,
            'bot.language.set',
            ['language' => $this->localization->nativeName($locale)],
        ));
    }
}
