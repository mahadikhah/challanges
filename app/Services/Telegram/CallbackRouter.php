<?php

namespace App\Services\Telegram;

use App\Models\User;
use Illuminate\Contracts\Container\Container;

/**
 * Sends a parsed callback to the handler registered for its action.
 *
 * The third of the three routers, and the same shape as the other two on purpose:
 * `UpdateRouter` maps an update kind to a class, `CommandRouter` maps a command
 * word, this maps a button's action word. All three take their map from
 * `TelegramServiceProvider`, so everything the bot reacts to is readable in one
 * file rather than inferred from class names scattered across a directory.
 */
class CallbackRouter
{
    /**
     * @param  array<string, class-string<HandlesCallback>>  $handlers  keyed by action word
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $handlers = [],
    ) {}

    /**
     * Hand the callback to its handler, reporting whether anyone took it.
     *
     * False is an ordinary answer, and more ordinary here than for a command: an
     * inline keyboard sent last week is still tappable today, so a deploy that
     * renames an action leaves live buttons whose data nothing claims. The caller
     * decides what to say; nothing is logged as a problem.
     */
    public function route(User $user, BotCallback $callback): bool
    {
        $handler = $this->handlers[$callback->action] ?? null;

        if ($handler === null) {
            return false;
        }

        $this->container->make($handler)->handle($user, $callback);

        return true;
    }
}
