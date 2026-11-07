<?php

namespace App\Services\Telegram\Commands;

use App\Models\User;
use App\Services\Telegram\BotCommand;
use App\Services\Telegram\HandlesBotCommand;
use App\Services\Telegram\Wizards\CreateChallengeWizard;

/**
 * `/create` — opens the create-challenge wizard.
 *
 * Nothing but a door. The gate check, the create-slot check and the ten questions
 * all live in `CreateChallengeWizard`, because the Mini App will open the same flow
 * without a slash command and the rules cannot be split across the two entrances.
 *
 * Re-issuing `/create` mid-flow restarts it rather than resuming. A creator who
 * types it again has lost their place — otherwise they would simply have answered
 * the question in front of them — and `/cancel` exists for leaving deliberately.
 */
class CreateCommand implements HandlesBotCommand
{
    public function __construct(private readonly CreateChallengeWizard $wizard) {}

    public function handle(User $user, BotCommand $command): void
    {
        $this->wizard->begin($user);
    }
}
