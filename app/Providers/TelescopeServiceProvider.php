<?php

namespace App\Providers;

use App\Enums\SettingKey;
use App\Models\User;
use App\Services\Settings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\EntryType;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

/**
 * Telescope, made safe for a production host with no Redis.
 *
 * §2.10's rule: local records everything, every other environment records only
 * what a human would ever come back for — exceptions, failed requests, failed
 * jobs, slow queries. The filter below *drops* the rest, so the quiet is in the
 * rows not written, not merely in the UI. (Failed jobs arrive as *all* job
 * entries — see the filter — whose final status lands via an update.)
 *
 * The slow-query bar and the prune window are `Setting`s, so an admin can tune
 * them without a redeploy (see the `observability` group in the panel).
 */
class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    /**
     * Set once the settings/cache tables are found to be absent, so a fresh
     * `migrate` does not re-issue a failing SELECT per query.
     */
    private bool $schemaMissing = false;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->hideSensitiveRequestDetails();

        $isLocal = $this->app->environment('local');

        Telescope::filter(function (IncomingEntry $entry) use ($isLocal) {
            if ($isLocal) {
                return true;
            }

            // Exceptions: `isReportableException()` is false on a plain
            // IncomingEntry, so the type is the honest check.
            if ($entry->type === EntryType::EXCEPTION) {
                return true;
            }

            // Jobs: every entry passes, not just failed ones — a job's
            // outcome is only known after dispatch. The pending row is
            // recorded when the job is queued, and the failed/processed
            // status arrives later as an EntryUpdate against that row.
            // Dropping the pending row (as `isFailedJob()` tempts — it can
            // never be true at record time) orphans every update: failed
            // jobs would record nothing, and Telescope would re-dispatch a
            // ProcessPendingUpdates job per failure that retries an update
            // destined never to land. The cost is a row per dispatched job,
            // bounded by the prune window below.
            if ($entry->type === EntryType::JOB) {
                return true;
            }

            // §2.10 says failed requests are 4xx/5xx; Telescope's own
            // `isFailedRequest()` only covers 5xx, so the 4xx half is ours.
            if ($entry->type === EntryType::REQUEST
                && (int) ($entry->content['response_status'] ?? 200) >= 400) {
                return true;
            }

            return $entry->type === 'query'
                && (float) ($entry->content['time'] ?? 0) >= $this->slowQueryThreshold();
        });
    }

    /**
     * Prevent sensitive request details from being logged by Telescope.
     *
     * Applies in every environment — a local run is exactly as capable of
     * leaking a webhook secret into a copy-pasted entry as a production one,
     * and redaction that depends on remembering to be in production is not a
     * boundary.
     */
    protected function hideSensitiveRequestDetails(): void
    {
        Telescope::hideRequestParameters([
            '_token',

            // The webhook path secret arrives as a route parameter, but the
            // payment flows carry their secrets as payload fields.
            'provider_token',
            'providerToken',
            'telegram_payment_charge_id',
        ]);

        Telescope::hideRequestHeaders([
            'authorization',
            'cookie',
            'x-csrf-token',
            'x-xsrf-token',
            'x-telegram-bot-api-secret-token',
        ]);
    }

    /**
     * Register the Telescope gate.
     *
     * The same check `EnsureUserIsAdmin` makes on every admin-panel request:
     * the authenticated user with `is_admin`. Not a second credential, not an
     * env allowlist — if this ever diverges from the middleware, one of the
     * two is a bug.
     */
    protected function gate(): void
    {
        Gate::define('viewTelescope', fn (User $user) => $user->is_admin);
    }

    /**
     * The slow-query bar, read at filter time so a settings change takes
     * effect without a restart.
     *
     * Falls back to the registry default when the schema is not there yet.
     * This filter runs on the *first* query of a fresh `migrate --force`, and
     * reading a Setting needs both the `settings` table and — because the cache
     * store is the `database` driver — the `cache` table, neither of which
     * exists until the migration it is inspecting has run. Letting that escape
     * aborted the whole command with zero migrations applied, on every
     * documented install path (docs/setup-vps.md §3, docs/setup-vm-docker.md
     * §4, `deploy/dev.sh setup`).
     *
     * Only a missing table is tolerated. A connection refused, a bad password
     * or any other QueryException still propagates: an admin-tuned threshold
     * must not silently revert to the default because the database is down.
     */
    private function slowQueryThreshold(): float
    {
        $default = (float) (is_int($fallback = SettingKey::TelescopeSlowQueryMs->default()) ? $fallback : 0);

        if ($this->schemaMissing) {
            return $default;
        }

        try {
            return (float) $this->app->make(Settings::class)->integer(SettingKey::TelescopeSlowQueryMs);
        } catch (QueryException $e) {
            if (! $this->isMissingTable($e)) {
                throw $e;
            }

            // Memoised for the life of the process: without this, every query
            // in a 39-migration run would re-issue a SELECT that is known to
            // fail. Only ever true before the tables exist, and the process
            // that creates them is short-lived, so nothing caches a stale
            // threshold into a running app.
            $this->schemaMissing = true;

            return $default;
        }
    }

    /**
     * MySQL reports a missing table as 42S02/1146, SQLite as HY000 with the
     * message below — the test suite and the asset build both run on SQLite,
     * so both spellings are load-bearing.
     */
    private function isMissingTable(QueryException $e): bool
    {
        return $e->getCode() === '42S02'
            || str_contains($e->getMessage(), 'no such table');
    }
}
