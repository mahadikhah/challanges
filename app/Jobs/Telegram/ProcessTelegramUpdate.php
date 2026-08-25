<?php

namespace App\Jobs\Telegram;

use App\Models\TelegramUpdate;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Act on one recorded update, off the request.
 *
 * The webhook answers Telegram in milliseconds and leaves the work here, because
 * Telegram retries any non-2xx and treats a slow endpoint as a failing one. Every
 * side effect of an update — replies, check-ins, payments — happens in this job.
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

    public function handle(): void
    {
        // Re-read: the instance was serialised when the request came in, and
        // another attempt — or another delivery of the same update — may have
        // settled it since.
        $this->update->refresh();

        if ($this->update->isProcessed()) {
            return;
        }

        $kind = $this->update->kind();

        if ($kind === null) {
            // Telegram adds update kinds faster than we adopt them. Recorded and
            // stamped, so it neither blocks the queue nor lingers as unprocessed
            // work somebody has to triage.
            Log::info('Ignored a Telegram update of an unhandled kind.', [
                'update_id' => $this->update->update_id,
                'keys' => array_keys($this->update->payload),
            ]);
        }

        // Routing to the per-kind handlers arrives with the command router in Bot
        // Core Task 2. Until then every recognised kind is a no-op, which is why
        // stamping unconditionally here is honest rather than premature: nothing
        // has been skipped.
        $this->update->update(['processed_at' => now()]);
    }
}
