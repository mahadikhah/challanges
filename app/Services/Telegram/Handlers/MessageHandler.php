<?php

namespace App\Services\Telegram\Handlers;

use App\Actions\Telegram\ResolveTelegramUser;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\Telegram\BotCommand;
use App\Services\Telegram\BotMessenger;
use App\Services\Telegram\CommandRouter;
use App\Services\Telegram\HandlesUpdate;
use Illuminate\Support\Facades\Log;

/**
 * The entry point for anything a user types at the bot.
 *
 * Three jobs, in this order, and the order is the point:
 *
 * 1. **Refuse anything that is not a private chat.** The bot is an admin of the
 *    announcement channel and may be added to groups, so `message` updates arrive
 *    from places that are not a conversation with one identifiable user. Replying
 *    into a group, or worse resolving a "user" from a channel post, is how private
 *    state leaks.
 * 2. **Resolve the user exactly once, here.** Not in each command handler, because
 *    `ClaimInvite` pays an inviter only when `wasRecentlyCreated` is true on the
 *    instance that performed the INSERT — a second lookup downstream would report
 *    false and the inviter would silently go unpaid.
 * 3. **Route the command.** Parsing is `BotCommand`'s job and dispatch is
 *    `CommandRouter`'s; what is left here is deciding what to say when neither
 *    finds anything.
 *
 * Free text is not an error. Once the create-challenge wizard lands it is how a
 * user answers the wizard's questions, and this fallback reply is what that task
 * replaces.
 */
class MessageHandler implements HandlesUpdate
{
    public function __construct(
        private readonly ResolveTelegramUser $resolveUser,
        private readonly CommandRouter $commands,
        private readonly BotMessenger $messenger,
    ) {}

    public function handle(TelegramUpdate $update): void
    {
        if ($update->value('message.chat.type') !== 'private') {
            Log::info('Ignoring a Telegram message from outside a private chat.', [
                'update_id' => $update->update_id,
                'chat_type' => $update->value('message.chat.type'),
            ]);

            return;
        }

        $from = $update->value('message.from');

        if (! is_array($from) || $update->value('message.from.is_bot') === true) {
            Log::info('Ignoring a Telegram message with no human sender.', [
                'update_id' => $update->update_id,
            ]);

            return;
        }

        /** @var array<string, mixed> $from */
        $user = $this->resolveUser->handle($from);

        $command = BotCommand::parse($this->text($update));

        if ($command === null || ! $this->commands->route($user, $command)) {
            $this->offerStart($user);
        }
    }

    /**
     * Point the user at the one command that always works.
     *
     * `/start` is both the entry point and the recovery path: it re-checks channel
     * membership, so a user who left and rejoined fixes themselves with it.
     */
    private function offerStart(User $user): void
    {
        $this->messenger->send($user, $this->messenger->line($user, 'bot.fallback.unknown'));
    }

    /**
     * The message text, if this message has any.
     *
     * A photo or a sticker has none, and that is not a problem — it falls through
     * to the same "I did not follow that" reply as an unknown command.
     */
    private function text(TelegramUpdate $update): ?string
    {
        $text = $update->value('message.text');

        return is_string($text) ? $text : null;
    }
}
