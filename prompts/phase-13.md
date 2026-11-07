## Phase 13 Task 1: Telescope — production-safe install

### Goal
Install Laravel Telescope with environment-aware filtering, redaction of secrets and media, and access
control gated through the existing admin auth — usable identically in local dev, VPS, and shared cPanel
since it's entirely database-backed.

### Before starting
- Query graphify: `graphify query "admin authorization gate"`, `graphify query "Setting model"`.
- Read `prompts/main-addendum-4.md` §2.10 in full, especially "Telescope in production" and "Redaction."
- `composer require laravel/telescope`, confirm the resolved version actually supports the installed Laravel
  version — don't assume, check `composer.json` after install like the bot SDK was pinned in Setup Task 1.

### What to Build
1. `php artisan telescope:install` + migrate. Confirm the tables land on MySQL, not assumed elsewhere.
2. `TelescopeServiceProvider`: gate `viewTelescope` to the same admin-check the Inertia admin panel already
   uses — find and reuse that check, don't write a second one.
3. Environment split in the provider: `app()->environment('local')` → default (unfiltered) watchers. Any
   other environment → `Telescope::filter()` allowing only: unhandled exceptions, requests with a 4xx/5xx
   response, queries slower than a `Setting`-backed threshold (default 500ms), and failed jobs. Everything
   else is dropped, not just hidden in the UI — don't write rows you'll never want.
4. Redaction: configure `hideRequestParameters`/`hideRequestHeaders` for webhook secrets, payment provider
   tokens/authority ids, and any `Authorization` header. For the HTTP client and request watchers
   specifically, truncate or exclude request/response bodies above a size threshold (a few KB) so submitted
   image/voice proof bytes never land in a Telescope entry — proof media already has its own storage; this
   watcher should reference it, not duplicate it.
5. `telescope:prune --hours={Setting, default 72}` added to the existing scheduler.

### Tests (Pest, required)
- A non-admin user hitting `/telescope` is rejected; an admin user is allowed.
- In a non-local environment, a successful 200 request produces no Telescope entry; a 500 response, an
  exception, and a failed job each do.
- A request carrying a configured secret header/parameter is stored redacted, not verbatim.
- A large binary body (simulate an image upload) is truncated/excluded from the stored watcher entry, not
  stored whole.

### Explicitly Out of Scope
- No System Health dashboard (Task 4) — this task only gets Telescope itself installed and safe.
- No alerting (Task 5).

### Code Rules
- Follow `CLAUDE.md`. No Redis — confirm Telescope's driver config is `database`, not left on a package
  default that might assume otherwise.

Work only on this task.

## Phase 13 Task 2: Log Viewer + logging conventions

### Goal
Install `opcodesio/log-viewer` as a friendly, Telescope-independent UI over the plain log files, and pass
over the existing codebase to add the structured logging that's currently missing at key decision points.

### Before starting
- Query graphify: `graphify query "admin authorization gate"`, `graphify query "AI approval decision"`,
  `graphify query "Bale degraded capability"`.
- Read `prompts/main-addendum-4.md` §2.10.
- `composer require opcodesio/log-viewer:^3.24`, then `php artisan log-viewer:publish`. Confirm the resolved
  version in `composer.json` supports the installed Laravel version.

### What to Build
1. Mount Log Viewer under the same admin URL prefix and middleware group the Inertia admin panel already
   uses (not its default standalone route/auth), so it's gated by the same "is admin" check as Telescope.
2. Confirm the default `stack`/daily file log channel is what Log Viewer points at; no new channel needed
   unless one doesn't already exist.
3. Establish and apply logging conventions across the existing codebase — this is the bulk of the task, not
   the package install:
   - `Log::info` with context (`challenge_id`, `participant_id`, provider, idempotency key as applicable) on
     every settlement, payment credit, and AI approval decision.
   - `Log::warning` on every fallback path: AI low-confidence → manual queue, a degraded Bale capability
     being used instead of the Telegram-parity behavior, a `ChallengeChat` failing re-verification.
   - `Log::error` on every external provider failure (Telegram/Bale/AI-provider/payment call failure) that
     isn't already going to end up in Telescope's exception watcher — this is the file-based backstop for
     when the database itself is the problem.
   - Grep Phases 3–12 for `catch` blocks and provider-call sites that currently swallow or only partially log
     failures; add the missing calls rather than only writing new ones going forward.

