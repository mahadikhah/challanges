<?php

namespace App\Services\Telegram;

use App\Models\User;

/**
 * Acts on one slash command from one user.
 *
 * The user is resolved server-side before a handler is reached — from
 * `telegram_id` on our own row, never from anything the payload asserts about
 * who is speaking — and is the *same instance* the arrival created or found, so
 * `wasRecentlyCreated` still means "first ever `/start`" by the time it gets
 * here. See `ResolveTelegramUser`.
 *
 * **Every command that creates, joins or spends must call
 * `VerifyChannelMembership::ensure()` first.** The gate is not applied to messages
 * as a whole, deliberately: at ~30 Bot API calls a second one `getChatMember` per
 * message would compete with reminder fan-out, and the product rule is about
 * privileged actions rather than about saying hello. A handler that skips it is
 * the bug this note exists to prevent.
 *
 * A handler that cannot finish its work must **throw**: the queued job stamps
 * `processed_at` only after routing returns, so a throw means retried, while a
 * quiet return means dealt with.
 */
interface HandlesBotCommand
{
    public function handle(User $user, BotCommand $command): void;
}
