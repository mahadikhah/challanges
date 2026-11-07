<?php

namespace App\Actions\Observability;

use App\Models\SchedulerHeartbeat;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Support\Facades\DB;

/**
 * What the queue looks like right now, from Laravel's own tables.
 *
 * A read-only view over `jobs` and `failed_jobs` — the database queue driver's
 * native storage, so there is nothing extra to maintain and nothing the worker
 * does that this cannot see. `jobs.available_at` is a Unix timestamp because
 * Laravel's migrations define it that way; it is converted here so callers
 * never touch the raw integer.
 */
class QueueHealth
{
    /**
     * Pending job count, the oldest pending job's age, and how many jobs have
     * exhausted their retries. The oldest age answers the question a count
     * cannot: a backlog of five jobs five seconds old is a busy minute, and
     * one job four hours old is a stuck worker.
     *
     * @return array{pending_count: int, oldest_pending_age: CarbonInterval|null, failed_count: int}
     */
    public function snapshot(): array
    {
        $oldestPendingAt = DB::table('jobs')->min('available_at');

        return [
            'pending_count' => DB::table('jobs')->count(),
            'oldest_pending_age' => $oldestPendingAt !== null
                ? CarbonImmutable::createFromTimestamp((int) $oldestPendingAt)->diffAsCarbonInterval(now())
                : null,
            'failed_count' => DB::table('failed_jobs')->count(),
        ];
    }

    /**
     * When the scheduler last stamped its heartbeat — null when it never has
     * (a fresh install's first minute, or a wiped table), which every reader
     * treats as stale rather than healthy.
     */
    public function schedulerLastRanAt(): ?CarbonImmutable
    {
        return SchedulerHeartbeat::query()
            ->where('key', SchedulerHeartbeat::SCHEDULER_KEY)
            ->value('last_ran_at');
    }
}
