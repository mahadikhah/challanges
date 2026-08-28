<?php

namespace App\Services\Telegram\Handlers;

use App\Actions\Telegram\ResolveTelegramUser;
use App\Messaging\Contracts\MessengerPlatform;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\Telegram\BotCallback;
use App\Services\Telegram\BotMessenger;
use App\Services\Telegram\CallbackRouter;
use App\Services\Telegram\HandlesUpdate;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The entry point for anything a user taps.
 *
 * **The query is acknowledged before the work, not after.** The platform shows
 * a loading state on the tapped button until the answer arrives, and a
 * `callback_query_id` expires; if the work throws, the update is retried, and
 * by then the id is stale and the acknowledgement would fail. Answering first
 * means a failing handler leaves a spinner that stops rather than one that
 * never does — and the acknowledgement itself is deliberately best-effort,
 * because it is cosmetic and must never be the reason an update is retried.
 * Nothing user-facing rides on it: a handler's reply is an ordinary message.
 *
 * **Chat type is not checked, unlike in `MessageHandler`.** A tap can arrive
 * from a button on a channel post — which is exactly how a public challenge
 * will be joined — and there is nothing to leak by allowing it, because the
 * actor is resolved from `callback_query.from` and every reply goes to that
 * user's own private chat rather than to the chat the button was in.
 */
class CallbackQueryHandler implements HandlesUpdate
{
    public function __construct(
        private readonly ResolveTelegramUser $resolveUser,
        private readonly CallbackRouter $callbacks,
        private readonly BotMessenger $messenger,
        private readonly MessengerPlatform $platform,
    ) {}

    public function handle(TelegramUpdate $update): void
    {
        $from = $update->value('callback_query.from');

        if (! is_array($from) || $update->value('callback_query.from.is_bot') === true) {
            Log::info('Ignoring a Telegram callback query with no human sender.', [
                'update_id' => $update->update_id,
            ]);

            return;
        }

        $this->acknowledge($update);

        /** @var array<string, mixed> $from */
        $user = $this->resolveUser->handle($from);

        $data = $update->value('callback_query.data');
        $callback = BotCallback::parse(is_string($data) ? $data : null);

        if ($callback === null || ! $this->callbacks->route($user, $callback)) {
            // A button from a deploy that has since renamed its action, or one
            // carrying no data at all. Say something rather than leave a tap that
            // visibly does nothing.
            $this->messenger->send($user, $this->messenger->line($user, 'bot.fallback.stale_button'));
        }
    }

    /**
     * Stop the spinner on the tapped button.
     *
     * Failures are logged and swallowed: the id may already have expired on a
     * retry, and there is no user-visible consequence worth retrying the whole
     * update for.
     */
    private function acknowledge(TelegramUpdate $update): void
    {
        $queryId = $update->value('callback_query.id');

        if (! is_string($queryId) || $queryId === '') {
            return;
        }

        try {
            $this->platform->answerCallbackQuery($queryId);
        } catch (Throwable $failure) {
            Log::info('Could not acknowledge a Telegram callback query.', [
                'update_id' => $update->update_id,
                'reason' => $failure->getMessage(),
            ]);
        }
    }
}
