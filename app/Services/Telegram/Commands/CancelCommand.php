<?php

namespace App\Services\Telegram\Commands;

use App\Models\User;
use App\Services\Telegram\BotCommand;
use App\Services\Telegram\BotMessenger;
use App\Services\Telegram\HandlesBotCommand;
use App\Services\Telegram\Wizards\CreateChallengeWizard;

/**
 * `/cancel` — the way out of any multi-step flow.
 *
 * Deliberately generic. There is one conversation row per user, so dropping it ends
 * whichever flow was open, and this command will keep working for the check-in and
 * purchase flows without being taught about them.
 *
 * No gate check: refusing to let somebody out of a wizard because they left the
 * announcement channel would trap them in it.
 */
class CancelCommand implements HandlesBotCommand
{
    public function __construct(
        private readonly CreateChallengeWizard $wizard,
        private readonly BotMessenger $messenger,
    ) {}

    public function handle(User $user, BotCommand $command): void
    {
        $this->messenger->send($user, $this->messenger->line(
            $user,
            // Saying "nothing to cancel" matters: a user who thinks a flow is still
            // open and gets a bare acknowledgement will keep waiting for a prompt.
            $this->wizard->abandon($user) ? 'bot.wizard.cancelled' : 'bot.cancel.nothing_open',
        ));
    }
}
