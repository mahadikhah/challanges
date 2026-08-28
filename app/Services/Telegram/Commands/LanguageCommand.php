<?php

namespace App\Services\Telegram\Commands;

use App\Models\User;
use App\Services\Localization;
use App\Services\Telegram\BotCallback;
use App\Services\Telegram\BotCommand;
use App\Services\Telegram\BotMessenger;
use App\Services\Telegram\Callbacks\LanguageCallback;
use App\Services\Telegram\HandlesBotCommand;

/**
 * `/language` — pick the language the bot speaks to you.
 *
 * The choice it makes is a preference, not a capability: every line the bot
 * sends is resolved per recipient by `BotMessenger` anyway, so the only thing
 * that changes is the `locale` column on the user's row. No gate check, for the
 * same reason `/cancel` has none — a user blocked at the gate still deserves to
 * read the blocking message in a language they understand.
 */
class LanguageCommand implements HandlesBotCommand
{
    public function __construct(
        private readonly BotMessenger $messenger,
        private readonly Localization $localization,
    ) {}

    public function handle(User $user, BotCommand $command): void
    {
        $buttons = [];

        foreach ($this->localization->options() as $option) {
            // The label is the language's own name for itself — anything else
            // asks a Farsi speaker to find the word "Farsi" in English first.
            $buttons[] = [
                'text' => $option['native'],
                'callback_data' => BotCallback::encode(LanguageCallback::ACTION, $option['code']),
            ];
        }

        $this->messenger->paragraphs(
            $user,
            [$this->messenger->line($user, 'bot.language.prompt')],
            [$buttons],
        );
    }
}
