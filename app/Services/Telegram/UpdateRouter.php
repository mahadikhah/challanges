<?php

namespace App\Services\Telegram;

use App\Models\TelegramUpdate;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;

/**
 * Sends a recorded update to the handler for its kind.
 *
 * One `match`-free lookup table instead of a growing conditional in the queued
 * job: `TelegramUpdate::kind()` names the update, this maps that name to a class,
 * and the container builds it. The map itself lives in `TelegramServiceProvider`
 * so that the complete list of things the bot reacts to is readable in one place
 * rather than spread across handler class names.
 *
 * Routing is deliberately *not* the same thing as processing. This class decides
 * who acts; `ProcessTelegramUpdate` owns whether the update is then marked done.
 * Keeping those apart is what lets a handler throw and be retried without the
 * router needing to know anything about the queue.
 */
class UpdateRouter
{
    /**
     * @param  array<string, class-string<HandlesUpdate>>  $handlers  keyed by update kind
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $handlers = [],
    ) {}

    /**
     * Hand the update to its handler, reporting whether anyone took it.
     *
     * An unclaimed update is not an error. Telegram adds update kinds faster than
     * we adopt them, and `setWebhook` asks only for the kinds we listed — so
     * anything unclaimed arriving here is either a kind we recorded but have not
     * wired yet, or a webhook registered by an older deploy. Both are worth a log
     * line and neither is worth failing the job over.
     */
    public function route(TelegramUpdate $update): bool
    {
        $kind = $update->kind();
        $handler = $kind === null ? null : ($this->handlers[$kind] ?? null);

        if ($handler === null) {
            Log::info('No handler is registered for this Telegram update.', [
                'update_id' => $update->update_id,
                'kind' => $kind,
                'keys' => array_keys($update->payload),
            ]);

            return false;
        }

        $this->container->make($handler)->handle($update);

        return true;
    }
}
