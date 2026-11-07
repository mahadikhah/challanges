# Project Goal — Phase 13 (Observability)

Continuation of the earlier goal files. Same repo, same conventions, same `CLAUDE.md`. Drives Phase 13 only.

## Before the first run
Check `prompts/progress.md`. If Phases 1–12 are not all ✅, finish those first with the earlier goal files —
Task 4 in particular reads from the admin panel (Phase 6) and `MessengerPlatform` (Phase 11), and Task 5
depends on both those and the payment/AI-provider call sites from Phases 4, 10, and 11.

## North Star (definition of done)
1. Telescope is installed, environment-filtered, redacted, and gated behind admin auth.
2. Log Viewer is installed and gated the same way; existing Actions across the codebase have the logging
   conventions from Task 2 applied, not just new code going forward.
3. Scheduler/queue heartbeat data exists and the optional external ping is wired but off by default.
4. The System Health admin page shows heartbeat, queue/failed-jobs (with working retry/discard), recent
   exceptions (degrading gracefully if Telescope has none), and per-provider success/failure counts.
5. Critical alerts fire through the existing bot for failed jobs and exception spikes, debounced, fully
   optional via `Setting`, defaulting off.
6. `sail composer ci:check` passes wholesale, including everything from Phases 1–12 unchanged.
7. `prompts/progress.md` updated with Phase 13, including which exception-alerting data source was chosen in
   Task 5 and why.

## Operating loop
Same as the earlier goal files — orient from `progress.md` and `git log`, query graphify before writing new
code, spec from the task file, implement per `CLAUDE.md`, test with Pest, refresh graphify, commit per green
task, record in `progress.md`, repeat.

## Build order (strict)
Task 1 (Telescope) → Task 2 (Log Viewer + logging pass) → Task 3 (heartbeat/dead-man's-switch) → Task 4
(System Health page, depends on 1–3's data sources) → Task 5 (alerts, depends on 3 and on Task 4's data
being correct). Don't reorder — each task after the first depends on data the previous one produces.

## Baked-in decisions — do NOT stop to ask about these
- **Production Telescope is filtered** (exceptions, 4xx/5xx, slow queries, failed jobs only) — full capture
  is local-only.
- **The external dead-man's-switch ping defaults to off** (`HEALTHCHECK_PING_URL` unset). Don't require it,
  don't pick a service on the user's behalf.
- **Alerting defaults to off** (`alerts_enabled = false`) even once configured — a deliberate opt-in, not
  automatic once a chat is set.
- **`ExternalCallStat` is the System Health page's source for provider health, not Telescope** — Telescope
  data can be pruned or disabled; the counter table can't be.
- **Both Telescope and Log Viewer are gated by the same admin-auth check the Inertia admin panel already
  uses** — never a separate credential, never left on a package's default auth.

## When you MAY stop and ask (only these)
- A new money/authorization/security question genuinely not covered by `CLAUDE.md` or the addenda.
- A package (Telescope, Log Viewer) turns out not to actually support the installed Laravel version despite
  the addendum's expectation — note it in `progress.md`, find the compatible version or a documented
  alternative, and continue; don't stop and wait.
- Everything else: choose the reasonable default, write it down, keep going.

## Guardrails
- No Redis, no new external services required by default — everything in this phase works with just MySQL
  and the filesystem unless the user later opts into the external ping.
- Never hit a real external provider in tests — `Http::fake()` throughout, including the healthcheck ping.
- If context runs low mid-task, commit + update `progress.md` before stopping — resume by pasting this file
  again.
