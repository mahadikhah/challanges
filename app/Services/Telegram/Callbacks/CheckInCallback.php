<?php

namespace App\Services\Telegram\Callbacks;

use App\Enums\FlowType;
use App\Models\Challenge;
use App\Models\User;
use App\Services\Telegram\BotCallback;
use App\Services\Telegram\BotMessenger;
use App\Services\Telegram\CheckInFlow;
use App\Services\Telegram\HandlesCallback;
use App\Services\Telegram\SessionStepFlow;

/**
 * Delivers a check-in button to the flow its challenge runs on.
 *
 * The button is the same one for every challenge — the participant should not
 * have to know or care how a challenge checks in — so the dispatch on flow type
 * happens here, behind the one action word `CheckInCallback` already owns.
 *
 * Like `JoinCallback`, the button carries only a join token: the actor comes
 * from `callback_query.from`, and the receiving flow re-makes every decision
 * inside the Task 2 actions from our own rows. The step order a session button
 * carries is checked against the session's actual current step, never trusted.
 */
class CheckInCallback implements HandlesCallback
{
    /**
     * The action word on a check-in button.
     */
    public const ACTION = 'ci';

    public function __construct(
        private readonly CheckInFlow $simple,
        private readonly SessionStepFlow $sessions,
        private readonly BotMessenger $messenger,
    ) {}

    public function handle(User $user, BotCallback $callback): void
    {
        $token = $callback->argument(0);

        if ($token === null) {
            $this->messenger->send($user, $this->messenger->line($user, 'bot.fallback.stale_button'));

            return;
        }

        $challenge = Challenge::query()->where('join_token', $token)->first();

        if ($challenge === null) {
            // Answered rather than thrown for: the listing that carried the button
            // may be hours old, and a deleted challenge is not a retryable error.
            $this->messenger->send($user, $this->messenger->line($user, 'bot.checkin.not_found'));

            return;
        }

        if ($challenge->flow_type === FlowType::TimedSession) {
            $this->sessions->begin($user, $challenge);

            return;
        }

        $this->simple->start($user, $challenge);
    }
}
