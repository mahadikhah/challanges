<?php

namespace App\Services\Telegram;

use App\Models\User;
use App\Services\Localization;
use App\Services\Telegram\Callbacks\LanguageCallback;

/**
 * "Which language should I speak?" — the buttons, built in one place.
 *
 * Two callers ask the question: `/language`, and `/start` for a user who has
 * never answered it. They must ask it identically — same source, same order,
 * same labels — so the loop lives here rather than in either of them.
 *
 * The label is each language's own name for itself, because anything else asks
 * a Farsi speaker to find the word "Farsi" in English first.
 */
class LanguagePrompt
{
    public function __construct(
        private readonly BotMessenger $messenger,
        private readonly Localization $localization,
    ) {}

    /**
     * Ask, with every button carrying `$carry`.
     *
     * The prompt itself resolves in whatever locale the recipient currently
     * reads — the fallback one, for somebody who has never chosen. There is
     * nothing to guess there: the question is the one message that cannot be
     * sent in its own answer.
     *
     * @param  list<string>  $carry  extra callback segments, so a tap can answer
     *                               for the message that asked as well as with it
     */
    public function send(User $user, array $carry = []): void
    {
        $buttons = [];

        foreach ($this->localization->options() as $option) {
            $buttons[] = [
                'text' => $option['native'],
                'callback_data' => BotCallback::encode(LanguageCallback::ACTION, $option['code'], ...$carry),
            ];
        }

        $this->messenger->paragraphs(
            $user,
            [$this->messenger->line($user, 'bot.language.prompt')],
            [$buttons],
        );
    }
}
