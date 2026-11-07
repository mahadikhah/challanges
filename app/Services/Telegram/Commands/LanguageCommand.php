<?php

namespace App\Services\Telegram\Commands;

use App\Models\User;
use App\Services\Telegram\BotCommand;
use App\Services\Telegram\HandlesBotCommand;
use App\Services\Telegram\LanguagePrompt;

/**
 * `/language` — pick the language the bot speaks to you.
 *
 * The choice it makes is a preference, not a capability: every line the bot
 * sends is resolved per recipient by `BotMessenger` anyway, so the only thing
 * that changes is the `locale` column on the user's row. No gate check, for the
 * same reason `/cancel` has none — a user blocked at the gate still deserves to
 * read the blocking message in a language they understand.
 *
 * The buttons themselves are `LanguagePrompt`'s, shared with `/start`, which
 * asks the same question of anybody who has never answered it.
 */
class LanguageCommand implements HandlesBotCommand
{
    public function __construct(private readonly LanguagePrompt $prompt) {}

    public function handle(User $user, BotCommand $command): void
    {
        // Nothing is owed on this path: the user asked the question themselves,
        // so the tap's only job is to answer it.
        $this->prompt->send($user);
    }
}
