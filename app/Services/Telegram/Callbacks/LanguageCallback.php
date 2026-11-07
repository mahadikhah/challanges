<?php

namespace App\Services\Telegram\Callbacks;

use App\Models\User;
use App\Services\Localization;
use App\Services\Telegram\BotButtons;
use App\Services\Telegram\BotCallback;
use App\Services\Telegram\BotMessenger;
use App\Services\Telegram\HandlesCallback;
use App\Services\Telegram\StartArrival;
use App\Services\Telegram\StartGreeting;

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
 *
 * A button minted by `/start` carries that `/start`'s unfinished business as
 * well (`StartArrival`), and answering it settles both. In that case the
 * greeting *is* the confirmation — it arrives in the chosen language, which
 * proves the pick just as well as a separate line would, and sending both would
 * be two messages inside the one second Telegram allows a chat.
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
        private readonly StartGreeting $greeting,
        private readonly BotButtons $buttons,
    ) {}

    public function handle(User $user, BotCallback $callback): void
    {
        $locale = $callback->argument(0);

        if ($locale === null || ! $this->localization->isSupported($locale)) {
            $this->buttons->stale($user);

            return;
        }

        // Saved before anything is said, so the greeting below resolves in the
        // language they just chose without a second round trip.
        $user->forceFill(['locale' => $locale])->save();

        $owed = StartArrival::carried($user, $callback);

        if ($owed !== null) {
            $this->greeting->deliver($user, $owed);

            return;
        }

        $this->messenger->send($user, $this->messenger->line(
            $user,
            'bot.language.set',
            ['language' => $this->localization->nativeName($locale)],
        ));
    }
}