### Tests (Pest, required)
- A non-admin user hitting the Log Viewer route is rejected; an admin user is allowed.
- Spot-check tests (not exhaustive) asserting a settlement, a payment credit, and an AI approval decision
  each produce a log entry with the expected context fields — use `Log::spy()`/`Log::shouldReceive()`.
- A simulated external provider failure (mocked `Http::fake()` failure response) produces an `error`-level
  log entry.

### Explicitly Out of Scope
- No changes to Telescope's config (Task 1).
- No log aggregation/shipping to an external service — local files only, per §2.10.

### Code Rules
- Follow `CLAUDE.md`. Every log call includes enough context to search on in Log Viewer — no bare
  `Log::error('failed')` without an identifier.

Work only on this task.
## Phase 13 Task 3: Scheduler heartbeat + optional external dead-man's-switch

### Goal
Track whether the scheduler and queue are actually running, and optionally ping an external monitor that can
detect the one failure mode nothing internal can: cron dying completely.

### Before starting
- Query graphify: `graphify query "scheduler"`, `graphify query "Setting model"`.
- Read `prompts/main-addendum-4.md` §2.10 "The blind spot, stated plainly" before writing this task — the
  external ping is optional and must default to a no-op, not a required dependency.

### What to Build
1. Migration + a single-purpose `scheduler_heartbeats` table (or a well-named row in the existing `Setting`/
   cache-like mechanism if one already fits this shape better — check before adding a new table for a single
   timestamp). A scheduled command (`->everyMinute()`, added to the existing scheduler) updates
   `last_ran_at = now()` every run.
2. A `QueueHealth` read-only query object/Action: pending job count and oldest-pending-job age from the
   existing `jobs` table, failed job count from `failed_jobs` — Laravel's own tables, nothing new to migrate.
3. Optional external ping: `HEALTHCHECK_PING_URL` config (nullable, unset by default). If set, the same
   heartbeat command fires an outbound `Http::get($url)` **after** updating `last_ran_at`, wrapped so a
   failed ping never breaks the heartbeat write itself (catch and log via the Task 2 conventions, don't
   throw). Document in a code comment that this is the only mechanism in the system that can detect total
   cron failure, and why.
4. `Setting` entries: heartbeat staleness threshold (default 5 minutes, accounting for cron jitter).

### Tests (Pest, required)
- The heartbeat command updates `last_ran_at` on each run (`travel()` across two runs, assert the timestamp
  advances).
- `QueueHealth` correctly reports pending count, oldest-pending age, and failed count against seeded `jobs`/
  `failed_jobs` rows.
- With `HEALTHCHECK_PING_URL` unset, the command runs with no outbound HTTP call (`Http::assertNothingSent()`
  or equivalent).
- With it set, a ping fires (`Http::fake()`); a failed ping (mocked non-2xx or timeout) does not prevent the
  heartbeat row from updating and does not throw.

### Explicitly Out of Scope
- No System Health page yet (Task 4) — this task only builds the data sources.
- No alerting yet (Task 5).

### Code Rules
- Follow `CLAUDE.md`. The external ping is genuinely optional — a fresh install with no
  `HEALTHCHECK_PING_URL` set must behave identically to one where the feature doesn't exist.

Work only on this task.
## Phase 13 Task 4: System Health admin page

### Goal
One Inertia admin page that answers "is everything okay?" at a glance, independent of whether Telescope's
data happens to still be around — and links out to Telescope and Log Viewer for anyone who needs to dig in.

### Before starting
- Query graphify: `graphify query "admin panel layout"`, `graphify query "QueueHealth"`,
  `graphify query "MessengerPlatform"`.
- Read `prompts/main-addendum-4.md` §2.10 and §3.9 — the external-provider counters are deliberately a
  separate, always-available data source from Telescope, not a read of Telescope's tables.

### What to Build
1. Migration + `ExternalCallStat` (or similarly named) table: `(provider, date, outcome, count)` —
   `provider` covers `telegram`, `bale`, `telegram_stars`, `bale_pay`, and the AI provider(s) in use;
   `outcome` is `success`/`failure`. Increment it from the actual call sites (`MessengerPlatform`
   implementations, payment gateways, `ReviewProofWithAi`) — a small, cheap counter, not a full log.
