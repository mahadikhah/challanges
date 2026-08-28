<?php

namespace App\Services\Telegram;

use App\Models\BotConversation;
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
 *
 * The whole update is handed over rather than its text, because what a message
 * *is* can be the answer itself: a check-in photo has no text to extract, but it
 * is exactly the thing `AwaitingCheckInPhoto` is waiting for.
 */
class ConversationRouter
{
    public function __construct(
        private readonly CreateChallengeWizard $wizard,
        private readonly CheckInFlow $checkIns,
        private readonly LinkChatFlow $chatLinks,
    ) {}

    /**
     * Hand the message to the open flow, reporting whether there was one.
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

        if ($conversation->state->isChatLinkStep()) {
            // The whole message, not its text: what makes a forwarded message
            // an answer here is the *forward* — `forward_from_chat` — which a
            // photo carries as readily as a sentence.
            $message = $update->value('message');

            $this->chatLinks->receiveForward($user, $conversation, is_array($message) ? $message : []);

            return true;
        }

        if ($conversation->state->isCheckInStep()) {
            $this->routeCheckIn($user, $conversation, $update);

            return true;
        }

        // A state nothing routes — reachable only if `ConversationState` grows a
        // case before a flow claims it. Reported as unclaimed so the user gets
        // the fallback reply rather than silence.
        Log::info('A message arrived for a conversation state nothing routes.', [
            'update_id' => $update->update_id,
            'state' => $conversation->state->value,
        ]);

        return false;
    }

    /**
     * A check-in answer: text for the phrase step, a photo for the photo step.
     *
     * The flow itself decides what a non-matching message means — a photo sent to
     * the phrase step is re-asked, not refused — so this only picks the entry
     * point the stored state names and passes the payload through untouched.
     */
    private function routeCheckIn(User $user, BotConversation $conversation, TelegramUpdate $update): void
    {
        if ($conversation->state->expectsPhoto()) {
            $photo = $update->value('message.photo');

            $this->checkIns->receivePhoto(
                $user,
                $conversation,
                is_array($photo) ? $photo : null,
            );

            return;
        }

        $this->checkIns->receiveText($user, $conversation, $this->text($update));
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
