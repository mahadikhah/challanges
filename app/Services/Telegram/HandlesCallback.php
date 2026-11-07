<?php

namespace App\Services\Telegram;

use App\Models\User;

/**
 * Acts on one tapped inline button.
 *
 * The counterpart of `HandlesBotCommand`, with the same two contracts. The user is
 * resolved server-side from `callback_query.from` before a handler is reached, and
 * **every handler that creates, joins or spends must call
 * `VerifyChannelMembership::ensure()` first** — a button that was rendered while
 * the user was a member proves nothing about now, and buttons outlive the state
 * that produced them.
 *
 * The query has already been acknowledged by the time a handler runs, so a handler
 * never has to answer it and must not assume its own reply is instant. Anything the
 * user should read goes out as an ordinary message.
 *
 * A handler that cannot finish its work must **throw**: `ProcessTelegramUpdate`
 * stamps `processed_at` only after routing returns, so a throw means retried while
 * a quiet return means dealt with.
 */
interface HandlesCallback
{
    public function handle(User $user, BotCallback $callback): void;
}