2. Admin page `/admin/system-health` (Inertia + React/TS, matching the existing admin panel's conventions):
   - Scheduler heartbeat: last-run time, green/red against the `Setting` staleness threshold (Task 3).
   - Queue: pending count, oldest-pending age, failed job count — with **retry** and **discard** actions per
     failed job (or bulk), calling into Laravel's existing failed-job retry/forget mechanisms.
   - Recent exceptions: read from Telescope's `exceptions` table if Telescope is enabled and has data; show a
     clear "Telescope is disabled/pruned, no data" state rather than an empty table that looks broken.
   - External provider health: success/failure counts per provider over a selectable rolling window (today,
     7 days), from `ExternalCallStat` — this one always has data regardless of Telescope's state.
   - Links to `/telescope` and the Log Viewer route for deeper investigation.
3. Auto-refresh or a manual refresh action — this page is meant to be glanced at, so don't require a full
   page reload to see current numbers.

### Tests (Pest, required)
- Non-admin access rejected.
- Page correctly reflects a stale heartbeat as unhealthy and a fresh one as healthy.
- Failed-job retry action actually re-queues the job; discard action removes it from `failed_jobs`.
- `ExternalCallStat` counts increment correctly on a simulated success and a simulated failure for at least
  one provider (mirror the pattern for the others in the same test file via a dataset).
- The exceptions panel degrades gracefully (clear empty state, not an error) when Telescope has no data.

### Explicitly Out of Scope
- No alerting (Task 5) — this page is pull, not push.
- No historical charting/graphs beyond the rolling-window counts — a future enhancement, not required here.

### Code Rules
- Follow `CLAUDE.md` and the existing admin panel's component/styling conventions — this page should look
  like it belongs, not like a bolted-on debugging tool.
- RTL and i18n apply here same as the rest of the admin panel.

Work only on this task.
## Phase 13 Task 5: Critical alerts via the existing bot

### Goal
DM a configured "ops chat" through the existing bot when something needs a human immediately — a job
exhausting its retries, or an exception rate spike — without inventing a new notification channel.

### Before starting
- Query graphify: `graphify query "MessengerPlatform"`, `graphify query "Setting model"`.
- Read `prompts/main-addendum-4.md` §2.10 — alerts route through `MessengerPlatform` (Phase 11), reusing
  infrastructure rather than adding a service.

### What to Build
1. `Setting` entries: an ops chat `platform` + `chat_id` (nullable — alerting is off until configured), and
   an alerting kill switch (`alerts_enabled`, default `false` until deliberately turned on).
2. Listener on Laravel's `JobFailed` event (fired once a job exhausts its configured retries): sends an alert
   via `MessengerPlatform` naming the job class, the failure reason, and a link to the admin System Health
   page's failed-jobs view.
3. Exception-rate alerting: hook into the existing exception handler (or a Telescope/log-based signal — pick
   whichever is more reliable given Task 1/2's data, and say which in `prompts/progress.md`). Debounce hard:
   at most one alert per distinct exception class per `Setting`-backed cooldown window (default 15 minutes)
   — a burst of the same error must not become a burst of messages.
4. Heartbeat-stale alert: reads Task 3's heartbeat table; if stale beyond threshold, alert — with the same
   caveat as §2.10: this only helps if at least one cron entry is still alive to run the check.
5. Every alert path respects `alerts_enabled` and no-ops silently (not erroring) if the ops chat isn't
   configured — this feature must be fully optional, same discipline as Task 3's external ping.

### Tests (Pest, required)
- A `JobFailed` event fires an alert via `MessengerPlatform` (assert the send call, don't hit real Telegram/
  Bale — `Http::fake()`).
- Two exceptions of the same class within the cooldown window produce exactly one alert; one after the
  cooldown produces a second.
- With `alerts_enabled = false` or no ops chat configured, no alert is sent under any trigger — assert this
  explicitly for each trigger type, not just generally.
- Stale-heartbeat alert fires once heartbeat age exceeds the threshold, not before.

### Explicitly Out of Scope
- No alert channel beyond the bot (no email/SMS) — if that's wanted later, it's a new task, not a silent
  scope-add here.
- No alert history/audit page — the System Health page (Task 4) already shows the underlying data; this task
  only adds the push notification on top of it.

### Code Rules
- Follow `CLAUDE.md`. Alert message strings go through i18n. Reuse `MessengerPlatform` — do not call a
  provider's HTTP API directly from this task's code.

Work only on this task.
