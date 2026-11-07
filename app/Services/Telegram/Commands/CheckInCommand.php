<?php

namespace App\Services\Telegram\Commands;

use App\Models\User;
use App\Services\Telegram\BotCommand;
use App\Services\Telegram\CheckInFlow;
use App\Services\Telegram\HandlesBotCommand;

/**
 * `/checkin` — what do I owe right now?
 *
 * A door, like `/create`. The listing, the gate and every rule about what counts
 * live in `CheckInFlow`, so the Mini App can open the same flow without a slash
 * command and the answers cannot drift apart.
 */
class CheckInCommand implements HandlesBotCommand
{
    public function __construct(private readonly CheckInFlow $flow) {}

    public function handle(User $user, BotCommand $command): void
    {
        $this->flow->begin($user);
    }
}
