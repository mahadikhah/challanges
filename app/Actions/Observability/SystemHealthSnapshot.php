<?php

namespace App\Actions\Observability;

use App\Enums\ExternalCallProvider;
use App\Enums\SettingKey;
use App\Services\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Telescope\EntryType;

/**
 * Assemble the System Health page's answer to "is everything okay?".
 *
 * Three data sources, deliberately independent of one another (§3.9): the
 * heartbeat row and queue tables are always there; the external-call counters
 * are always there; only the recent-exceptions list depends on Telescope, and
 * it degrades to an explicit "no data" state rather than an empty table that
 * looks broken — Telescope can be disabled or pruned, and the page must still
 * answer the question with what remains.
 */
readonly class SystemHealthSnapshot
{
    public function __construct(
        private readonly QueueHealth $queue,
        private readonly Settings $settings,
    ) {}

    /**
     * Everything the page shows, in one call.
     *
     * @return array{
     *     scheduler: array{last_ran_at: CarbonImmutable|null, stale_after_minutes: int, healthy: bool},
     *     queue: array{pending_count: int, oldest_pending_minutes: int|null, failed_count: int},
     *     exceptions: array{available: bool, rows: list<array{class: string, count: int, last_seen_at: string}>},
     *     providers: list<array{provider: string, label: string, success: int, failure: int}>,
     * }
     */
    public function build(): array
    {
        return [
            'scheduler' => $this->scheduler(),
            'queue' => $this->queueSnapshot(),
            'exceptions' => $this->exceptions(),
            'providers' => $this->providers(1),
        ];
    }

    /**
     * @return array{last_ran_at: CarbonImmutable|null, stale_after_minutes: int, healthy: bool}
     */
    private function scheduler(): array
    {
        $threshold = $this->settings->integer(SettingKey::HeartbeatStalenessMinutes);
        $lastRanAt = $this->queue->schedulerLastRanAt();

        // Never-ran reads as stale, not healthy: a fresh install's first
        // minutes and a wiped table look identical to a dead cron, and the
        // safe reading is the alarming one.
        $healthy = $lastRanAt !== null
            && $lastRanAt->diffInMinutes(now()) < $threshold;

        return [
            'last_ran_at' => $lastRanAt,
            'stale_after_minutes' => $threshold,
            'healthy' => $healthy,
        ];
    }

    /**
     * @return array{pending_count: int, oldest_pending_minutes: int|null, failed_count: int}
     */
    private function queueSnapshot(): array
    {
        $snapshot = $this->queue->snapshot();

        return [
            'pending_count' => $snapshot['pending_count'],
            'oldest_pending_minutes' => $snapshot['oldest_pending_age']?->totalMinutes === null
                ? null
                : (int) floor($snapshot['oldest_pending_age']->totalMinutes),
            'failed_count' => $snapshot['failed_count'],
        ];
    }

    /**
     * The recent unhandled exceptions, from Telescope's own table — grouped
     * the way Telescope groups them, by family hash, so a burst of the same
     * error is one row with a count instead of a page of repeats.
     *
     * @return array{available: bool, rows: list<array{class: string, count: int, last_seen_at: string}>}
     */
    private function exceptions(): array
    {
        // Disabled by config or not migrated: the panel says so rather than
        // rendering an empty table that reads as "no exceptions ever".
        if (! config('telescope.enabled', false) || ! Schema::hasTable('telescope_entries')) {
            return ['available' => false, 'rows' => []];
        }

        $rows = DB::table('telescope_entries')
            ->where('type', EntryType::EXCEPTION)
            ->where('created_at', '>=', now()->subDay())
            ->groupBy('family_hash')
            ->selectRaw('MAX(JSON_UNQUOTE(JSON_EXTRACT(content, "$.class"))) as exception_class')
            ->selectRaw('COUNT(*) as occurrence_count')
            ->selectRaw('MAX(created_at) as last_seen_at')
            ->get()
            ->map(fn (object $row): array => [
                'class' => is_string($row->exception_class) ? $row->exception_class : 'Unknown',
                'count' => (int) $row->occurrence_count,
                'last_seen_at' => (string) $row->last_seen_at,
            ]);

        return [
            'available' => true,
            'rows' => array_values($rows->all()),
        ];
    }

    /**
     * Per-provider success/failure totals over a rolling window of days.
     *
     * @param  positive-int  $days
     * @return list<array{provider: string, label: string, success: int, failure: int}>
     */
    public function providers(int $days): array
    {
        $since = today()->subDays($days - 1)->toDateString();

        /** @var array<string, array<string, int>> $counts provider => outcome => total */
        $counts = [];

        foreach (DB::table('external_call_stats')
            ->where('day', '>=', $since)
            ->groupBy('provider', 'outcome')
            ->selectRaw('provider, outcome, SUM(count) as total')
            ->get() as $row) {
            $counts[(string) $row->provider][(string) $row->outcome] = (int) $row->total;
        }

        // Every provider case appears, zeros included — a provider with no
        // calls is a different sentence on a dashboard from a provider with
        // no failures, and neither should be a missing row.
        $providers = collect(ExternalCallProvider::cases())
            ->map(fn (ExternalCallProvider $provider): array => [
                'provider' => $provider->value,
                'label' => $provider->label(),
                'success' => (int) ($counts[$provider->value]['success'] ?? 0),
                'failure' => (int) ($counts[$provider->value]['failure'] ?? 0),
            ]);

        return array_values($providers->all());
    }
}
