<?php

namespace App\Actions\Observability;

use App\Enums\SettingKey;
use App\Models\SchedulerHeartbeat;
use App\Services\Settings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stamp the scheduler's one heartbeat row, then optionally tell the outside.
 *
 * The stamp is the whole point: a row whose `last_ran_at` says "just now" is
 * how Tasks 4 and 5 know cron is alive, because only cron runs this command.
 *
 * The ping is genuinely optional — a no-op wherever `HEALTHCHECK_PING_URL` is
 * unset, which is everywhere by default. It earns its existence as the one
 * mechanism that can detect **total cron failure**: when cron dies completely,
 * no schedule entry fires, so nothing inside this application can notice, and
 * this very line never runs. An external dead-man's-switch service (healthchecks.io
 * and the like) notices our pings stopped and says so through a channel that
 * does not depend on our scheduler being alive. That is why the ping fires
 * after the stamp and why its failure can never break the stamp: a monitoring
 * outage must not take down the record the monitoring is about.
 */
class RecordSchedulerHeartbeat
{
    public function __construct(private readonly Settings $settings) {}

    public function handle(): CarbonImmutable
    {
        $ranAt = now()->toImmutable();

        SchedulerHeartbeat::query()->updateOrCreate(
            ['key' => SchedulerHeartbeat::SCHEDULER_KEY],
            ['last_ran_at' => $ranAt],
        );

        $pingUrl = config('services.healthcheck.ping_url');

        if (is_string($pingUrl) && $pingUrl !== '') {
            try {
                $response = Http::get($pingUrl);

                if ($response->failed()) {
                    Log::warning('The heartbeat\'s external dead-man\'s-switch ping failed.', [
                        'ping_url' => $pingUrl,
                        'status' => $response->status(),
                    ]);
                }
            } catch (Throwable $unreachable) {
                // §2.10's logging conventions: an external provider failure is
                // a warning in the file log — the file-based backstop for when
                // the database side is the problem. The stamp above already
                // happened and stays.
                Log::warning('The heartbeat\'s external dead-man\'s-switch ping failed.', [
                    'ping_url' => $pingUrl,
                    'reason' => $unreachable->getMessage(),
                ]);
            }
        }

        return $ranAt;
    }

    /**
     * How stale the stamp may get before a reader should call the scheduler
     * unhealthy. Cron runs every minute; five minutes absorbs the host's
     * jitter without ever hiding a genuinely dead cron for long.
     */
    public function stalenessThreshold(): CarbonInterval
    {
        return CarbonInterval::minutes(
            $this->settings->integer(SettingKey::HeartbeatStalenessMinutes),
        );
    }
}
