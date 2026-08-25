<?php

namespace App\Exceptions;

use App\Models\User;
use RuntimeException;

/**
 * The access gate could not reach a verdict.
 *
 * Not a rejection — a rejection is an ordinary answer, and the caller shows a
 * join button. This is the gate being *unable to answer*, and it is deliberately
 * not catchable into a "let them in" path anywhere: from the webhook the throw
 * leaves `TelegramUpdate.processed_at` null, so the queue retries the update once
 * the misconfiguration is fixed, and nothing was silently allowed in the meantime.
 *
 * Fails closed for the same reason the webhook secrets do. An unset required
 * channel is a deployment that is not finished, not a deployment with the gate
 * turned off — and treating it as the latter would quietly open the bot to
 * everybody at exactly the moment somebody fat-fingered an env file.
 */
class ChannelGateException extends RuntimeException
{
    public static function notConfigured(): self
    {
        return new self(
            'No required channel is configured, so channel membership cannot be verified. '
            .'Set TELEGRAM_REQUIRED_CHANNEL, or override the required_channel setting.'
        );
    }

    /**
     * Asked to gate somebody with no Telegram identity.
     *
     * A programming error rather than a misconfiguration: an admin who signs in
     * by email has no `telegram_id`, so there is nobody to look up in the channel.
     * The gate belongs to the bot and Mini App surfaces; the admin panel must not
     * route through it.
     */
    public static function notATelegramUser(User $user): self
    {
        return new self("User {$user->getKey()} has no telegram_id, so channel membership cannot be verified.");
    }
}
