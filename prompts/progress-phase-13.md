# Phase 13 — observability & production hardening (in progress)

Task-by-task record. Conventions and the operating loop live in `CLAUDE.md`; the phase's task
definitions live in `prompts/phase-13.md`.

---

## Task 1 — Telescope, production-safe (`64be469`)

**What shipped.** `laravel/telescope` **v5.22.1** (verified against the installed Laravel 13 —
`composer.json` pins `^5.22`), database driver only — the package default, confirmed, since no Redis
exists on the production host. `app/Providers/TelescopeServiceProvider.php`:

- **Gate.** `Gate::define('viewTelescope', fn (User $user) => $user->is_admin)` — literally the same
  check `EnsureUserIsAdmin` makes on every admin-panel request, with a docblock saying divergence
  between the two is a bug. UI sits behind `['web', 'auth', EnsureUserIsAdmin::class, Authorize::class]`
  (config/telescope.php), so guests are redirected to login and non-admins get 403 before Telescope's
  own `Authorize` runs.
- **Environment split.** `local` → unfiltered. Every other environment → `Telescope::filter()` keeping
  exceptions, requests ≥ 400 (Telescope's own `isFailedRequest()` covers only 5xx — the 4xx half is
  ours), slow queries, and **all job entries** (see below). Dropped at `record()` time — the rows are
  never written, not merely hidden in the UI.
- **Jobs — the one deliberate broadening of §2.10's list, and why.** A job's outcome is only known
  after dispatch: `Queue::createPayloadUsing` records the *pending* row when the job is queued, and the
  failed/processed status arrives later as an `EntryUpdate` against that row. A filter keyed on
  `isFailedJob()` (as the docs' example tempts) can never pass at record time — the status is always
  `pending` then — so it drops the base row, orphans every update, records **nothing** for failed jobs,
  and makes Telescope re-dispatch a `ProcessPendingUpdates` job per failure that retries an update
  destined never to land. Discovered by test, verified against vendor source (`Telescope::record()`,
  `JobWatcher`, `DatabaseEntriesRepository::update()`). Keeping every job entry costs one row per
  dispatched job, bounded by the prune window.
- **Redaction — every environment, not just production.** `hideRequestHeaders`: authorization, cookie,
  CSRF, `x-telegram-bot-api-secret-token`. `hideRequestParameters`: `_token`, `provider_token`,
  `telegram_payment_charge_id`. Both HTTP watchers (`RequestWatcher`, `ClientRequestWatcher`) capped at
  `TELESCOPE_RESPONSE_SIZE_LIMIT` (default **4 KB**, was 64/absent) so proof-media bytes are stored as
  `"Purged By Telescope"` rather than duplicated — uploads already arrive as name+size metadata, and
  the media itself lives in proof storage with its own access control.
- **Slow-query threshold** is a Setting (`telescope_slow_query_ms`, default 500, read at filter time so
  an admin change takes effect without a restart) and **the retention window** is a Setting too
  (`telescope_prune_hours`, default 72). Both live in a new `observability` group in the admin panel
  (SettingsController::GROUPS + lang/en + lang/fa + Settings.tsx GROUPS; the registry pattern meant no
  request-validation changes).

**Prune scheduling — a trap worth remembering.** The first attempt resolved the Setting at
`routes/console.php` load: `Schedule::command('telescope:prune', ['--hours' => app(Settings::class)->…])`.
Console routes load on **every application boot**, so that warmed the settings cache
(`Cache::rememberForever('settings.overrides')`) before anything else ran, freezing the memoised
overrides for the whole process — caught by `SettingsTest`'s "prefers a stored override over the shipped
default" failing (factory-written rows are invisible to a warmed cache; only `set()`/`forget()` flush).
Fix: `observability:prune-telescope` (app/Console/Commands/Observability/PruneTelescope.php) reads the
Setting in `handle()`, once a day, at prune time — the same pattern as `PruneProofMediaCommand`.

**Tests** (`tests/Feature/Observability/TelescopeTest.php`, 10): guest redirect + non-admin 403 + admin
200 on `/telescope`; a 200 records nothing; 4xx recorded; 5xx + exception recorded; a failed job lands
`failed` and a successful one `processed` (both statuses asserted — a missing `failed` means the update
chain is broken); slow query kept / fast dropped (SLEEP past the threshold between two requests); the
webhook secret header stored as `********`; an oversized body stored as `Purged By Telescope`; the
prune command honours the retention Setting (stale row gone, fresh row kept).

Test-environment traps the file documents in its header: phpunit.xml sets `TELESCOPE_ENABLED=false`,
and the vendor provider registers watchers/routes/recording **only at boot when enabled** — so
beforeEach flips `$_ENV['TELESCOPE_ENABLED']` then `refreshApplication()` (restored in afterEach). That
mid-test refresh escapes RefreshDatabase's transaction, so the file uses **DatabaseTruncation**. And
the in-process `queue:work` must run with `--memory 1024`: the worker shares the suite's PHP process,
whose usage is far above the worker's default 128M limit by then — at the default it stops after the
first job and the second is never processed (only reproducible in a full-suite run).

`SettingsPanelTest` count 23 → 25 for the two new keys.

**Green:** `sail composer ci:check` — pint, phpstan (lvl 7), 1566 tests + 4 pre-existing skips, eslint,
prettier, `tsc --noEmit`.

**Next:** Phase 13 Task 2 — Log Viewer + structured logging conventions.
