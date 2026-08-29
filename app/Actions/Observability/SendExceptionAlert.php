<?php

namespace App\Actions\Observability;

use Throwable;

/**
 * The exception-rate trigger of Task 5: every *reported* exception offers an
 * alert, debounced per exception class by {@see SendCriticalAlert}.
 *
 * Signal choice (the task asks us to pick one and say which): the exception
 * handler, not Telescope. Telescope is a sample — it can be disabled, pruned,
 * or not yet migrated, and Task 1 deliberately keeps it cheap in production —
 * while `report()` fires for every unhandled exception the app itself sees,
 * whatever Telescope's state. The debounced result is the "rate" part: a
 * hundred of the same error inside the cooldown window still cost one
 * message.
 */
class SendExceptionAlert
{
    public function __construct(private readonly SendCriticalAlert $alerts) {}

    public function handle(Throwable $exception): void
    {
        $class = $exception::class;

        $this->alerts->send(
            "exception:{$class}",
            fn (): string => __('admin.alerts.exception', [
                'class' => $class,
                'reason' => $this->firstLine($exception->getMessage()),
                'link' => route('admin.system-health.index'),
            ]),
        );
    }

    /**
     * `RuntimeException: boom` — the first line is the sentence a phone
     * screen needs; the trace below it is what Telescope is for.
     */
    private function firstLine(string $message): string
    {
        $line = strtok($message, "\n");

        return $line === false ? '' : $line;
    }
}
