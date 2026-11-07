<?php

namespace App\Services\Telegram;

use App\Models\User;
use Illuminate\Contracts\Container\Container;

/**
 * Sends a parsed command to the handler registered for its name.
 *
 * The same shape as `UpdateRouter`, one level down: that one maps an update kind
 * to a class, this maps a command word to a class, and both take their map from
 * `TelegramServiceProvider` so the complete list of things the bot reacts to is
 * readable in one file rather than inferred from class names.
 *
 * A lookup table rather than a `match` inside `MessageHandler`, for the same
 * reason: the command list grows with every task in this phase, and a growing
 * conditional in the middle of a handler is where the channel gate eventually
 * gets forgotten for one branch.
 */
class CommandRouter
{
    /**
     * @param  array<string, class-string<HandlesBotCommand>>  $handlers  keyed by command word
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $handlers = [],
    ) {}

    /**
     * Hand the command to its handler, reporting whether anyone took it.
     *
     * False is an ordinary answer — a user typed `/wat`, or a command from an
     * older bot menu — and it is the caller's business to reply. Nothing is
     * logged here as a problem, because a mistyped command is not one.
     */
    public function route(User $user, BotCommand $command): bool
    {
        $handler = $this->handlers[$command->name] ?? null;

        if ($handler === null) {
            return false;
        }

        $this->container->make($handler)->handle($user, $command);

        return true;
    }

    /**
     * Whether a command word has a handler, without running it.
     */
    public function handles(string $name): bool
    {
        return isset($this->handlers[$name]);
    }
}
