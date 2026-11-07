<?php

namespace App\Services\Telegram;

use App\Models\TelegramUpdate;

/**
 * Acts on one kind of Telegram update.
 *
 * Handlers are resolved from the container by `UpdateRouter`, so they may depend
 * on anything the app can build — the `Api`, an Action, a repository. They receive
 * the recorded row rather than a parsed SDK object because the row is the unit the
 * queue retries and the unit idempotency is keyed on.
 *
 * A handler that cannot finish its work must **throw**. `ProcessTelegramUpdate`
 * stamps `processed_at` only after routing returns, so a throw leaves the update
 * unprocessed for the queue to retry, while a quiet return claims the update was
 * dealt with. Swallowing an error here loses the update for good.
 */
interface HandlesUpdate
{
    public function handle(TelegramUpdate $update): void;
}
