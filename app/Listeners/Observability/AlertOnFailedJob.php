<?php

namespace App\Listeners\Observability;

use App\Actions\Observability\SendCriticalAlert;
use Illuminate\Queue\Events\JobFailed;

/**
 * The failed-job trigger of Task 5: a job exhausting its configured retries
 * is the platform's loudest "a human, now" signal — reminders unbuilt,
 * payments uncredited — so it gets the ops chat's attention by name.
 *
 * Debounced per job class (a batch of the same job failing looks like one
 * problem, because it almost always is), and every gate lives in the send
 * action: disabled or unconfigured alerting makes this listener a no-op.
 */
class AlertOnFailedJob
{
    public function __construct(private readonly SendCriticalAlert $alerts) {}

    public function handle(JobFailed $event): void
    {
        $jobClass = $event->job->resolveName();

        $this->alerts->send(
            "failed-job:{$jobClass}",
            fn (): string => __('admin.alerts.failed_job', [
                'job' => $jobClass,
                'reason' => $this->firstLine($event->exception->getMessage()),
                'link' => route('admin.system-health.index'),
            ]),
        );
    }

    /**
     * The first line of the failure — the sentence that identifies it; the
     * full trace is on the System Health page's failed-jobs list.
     */
    private function firstLine(string $message): string
    {
        $line = strtok($message, "\n");

        return $line === false ? '' : $line;
    }
}
