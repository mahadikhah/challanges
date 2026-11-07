<?php

namespace App\Actions\Telegram;

use App\Enums\MessagingPlatform;
use App\Jobs\Telegram\ProcessTelegramUpdate;
use App\Models\TelegramUpdate;

/**
 * Take one update off the wire: record it, then queue the work.
 *
 * Two things happen here and they happen in this order for a reason. Recording
 * first means the payload survives even if every later step fails, so nothing
 * the platform sent is ever lost to a bug downstream. Queueing second means the
 * request can answer 200 immediately — a messenger platform treats a slow
 * webhook as a failing one and retries it.
 *
 * **`(platform, update_id)` is the idempotency key.** The platform redelivers
 * an update whenever it does not see a 2xx, including when our response is lost
 * in transit after we already handled it. `firstOrCreate` against the unique
 * index makes the second delivery find the first row instead of inserting a
 * twin, and the job is dispatched only for an insert that actually happened —
 * so one update produces one job no matter how many times it arrives. The
 * platform scopes the key because Telegram and Bale each number their own
 * updates, and the same number on the two of them is two different updates.
 */
class IngestTelegramUpdate
{
    /**
     * @param  array<string, mixed>  $payload  The raw update, exactly as the platform sent it.
     */
    public function handle(MessagingPlatform $platform, int $updateId, array $payload): TelegramUpdate
    {
        $update = TelegramUpdate::query()->firstOrCreate(
            ['platform' => $platform, 'update_id' => $updateId],
            ['payload' => $payload],
        );

        // `wasRecentlyCreated` is the race-safe test for "we are the delivery
        // that won". `firstOrCreate` catches the unique-constraint violation a
        // simultaneous delivery causes and re-reads instead, so the loser lands
        // here with a false and correctly declines to queue a second job.
        if ($update->wasRecentlyCreated) {
            ProcessTelegramUpdate::dispatch($update);
        }

        return $update;
    }
}
