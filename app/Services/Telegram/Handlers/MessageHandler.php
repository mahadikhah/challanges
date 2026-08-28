<?php

namespace App\Services\Telegram\Handlers;

use App\Actions\Payments\CompleteStarsPayment;
use App\Actions\Telegram\ResolveTelegramUser;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\CoinLedger;
use App\Services\Telegram\BotCommand;
use App\Services\Telegram\BotMessenger;
use App\Services\Telegram\CommandRouter;
use App\Services\Telegram\ConversationRouter;
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
 * 3. **Route the command, then the open flow.** Parsing is `BotCommand`'s job and
 *    dispatch is `CommandRouter`'s; what is left here is deciding what to say when
 *    neither finds anything.
 *
 * Free text is not an error: it is how a user answers a wizard's question, which is
 * what `ConversationRouter` is asked about. **Commands are routed first.** A user
 * halfway through the create-challenge wizard who types `/cancel` means the command,
 * not a challenge titled "/cancel", and the same goes for the `/start` that recovers
 * a user who left the channel. The cost is that a title cannot begin with a slash,
 * which is a fair trade for never being trapped inside a flow.
 */
class MessageHandler implements HandlesUpdate
{
    public function __construct(
        private readonly ResolveTelegramUser $resolveUser,
        private readonly CommandRouter $commands,
        private readonly ConversationRouter $conversations,
        private readonly CompleteStarsPayment $completePayment,
        private readonly CoinLedger $ledger,
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

        // A payment confirmation before anything else: it arrives as a message
        // with no text, so without this it would fall through to "I did not
        // follow that" — a poor reply to money having just moved.
        if ($this->settlePayment($user, $update)) {
            return;
        }

        $command = BotCommand::parse($this->text($update));

        if ($command !== null && $this->commands->route($user, $command)) {
            return;
        }

        if ($this->conversations->route($user, $update)) {
            return;
        }

        $this->offerStart($user);
    }

    /**
     * Credit a completed Stars purchase, if this message carries one.
     *
     * Returns whether it did, so the caller knows the message was answered. The
     * reply rides after the credit inside the same job: if sending it fails,
     * the update stays unprocessed and is retried, where `CompleteStarsPayment`
     * finds the row already paid and credits nothing twice — the reply is the
     * only thing the retry can still do.
     */
    private function settlePayment(User $user, TelegramUpdate $update): bool
    {
        $successfulPayment = $update->value('message.successful_payment');

        if (! is_array($successfulPayment)) {
            return false;
        }

        $payment = $this->completePayment->handle($user, $successfulPayment);

        if ($payment === null || ! $payment->isPaid()) {
            // Money arrived that matches no invoice we issued. The row is
            // logged inside the action; what is left is to tell the payer, and
            // to make sure the failure is visible to somebody watching.
            Log::error('A successful_payment could not be credited to any invoice.', [
                'user_id' => $user->getKey(),
                'update_id' => $update->update_id,
            ]);

            $this->messenger->send($user, $this->messenger->line($user, 'bot.shop.not_credited'));

            return true;
        }

        $this->messenger->send($user, $this->messenger->line($user, 'bot.shop.credited', [
            'coins' => $payment->coin_amount,
            'balance' => $this->ledger->balanceFor($user),
        ]));

        return true;
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
