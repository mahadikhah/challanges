<?php

namespace App\Services\Telegram\Callbacks;

use App\Models\Challenge;
use App\Models\User;
use App\Services\Telegram\BotCallback;
use App\Services\Telegram\BotMessenger;
use App\Services\Telegram\HandlesCallback;
use App\Services\Telegram\SessionStepFlow;

/**
 * Delivers a session step's Next button to the session it belongs to.
 *
 * The button carries the challenge's join token and the step order it was sent
 * for — the order so `AdvanceCheckInStep` can tell a replay from progress, the
 * token because that is the one identifier a challenge shares with its
 * participants. Neither is trusted: the actor is re-resolved from
 * `callback_query.from`, the session from our own rows, and the step is
 * accepted only if it is the session's *current* step. A tap on a button from
 * a finished session, or on step 3's button while step 1 is still waiting, is
 * an ordinary answer, not an error.
 */
class SessionStepCallback implements HandlesCallback
{
    /**
     * The action word on a session step button. Two letters like its siblings:
     * `callback_data` is 64 bytes and a join token costs most of a third of
     * that.
     */
    public const ACTION = 'ss';

    public function __construct(
        private readonly SessionStepFlow $flow,
        private readonly BotMessenger $messenger,
    ) {}

    public function handle(User $user, BotCallback $callback): void
    {
        $token = $callback->argument(0);
        $order = $callback->argument(1);

        if ($token === null || $order === null || ! ctype_digit($order)) {
            $this->messenger->send($user, $this->messenger->line($user, 'bot.fallback.stale_button'));

            return;
        }

        $challenge = Challenge::query()->where('join_token', $token)->first();

        if ($challenge === null) {
            $this->messenger->send($user, $this->messenger->line($user, 'bot.fallback.stale_button'));

            return;
        }

        $this->flow->tap($user, $challenge, (int) $order);
    }
}
