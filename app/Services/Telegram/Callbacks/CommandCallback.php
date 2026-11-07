<?php

namespace App\Services\Telegram\Callbacks;

use App\Models\User;
use App\Services\Telegram\BotButtons;
use App\Services\Telegram\BotCallback;
use App\Services\Telegram\BotCommand;
use App\Services\Telegram\CommandRouter;
use App\Services\Telegram\HandlesCallback;

/**
 * A tap on a command button, run as the command it names.
 *
 * The point of the whole conversion: a button under "that draft has been
 * dropped" and a user typing `/create` must do the same thing. They do, because
 * this handler does not implement anything — it hands the word to the same
 * `CommandRouter` a typed message reaches, so there is one `CreateCommand`, one
 * gate check, one entitlement check, and no second copy of a rule to keep in
 * step.
 *
 * **The command word is checked against the router's own map before it is
 * routed.** `callback_data` is a string a client sends us, so `cm:dropTables` is
 * a thing that can arrive; the map is the allowlist that decides what exists, and
 * `CommandRouter::handles()` reads it without running anything. Without that
 * check this handler would be a way to reach any class the map happens to hold,
 * including a command a future task adds and never offers as a button.
 *
 * No argument is passed. A button carries the command word and nothing else —
 * `/chatlink`'s payload cannot be expressed as a tap, which is why it is the one
 * command still documented as something to type.
 */
class CommandCallback implements HandlesCallback
{
    /**
     * The action word on a command button. Two letters like its siblings:
     * `callback_data` is 64 bytes and a join token costs a third of that.
     */
    public const ACTION = 'cm';

    public function __construct(
        private readonly CommandRouter $commands,
        private readonly BotButtons $buttons,
    ) {}

    public function handle(User $user, BotCallback $callback): void
    {
        $name = $callback->argument(0);

        if ($name === null || ! $this->commands->handles($name)) {
            // A button from a deploy that has since dropped its command, or a
            // crafted payload. Nothing is logged: the first is ordinary and the
            // second says nothing we act on.
            $this->buttons->stale($user);

            return;
        }

        $this->commands->route($user, BotCommand::named($name));
    }
}
