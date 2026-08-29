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

## Task 2 — Log Viewer + structured logging conventions

**Status: complete** (commit `feat(observability): log viewer + structured logging conventions`)

### What landed

**Log Viewer** (`opcodesio/log-viewer` v3.24.2), published config at `config/log-viewer.php`:

- mounted at **`admin/logs`**, inside the panel's URL space rather than a second door
- route middleware `['web','auth',EnsureUserIsAdmin,AuthorizeLogViewer]`; API middleware adds
  `EnsureFrontendRequestsAreStateful` — the SPA-side fetches ride the same session
- `Gate::define('viewLogViewer', fn (User $user) => $user->is_admin)` in `AppServiceProvider` — the **same
  check** the Inertia panel middleware and the Telescope `viewTelescope` gate use; divergence between the
  three would be a bug
- pre-built assets committed under `public/vendor/log-viewer/`
- PHPStan rejected the published config's raw `env()` uses (`explode(',', env(...))`, `ucfirst(env(...))` —
  `env()` answers `string|true|null`); narrowed with `is_string()` on config-local variables rather than
  suppressing

**Structured logging (addendum-4 §2.10)** — one info line per move a human comes back for, carrying the
identifiers a Log Viewer search needs:

| Site | Level | Line |
|---|---|---|
| `SettleCheckIn` | info | `A check-in was settled.` — check_in_id, challenge_id, participant_id, status, score, streak |
| `CoinLedger::write` | info | `A coin ledger entry was written.` — full ledger identity incl. idempotency_key; **replays log nothing** (they return before the log), so line count reconciles against the ledger |
| `ApplyAiVerdict` | info | `An AI verdict settled a proof.` — check_in_id, approved, confidence |
| `ReviewProofWithAi` | warning | `An AI verdict fell below the confidence threshold and went to the manual queue.` |
| `VerifyChallengeChat` | warning | `A linked chat failed re-verification against a creator the bot cannot message.` |
| `ShopCallback` (×2) | warning | stale/unpriced package tap — user_id, platform, package_index |
| `ShopCommand` | warning | empty/unpriced shelf for the payer's rail |
| `RefundStarsPayment` | error / info | provider refusal (`reason` included) / refund success (coins_clawed_back, idempotency_key) |

### Tests

- `tests/Feature/Observability/LogViewerTest.php` — guest → login redirect, non-admin → 403, admin → 200
- `tests/Feature/Observability/StructuredLoggingTest.php` — settlement line, ledger line + replay-silence,
  AI verdict info + below-threshold warning (verdict served through a closure reading `test()->verdictContent`
  because Http::fake merges first-match-wins), refund refusal error

### Traps recorded

- **Mockery spy chain order:** on a `Log::spy()`, every chained call (`once()`, `withArgs()`) clones the
  expectation **and verifies immediately**. `->once()->withArgs(f)` therefore verifies the count with no arg
  filter — "the method was called exactly once, total" — which fails whenever the code path logs two info
  lines (e.g. the AI approval also travelling `SettleCheckIn`, which logs its own line). The matcher must
  come first: **`->withArgs(f)->once()`**. (Verified against Mockery's `VerificationDirector::cloneApplyAndVerify`
  and `ReceivedMethodCalls::verify`.)
- **`Challenge::periods()` composite ordering:** the relation carries its own `orderBy('index')`, so
  `->orderByDesc('index')` on top yields `order by index asc, index desc` — **ASC wins**. Load the collection
  and use `->first()`/`->last()` instead of composing a second order.

## Task 3 — Scheduler heartbeat + optional external dead-man's-switch

**Status: complete**

### What landed

- **`scheduler_heartbeats` table + `SchedulerHeartbeat` model** — one row (`key` unique, default
  `scheduler`), `last_ran_at` stamped every minute. A dedicated table, deliberately not a cache entry
  (the database cache driver loses rows on any `cache:clear` — exactly when someone is debugging and a
  false "cron never ran" alarm hurts most) and not a `settings` override (that registry is admin
  tunables, not runtime state).
- **`observability:heartbeat` command** (`app/Console/Commands/Observability/RecordHeartbeat.php`) →
  `RecordSchedulerHeartbeat` action, scheduled `everyMinute()` in `routes/console.php`. Only cron can
  run it, so its success *is* the evidence cron is alive.
- **`QueueHealth`** (`app/Actions/Observability/QueueHealth.php`) — read-only snapshot over Laravel's own
  `jobs`/`failed_jobs` tables: pending count, oldest-pending age (a backlog five seconds old is a busy
  minute; one job four hours old is a stuck worker), failed count; plus the scheduler's last stamp.
- **Optional external ping** — `services.healthcheck.ping_url` from `HEALTHCHECK_PING_URL`
  (`.env`/`.env.example`, unset by default = strict no-op, asserted with `Http::preventStrayRequests`).
  Fires **after** the stamp; non-2xx or unreachable logs a §2.10 warning (`ping_url`, `status`/`reason`)
  and never breaks the stamp or the command's exit code. The only mechanism that can detect **total
  cron failure**, documented in the action's docblock: a dead cron silences every schedule entry
  including the heartbeat itself, so only an outside monitor noticing the pings stopped can say so.
- **`heartbeat_staleness_minutes` Setting** (default 5) wired into the admin panel's observability
  group (enum case + default, controller group, en/fa labels); `SettingsPanelTest` count 25 → 26.

### Tests

`tests/Feature/Observability/SchedulerHeartbeatTest.php` — stamp advances across two `travel()`ed runs
and stays **one row**; no outbound call when unset (plus the staleness default comes from the registry);
a 500 from the monitor leaves the stamp written, the command successful, and a warning logged;
`QueueHealth` reads seeded `jobs`/`failed_jobs` exactly; empty tables answer zeros/null (never-ran
scheduler is null, which every reader treats as stale — not healthy).

### Notes

- `jobs.available_at` is a raw Unix-integer column; `QueueHealth` converts to a `CarbonInterval` so
  callers never touch the integer.
- The model stamps its `key` default via `booted()`; factory carries a `ranMinutesAgo()` state for
  Task 4/5 staleness tests.
