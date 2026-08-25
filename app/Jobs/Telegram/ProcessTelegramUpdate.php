<?php

namespace App\Jobs\Telegram;

use App\Models\TelegramUpdate;
use App\Services\Telegram\UpdateRouter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Act on one recorded update, off the request.
 *
 * The webhook answers Telegram in milliseconds and leaves the work here, because
 * Telegram retries any non-2xx and treats a slow endpoint as a failing one. Every
 * side effect of an update — replies, check-ins, payments — happens in this job,
 * in whichever handler `UpdateRouter` picks for the update's kind.
 *
 * **Safe to run twice.** `processed_at` is stamped only after the work succeeds,
 * so a failed attempt retries and a duplicate delivery no-ops. That ordering is
 * the whole idempotency guarantee: a throw leaves the row unprocessed and the
 * queue owns the retry, while a stamped row is a decision already made.
 *
 * Deliberately not `ShouldBeUnique`: `IngestTelegramUpdate` dispatches only when
 * it actually inserted the row, so the unique index on `update_id` already caps
 * this at one job per update — a stronger guarantee than a cache lock, and one
 * that does not need a lock store to hold it.
 */
class ProcessTelegramUpdate implements ShouldQueue
{
    use Queueable;

    /**
     * Three attempts, backing off, then the row stays unprocessed for inspection
     * rather than vanishing into a silent success.
     */
    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [5, 30];

    public function __construct(public readonly TelegramUpdate $update) {}

    public function handle(UpdateRouter $router): void
    {
        // Re-read: the instance was serialised when the request came in, and
        // another attempt — or another delivery of the same update — may have
        // settled it since.
        $this->update->refresh();

        if ($this->update->isProcessed()) {
            return;
        }

        // A handler that fails throws, and the throw is not caught here on
        // purpose: it leaves `processed_at` null, so the queue retries the update
        // instead of this job recording it as dealt with. An unrouted update logs
        // and returns false, which is settled work — nothing was skipped.
        $router->route($this->update);

        $this->update->update(['processed_at' => now()]);
    }
}
