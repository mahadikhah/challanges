<?php

namespace App\Services\Telegram\Callbacks;

use App\Models\User;
use App\Services\Telegram\BotButtons;
use App\Services\Telegram\BotCallback;
use App\Services\Telegram\HandlesCallback;
use App\Services\Telegram\JoinChallengeFlow;

/**
 * Delivers a join button to the challenge it belongs to.
 *
 * The handler's whole job is handing the token to the flow, because the token is
 * all the button carries: the actor comes from `callback_query.from`, re-resolved
 * against our own rows by `CallbackQueryHandler`, and every decision — the gate,
 * the slot, the participant — is re-made inside `JoinChallengeFlow::confirm()`.
 * A crafted `callback_data` naming somebody else's challenge buys the sender
 * nothing they could not have got from the link, and never acts as anybody else.
 */
class JoinCallback implements HandlesCallback
{
    /**
     * The action word on a join button. Kept two bytes: `callback_data` is
     * budgeted at 64 and the token rides along after the separator.
     */
    public const ACTION = 'jn';

    public function __construct(
        private readonly JoinChallengeFlow $flow,
        private readonly BotButtons $buttons,
    ) {}

    public function handle(User $user, BotCallback $callback): void
    {
        $token = $callback->argument(0);

        if ($token === null) {
            // A payload with no token cannot name a challenge, however it was
            // built, and a retry would fail identically — so it is answered
            // rather than thrown for: the message it rode on is still sitting
            // in somebody's chat with its keyboard attached.
            $this->buttons->stale($user);

            return;
        }

        $this->flow->confirm($user, $token);
    }
}
