<?php

namespace App\Services\Telegram\Callbacks;

use App\Models\User;
use App\Services\Telegram\BotCallback;
use App\Services\Telegram\BotMessenger;
use App\Services\Telegram\HandlesCallback;
use App\Services\Telegram\Wizards\CreateChallengeWizard;

/**
 * Delivers a wizard button to the conversation it belongs to.
 *
 * The handler's whole job is finding that conversation, because the button and the
 * state it answers are stored in different places: the step travels in the
 * `callback_data`, but whether the flow is still open is a row in the database.
 * A tap with no live conversation behind it is the ordinary case rather than an
 * error — the wizard finished, or lapsed, and the message it was sent with is
 * still sitting in the chat with its keyboard attached.
 *
 * No gate check here. Recording an answer creates nothing and spends nothing; the
 * one privileged act in the flow is confirmation, and `CreateChallengeWizard`
 * re-verifies membership at that point rather than trusting the check `/create`
 * made several minutes earlier.
 */
class WizardCallback implements HandlesCallback
{
    public function __construct(
        private readonly CreateChallengeWizard $wizard,
        private readonly BotMessenger $messenger,
    ) {}

    public function handle(User $user, BotCallback $callback): void
    {
        $conversation = $user->conversation()->live()->first();

        if ($conversation === null || ! $conversation->state->isCreateChallengeStep()) {
            $this->messenger->send($user, $this->messenger->line($user, 'bot.fallback.stale_button'));

            return;
        }

        $this->wizard->receiveChoice($user, $conversation, $callback);
    }
}
