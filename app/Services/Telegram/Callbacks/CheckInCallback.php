<?php

namespace App\Services\Telegram\Callbacks;

use App\Models\Challenge;
use App\Models\User;
use App\Services\Telegram\BotCallback;
use App\Services\Telegram\BotMessenger;
use App\Services\Telegram\CheckInFlow;
use App\Services\Telegram\HandlesCallback;

/**
 * Delivers a check-in button to the challenge it belongs to.
 *
 * Like `JoinCallback`, the button carries only a join token: the actor comes from
 * `callback_query.from`, and the flow re-makes every decision — the gate, the
 * participant, the open period — inside `SubmitCheckIn` from our own rows. For a
 * `button` challenge this tap *is* the proof; for the other two proof types it
 * merely opens the conversation the proof arrives through.
 */
class CheckInCallback implements HandlesCallback
{
    /**
     * The action word on a check-in button.
     */
    public const ACTION = 'ci';

    public function __construct(
        private readonly CheckInFlow $flow,
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

        $this->flow->start($user, $challenge);
    }
}
