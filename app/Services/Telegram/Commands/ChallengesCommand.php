<?php

namespace App\Services\Telegram\Commands;

use App\Models\User;
use App\Services\Telegram\BotCommand;
use App\Services\Telegram\HandlesBotCommand;
use App\Services\Telegram\MyChallengesFlow;

/**
 * `/challenges` — what am I in, and what have I made?
 *
 * A door, like `/create` and `/checkin`. The merge, the ordering and the cap live
 * in `MyChallengesFlow`, so the welcome's "My challenges" button reaches the same
 * listing without a second copy of any of it.
 */
class ChallengesCommand implements HandlesBotCommand
{
    public function __construct(private readonly MyChallengesFlow $flow) {}

    public function handle(User $user, BotCommand $command): void
    {
        $this->flow->begin($user);
    }
}
