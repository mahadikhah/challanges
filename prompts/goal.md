# Project Goal — Autonomous Build Driver

Your job is to build the entire Telegram Challenges
Platform described in `CLAUDE.md` and `prompts/main.md`, working task by task through all seven phases, and
**not to stop until the whole application is complete and `sail composer ci:check` is green across it.**

## North Star (definition of done)
The build is done only when **all** of these hold — verify, don't assume:
1. Every phase in `prompts/main.md` §5 is implemented: Setup → Domain core → Bot core → Stars payments →
   Mini App → Admin panel → Website.
2. `sail composer ci:check` passes wholesale (Pint, PHPStan, Pest, ESLint, Prettier, `tsc --noEmit`).
3. The verification strategy in `prompts/main.md` §6 is covered by automated tests — especially the
   idempotency, coin-concurrency, and `initData`-rejection checks.
4. `prompts/progress.md` shows every task done, with any assumptions recorded.
Only then stop and report a summary.

## Operating loop — repeat until done
1. **Orient.** Read `CLAUDE.md`, `prompts/main.md`, and `prompts/progress.md`. Run `git log --oneline -20`.
   Determine the next unfinished task from the roadmap. (On the very first run, that is
   `prompts/task-01-setup.md`; create `prompts/progress.md` if it doesn't exist.)
2. **Query graphify** for existing patterns before writing new code (`graphify query "..."`).
3. **Spec the task** briefly using the template in `prompts/main.md` §8 — keep each task small enough that its
   diff is reviewable.
4. **Implement** per `CLAUDE.md` conventions: thin controllers → Action classes, Form Requests, backed enums,
   `CoinLedger` with `lockForUpdate()` + idempotency key, UTC storage with challenge-timezone period math,
   every surface routing through the **same** Actions.
5. **Test.** Write Pest tests; run the focused subset (`sail artisan test --filter=...`), then the whole gate
   `sail composer ci:check`. Fix until green — a task is not done until it is green.
6. **Refresh graph:** `graphify update .`.
7. **Commit** on the working branch with a clear message (Conventional Commits). One commit per green task.
8. **Record** in `prompts/progress.md`: the task, key decisions, any assumption you made, and what's next.
9. Go to step 1.

## Build order (strict)
Setup → Domain core → Bot core → Payments → Mini App → Admin → Website. **Domain core (pure Actions, fully
unit-tested) comes before any surface.** Within a phase, respect dependencies.

## Baked-in decisions — do NOT stop to ask about these
- **Completion reward:** flat coin reward, funded by the platform, stored as an admin-configurable `Setting`;
  it does **not** scale with challenge length.
- **Proof visibility:** private by default; a creator toggle can make it public; a public feed is only built
  for `image_approval` challenges.
- **Repeat failures:** no auto-removal. A miss with no freeze resets the streak to 0 and the participant stays.
  Add a `streak_resets_count` column to `ChallengeParticipant` now so a future "N resets → remove" rule needs
  no migration — but implement **no** removal behaviour yet.
- **Queue worker / hosting:** assume shared hosting. Reminders run via a scheduled command and the queue is
  drained by cron every minute: `artisan queue:work --stop-when-empty --max-time=55`. Design reminder fan-out
  to tolerate bounded throughput — stagger sends with `delay()`, and make `ReminderDispatch` idempotent on
  `(participant, period, kind)` so re-runs never double-send.

## When you MAY stop and ask (only these)
- A **new** money/authorization/security design question that `CLAUDE.md` does not already answer. The ones it
  *does* answer — `initData` HMAC (token is the message, `"WebAppData"` is the key; timing-safe compare),
  Sanctum bearer exchange, coin-ledger locking, idempotency keys, never trusting client-supplied ids — are
  **already decided**: implement them, don't re-ask.
- A required secret genuinely blocks progress and cannot be stubbed. For tests this never happens — always
  `Http::fake()` the Bot API. Live-token / real-device checks are **manual acceptance steps**: list them in
  `prompts/progress.md`, don't block on them.
- An external dependency cannot resolve. If `irazasyed/telegram-bot-sdk` ever fails to satisfy Laravel 13,
  fall back to a thin wrapper over Laravel's HTTP client (`sendMessage`, `editMessageText`,
  `answerCallbackQuery`, `getChatMember`, `createInvoiceLink`, `answerPreCheckoutQuery`, `refundStarPayment`,
  `getFile`) and continue — do not stop.
Otherwise: choose the reasonable default, **write it down in `progress.md`, and keep going.**

## Guardrails
- Hard nos: no Redis, no Filament, no production long-polling, never hit real Telegram in tests.
- The Mini App is a standalone React SPA on its own Vite entry; the admin panel and website are Inertia. Don't
  merge their bundles.
- All new admin/website UI supports dark/light (`useAppearance()`) **and RTL**, and every user-facing string
  goes through the i18n layer (Farsi + English).
- Keep diffs reviewable and commits frequent. If your context is running low, make sure a commit plus an
  updated `prompts/progress.md` capture the state so the next run resumes cleanly from step 1.

## Running me across resets
A single paste runs until this context fills. Because state lives in git + `prompts/progress.md`, simply paste
this prompt again to resume — or run it on an interval with `/loop 15m prompts/goal.md` so it re-invokes
itself automatically. No prompt can literally guarantee zero stops (permission prompts and context limits are
real), but the progress ledger makes every resume seamless.
