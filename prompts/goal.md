# Project Goal — Autonomous Build Driver

Build the entire Telegram Challenges Platform (described in `CLAUDE.md` + `prompts/main.md`) task by task through all phases. **Do not stop until complete and `sail composer ci:check` is green.**

## Definition of Done
- Every phase from `prompts/main.md` §5 implemented (Setup → Domain → Bot → Payments → Mini App → Admin → Website).
- `sail composer ci:check` passes (Pint, PHPStan, Pest, ESLint, Prettier, tsc).
- Automated tests cover idempotency, coin‑concurrency, initData rejection (per §6).
- `prompts/progress.md` shows all tasks done, assumptions recorded.

## Operating Loop (repeat until done)
1. **Orient.** Read `CLAUDE.md`, `prompts/main.md`, `prompts/progress.md`; `git log -20`. Find next unfinished task (first run = `task‑01‑setup.md`; create progress if absent).
2. **Query graphify** for existing patterns before new code.
3. **Spec** the task briefly (per §8 template) – small, reviewable diff.
4. **Implement** using conventions: thin controllers → Action classes, Form Requests, backed enums, `CoinLedger` with `lockForUpdate()` + idempotency key, UTC storage + challenge‑timezone period math, all surfaces share same Actions.
5. **Test.** Write Pest tests for the feature, run focused subset, then full `ci:check`. Fix until green.
6. **Refresh graph:** `graphify update .`.
7. **Commit** with Conventional Commit message (one per green task).
8. **Record** in `prompts/progress.md`: task, decisions, assumptions, next.
9. Go to step 1.

## Build Order (strict)
Setup → Domain core → Bot core → Payments → Mini App → Admin → Website. **Domain core (pure Actions, unit‑tested) comes before surfaces.** Respect dependencies.

## Baked‑in Decisions — DO NOT ASK
- **Completion reward:** flat coin reward, admin‑configurable `Setting`; not scaled by challenge length.
- **Proof visibility:** private by default; creator toggle for `image_approval` only.
- **Repeat failures:** no auto‑removal. Miss + no freeze → streak to 0, participant stays. Add `streak_resets_count` to `ChallengeParticipant` now, but implement no removal yet.
- **Queue / hosting:** assume shared hosting. Reminders via scheduled command + `queue:work --stop-when-empty --max-time=55` every minute. Fan‑out with `delay()`; `ReminderDispatch` idempotent on `(participant, period, kind)`.

## When you MAY stop and ask (only these)
- A **new** money/authorization/security issue not answered by `CLAUDE.md`. The answered ones (initData HMAC, Sanctum bearer, coin locks, idempotency, never trust client ids) are **settled** — implement, don't re‑ask.
- A required secret blocks progress and cannot be stubbed. Tests always fake Bot API; live tokens are manual acceptance steps – note in progress, don't block.
- External dependency cannot resolve. If `irazasyed/telegram-bot-sdk` fails with Laravel 13, fall back to Laravel HTTP client (sendMessage, editMessageText, answerCallbackQuery, getChatMember, createInvoiceLink, answerPreCheckoutQuery, refundStarPayment, getFile) and continue.

Otherwise: choose reasonable default, **record in `progress.md`, keep going.**

## Guardrails
- Hard nos: no Redis, no Filament, no production long‑polling, never hit real Telegram in tests.
- Mini App = standalone React SPA (Vite); Admin + Website = Inertia. Keep bundles separate.
- Admin/Website: support dark/light (`useAppearance()`) and RTL; all strings through i18n (Farsi + English).
- Keep diffs reviewable, commits frequent. If context runs low, commit + updated progress so next resume picks up cleanly.

## Running across resets
State lives in git + `prompts/progress.md`. Paste this prompt again to resume, or run with `/loop 15m prompts/goal.md` to auto‑re‑invoke. The ledger makes every resume seamless.

## Phase order override (user instruction, 2026-08-29)
Phases 12 (logging/observation) and 13 (README/documentation) are **deferred**: when Phase 11 finishes,
proceed directly to `prompts/phase-14.md`, then `prompts/phase-15.md`, and only then return to
`prompts/phase-12.md` and `prompts/phase-13.md`.
