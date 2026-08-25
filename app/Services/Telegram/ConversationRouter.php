<?php

namespace App\Services\Telegram;

use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\Telegram\Wizards\CreateChallengeWizard;
use Illuminate\Support\Facades\Log;

/**
 * Delivers a typed message to whichever multi-step flow the user has open.
 *
 * The fourth router, and the only one with nothing in the payload to key on. An
 * update announces its kind, a command names itself and a button carries an action
 * word; free text carries nothing at all, so the discriminator is the state stored
 * against the user. That is the whole reason `BotConversation` exists.
 *
 * **A user has at most one open flow** — `bot_conversations.user_id` is unique — so
 * there is never a question of which flow a message belongs to, only whether there
 * is one. That is a deliberate constraint rather than an oversight: two half-built
 * challenges and a pending check-in sharing one chat would be untypeable.
 *
 * Ordering matters at the call site, not here. `MessageHandler` routes registered
 * commands **before** asking this router, so `/cancel` and `/start` always work
 * mid-flow instead of being swallowed as an answer to "what is your title?".
 */
class ConversationRouter
{
    public function __construct(private readonly CreateChallengeWizard $wizard) {}

    /**
     * Hand the message to the open flow, reporting whether there was one.
     *
     * Takes the whole update rather than the text so that the check-in flow's photo
     * step can read `message.photo` without changing this signature.
     */
    public function route(User $user, TelegramUpdate $update): bool
    {
        $conversation = $user->conversation()->live()->first();

        if ($conversation === null) {
            return false;
        }

        if ($conversation->state->isCreateChallengeStep()) {
            $this->wizard->receiveText($user, $conversation, $this->text($update));

            return true;
        }

        // A check-in conversation, which nothing handles yet. Reported as unclaimed
        // so the user gets the fallback reply rather than silence.
        Log::info('A message arrived for a conversation state nothing routes.', [
            'update_id' => $update->update_id,
            'state' => $conversation->state->value,
        ]);

        return false;
    }

    /**
     * The message text, if this message has any.
     */
    private function text(TelegramUpdate $update): ?string
    {
        $text = $update->value('message.text');

        return is_string($text) ? $text : null;
    }
}
