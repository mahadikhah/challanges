# Main Prompt Addendum 4 — Observability

Append after `prompts/main-addendum-3.md`. §2.10 for spec, §3.9 for architecture, §5 gets Phase 13, §7 gets
new open questions. Assumes Phases 1–12 exist as built.

---

## 2.10 Observability

One story that works identically local / VPS / shared cPanel, because it's built entirely on MySQL and the
filesystem — no Redis, no Supervisor, no Docker requirement.

- **Laravel Telescope** — requests, queries, jobs, failed jobs, exceptions, outgoing HTTP client calls
  (Telegram/Bale/AI-provider/payment calls all become inspectable), mail, notifications, schedule runs. Its
  storage driver is the database, same as everything else in this project.
- **`opcodesio/log-viewer`** — a browser UI over the plain log files in `storage/logs`. Deliberately
  redundant with Telescope: if the database is the thing that's broken, Telescope can't help you, but the
  log files still can.
- **A "System Health" admin page** — the actual day-to-day answer to "what's going on," built for a glance
  rather than a deep dive: scheduler heartbeat freshness, queue backlog + failed jobs with retry, recent
  exceptions, and per-external-provider success/failure counts. Links out to Telescope and Log Viewer for
  when the glance isn't enough.
- **Critical alerts through the existing bot** — no new channel, no new service; a queued job exhausting its
  retries or an exception rate spike DMs a configured ops chat via `MessengerPlatform`.

### The blind spot, stated plainly

Nothing that runs *as* a scheduled command can detect the scheduler itself dying — if cron stops firing
entirely (a real shared-hosting failure mode: hosts occasionally wipe crontabs), every internal heartbeat
mechanism goes silent right along with it, because the code that would report the problem never runs. The
only real answer is a ping *out* to something external that alerts on absence, not presence. This is
optional and off by default (`HEALTHCHECK_PING_URL` unset = no-op) — turning it on means depending on a
third party (or self-hosting the same open-source service), and that's your call, not a default.

### Telescope in production, specifically

Telescope's default behavior — record everything — writes a row per request/query on a host with no Redis to
absorb the volume. Production therefore runs `Telescope::filter()` to record only: exceptions, failed
requests (4xx/5xx), slow queries (over a `Setting`-backed threshold), and failed jobs. Full unfiltered
capture is a **local-only** default. Pruning (`telescope:prune`) runs on the existing scheduler.

### Redaction

Telescope's request/HTTP-client watchers will otherwise capture webhook secrets, payment provider tokens,
and Authorization headers verbatim, and — worse for storage — the raw bytes of every submitted image/voice
proof. Configure `hideRequestParameters`/`hideRequestHeaders` for the former; explicitly truncate or exclude
binary media bodies from watcher entries for the latter. Proof media already has its own storage and access
control (Domain Task 6 / Phase 9) — it should not also end up duplicated, unredacted, inside Telescope.

### Access control

Both Telescope's UI and Log Viewer's UI gate through the **same** "is admin" check the Inertia admin panel
already uses — not a separate credential, not left on a package default that might be env-based or
unrestricted.

---

## 3.9 Architecture note

This phase adds no new domain logic — it's entirely instrumentation and read paths over what already exists.
The one piece of new *write* path is the external-provider counter table (Task 4) and the heartbeat table
(Task 3), both intentionally simple and independent of Telescope so the System Health page still works if
Telescope is pruned, disabled, or its data is stale.

## 5. Roadmap — Phase 13

**Phase 13 — Observability.** Task 1: Telescope (environment-aware filtering, redaction, auth). Task 2:
Log Viewer + a pass over existing Actions to add the logging conventions that were missing. Task 3: scheduler
heartbeat + optional external dead-man's-switch ping. Task 4: the System Health admin page. Task 5: critical
alerts via the existing bot.

## 7. New open product questions

1. **Slow-query threshold, exception-alert threshold, heartbeat staleness threshold** — all ship as
   `Setting`-backed values with reasonable defaults (see task files), not hardcoded, since the right numbers
   depend on real traffic you don't have yet.
2. **External dead-man's-switch service** — optional; decide later whether to use a third party or
   self-host. Not blocking.
