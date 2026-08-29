<?php

namespace App\Console\Commands\Observability;

use App\Actions\Observability\QueueHealth;
use App\Actions\Observability\SendCriticalAlert;
use App\Enums\SettingKey;
use App\Services\Settings;
use Illuminate\Console\Command;

/**
 * The stale-heartbeat trigger of Task 5: if cron's own pulse stamp (Task 3)
 * is older than the staleness bar, the ops chat hears about it.
 *
 * With the same caveat as §2.10, stated once more because it bounds what
 * this check can ever promise: a cron that is *completely* dead silences
 * every schedule entry, including this one — only the external dead-man's
 * switch beside the stamp can detect total cron death. What this command
 * catches is the middle case: cron ticking but the heartbeat command
 * failing, or ticks being missed past the threshold.
 *
 * Never-ran reads as stale (the System Health page judges the same way):
 * alerting is off by default and only ever switched on deliberately, so by
 * the time this can fire at all, someone has configured an ops chat on a
 * deployment they expect to have a heartbeat.
 *
 * The alert repeats once per cooldown window while the condition persists —
 * silence must not read as "it fixed itself".
 */
class AlertOnStaleHeartbeat extends Command
{
    protected $signature = 'observability:alert-stale-heartbeat';

    protected $description = 'Alert the ops chat when the scheduler heartbeat has gone stale';

    public function handle(QueueHealth $queue, SendCriticalAlert $alerts, Settings $settings): int
    {
        $lastRanAt = $queue->schedulerLastRanAt();
        $threshold = $settings->integer(SettingKey::HeartbeatStalenessMinutes);

        $stale = $lastRanAt === null
            || $lastRanAt->diffInMinutes(now()) >= $threshold;

        if ($stale) {
            $alerts->send(
                'stale-heartbeat',
                fn (): string => __('admin.alerts.stale_heartbeat', [
                    'minutes' => $lastRanAt === null
                        ? __('admin.alerts.never_ran')
                        : (string) (int) floor($lastRanAt->diffInMinutes(now())),
                    'threshold' => (string) $threshold,
                    'link' => route('admin.system-health.index'),
                ]),
            );
        }

        // The check itself succeeding is the exit code's only story: an
        // alert failing to send (or alerting being off) is not a failure of
        // the check.
        return self::SUCCESS;
    }
}
