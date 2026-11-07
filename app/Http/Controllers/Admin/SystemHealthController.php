<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Observability\SystemHealthSnapshot;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * "Is everything okay?" — one page, pull not push.
 *
 * Reads the snapshot action (heartbeat, queue, Telescope exceptions,
 * external-call counters) and owns the two levers that exist on failed jobs:
 * retry (Laravel's own `queue:retry`) and discard (`queue:forget`). The
 * levers are Laravel's mechanisms, not new ones, so the page can never drift
 * from what a worker on the host would do with the same rows.
 */
class SystemHealthController extends Controller
{
    public function __construct(private readonly SystemHealthSnapshot $health) {}

    public function index(): Response
    {
        return Inertia::render('Admin/SystemHealth', [
            'health' => $this->health->build(),
            'failedJobs' => $this->failedJobRows(),
        ]);
    }

    public function retryFailedJob(string $uuid): RedirectResponse
    {
        Artisan::call('queue:retry', ['id' => [$uuid]]);

        return redirect()->route('admin.system-health.index');
    }

    public function discardFailedJob(string $uuid): RedirectResponse
    {
        Artisan::call('queue:forget', ['id' => $uuid]);

        return redirect()->route('admin.system-health.index');
    }

    /**
     * The failed-jobs list the page offers its levers on — newest first, with
     * the payload's job name and the first line of the trace, which is the
     * part that identifies the failure on a phone screen.
     *
     * @return list<array<string, mixed>>
     */
    private function failedJobRows(): array
    {
        $rows = DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->limit(25)
            ->get()
            ->map(fn (object $job): array => [
                'uuid' => (string) $job->uuid,
                'queue' => (string) $job->queue,
                'name' => $this->jobName((string) $job->payload),
                'exception' => $this->firstLine((string) $job->exception),
                'failed_at' => (string) $job->failed_at,
            ]);

        return array_values($rows->all());
    }

    /**
     * The display name Laravel stores inside every queued payload.
     */
    private function jobName(string $payload): string
    {
        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) && is_string($decoded['displayName'] ?? null)
                ? $decoded['displayName']
                : 'Unknown';
        } catch (Throwable) {
            return 'Unknown';
        }
    }

    /**
     * `RuntimeException: boom\n\n#0 …` — the first line is the sentence; the
     * stack below it is what Telescope is for.
     */
    private function firstLine(string $exception): string
    {
        return strtok($exception, "\n") ?: '';
    }
}
