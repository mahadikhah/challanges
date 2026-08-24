# CLAUDE.md — Telegram Challenges Platform

Stable operating contract for this repo. Read before every task. The fuller product spec, rationale, and
task roadmap live in `prompts/main.md`.

## Project Context (stable)

Telegram accountability-challenge platform: users create challenges, invite friends, check in every period,
and keep streaks. Four surfaces, **one Laravel app, one repo**.

- **Laravel 13** (`^13.17`, PHP **8.3+**), running inside **Laravel Sail**. All artisan/npm/composer commands
  run via `sail`, e.g. `sail artisan make:model`, `sail npm run dev`, `sail composer test`.
- **Database: MySQL 8.4.** **No Redis** — the production host does not provide it. Queue, cache and session all
  use the `database` driver. Never introduce a Redis-only dependency (Horizon, Redis locks, Reverb by default).
  Node *is* available on the host if a long-running process ever becomes unavoidable.
- **Frontend (admin + website):** Inertia **v3** + React **19** + **TypeScript** + Tailwind **v4** +
  shadcn/ui (`new-york` style, base `neutral`, lucide icons).
- **Frontend (Telegram Mini App):** standalone React SPA, its own Vite entry, consuming a versioned JSON API.
  Deliberately **not** Inertia — see Surfaces.
- **Bot:** `irazasyed/telegram-bot-sdk` **`^3.16`** (v3.16.0 is the first release allowing
  `illuminate/support: 9 - 13`; do not downgrade below it). Webhook-driven — **never long-polling in production**.
- **Admin panel:** Inertia + React + TS. **No Filament** — do not install or suggest it.
- **Auth:** Laravel **Fortify** (session) for web/admin. Mini App authenticates via Telegram `initData` →
  short-lived Sanctum bearer token.
- **Route helpers:** **Laravel Wayfinder** generates TS helpers into `resources/js/actions/**` and
  `resources/js/routes/**`. Import from there; never hardcode URL strings in admin/website TSX. Regenerate with
  `sail artisan wayfinder:generate`.
- **Architecture:** Action classes, Form Requests, thin controllers, full SOLID principles.
- **Quality gate:** `sail composer test` = `config:clear` + `pint --test` + `phpstan analyse` + `artisan test`.
  `sail composer ci:check` adds `npm run lint:check`, `format:check`, `types:check`. **All must pass before a
  task is done.**
- **Codebase navigation:** this project uses **graphify** for a queryable knowledge graph of the codebase
  (`.graphify/graph.json`). Query it first (`graphify query "..."`, `graphify explain "<ClassName>"`,
  `graphify path "A" "B"`) before reading files directly. Run `/graphify . --update` if it looks stale for the
  area you're working in.

### Already scaffolded — reuse, do not rebuild

From `laravel/react-starter-kit`: Fortify auth pages (login/register/forgot/reset/verify/confirm), settings
pages (profile/security/appearance), `resources/js/app.tsx` with a page-name→layout resolver, ~25 shadcn/ui
components in `resources/js/components/ui/`, `app-sidebar`/`app-header`/`nav-*`, the `app`/`auth`/`settings`
layouts, `HandleInertiaRequests`, `HandleAppearance`, and **`hooks/use-appearance.tsx`** (complete
dark/light/system theming with cookie + localStorage and a pre-hydration script in `app.blade.php`).

**Known gaps that are net-new work, not config flags:** no i18n or `t()` helper anywhere on the frontend; **no
RTL handling at all** (required for Farsi); only the `User` model exists; no `routes/api.php`.

## Surfaces & Auth (stable)

| Surface | Stack | Auth | Routes |
|---|---|---|---|
| Telegram bot | webhook controller + SDK | URL secret + `X-Telegram-Bot-Api-Secret-Token` header | `routes/telegram.php` |
| Mini App | standalone React SPA (`resources/js/miniapp/`), one Blade shell + catch-all | `initData` verify → bearer token | `routes/api.php` → `/api/v1/miniapp/*` |
| Admin panel | Inertia + React + TS | Fortify session + admin ability | `routes/admin.php` |
| Public website | Inertia (public pages) | none | `routes/web.php` |

**Why the Mini App is not Inertia — settled, do not revisit.** Telegram's own docs state that to validate a
Mini App user you "send the data from the `Telegram.WebApp.initData` field to the bot's backend" — i.e. the
identity arrives only *after* the page loads, pushed up by client JS. It is also mirrored in the launch URL's
**hash fragment**, which is never transmitted in an HTTP request. So Laravel cannot know who the user is on the
first page load, which is precisely what Inertia needs in order to render props server-side. An Inertia Mini
App would render an empty shell → run JS → POST initData → refetch: an SPA+API flow with extra latency and a
flash of empty state. Webview cookie persistence is unreliable too. Hence SPA + bearer token.

Both frontends share `resources/js/lib`, `resources/js/types` and pure UI primitives, but build as **separate
Vite entries** so the desktop admin bundle never loads inside the mobile Mini App.

## Product Rules (stable)

**Access gate.** Every user must join the announcement channel before using the bot. Verify with
`getChatMember` on `/start`, block with a join button until confirmed, and re-verify on privileged actions.

**Economy — coins are the single currency.**
- Free baseline: **1 challenge created + 1 challenge joined** per user.
- Coins are credited from: Telegram Stars purchases, credited invites, challenge-completion rewards, admin
  adjustments.
- Coins are spent on: extra create-slots, extra join-slots, extra freezes.
- **Every rate and price is admin-configurable. Never hardcode one.**
- An invite credits coins **only if the invited user is brand-new to the bot** (first-ever `/start`, no prior
  user row).
- **No buy-in / prize-pool mechanics.** Stars are for digital goods and services only; wagering risks the bot.
  Completion rewards are flat and funded by the platform.

**Challenges.**
- `period_type`: `daily | weekly | monthly | seasonal | yearly | custom` (custom = N days).
- Creator sets start date, total periods and timezone. **One shared fixed timeline for all participants** —
  late joiners catch up rather than getting a personal clock.
- Late joiners are **not** penalised for periods before they joined; obligations begin at their join period.
- `visibility`: `public | invite_only`. Public challenges are auto-posted to the announcement channel.
- `proof_type`, chosen by the creator:
  - `button` — one tap, auto-approved.
  - `text_autogen` — the system generates a unique, meaningful phrase **per participant per period**; the
    participant types it; auto-approved on normalised exact match. Per-participant, **not** per-period, so
    participants cannot paste the phrase to each other and defeat the mechanic.
  - `image_approval` — participant uploads a photo; the creator approves or rejects.
- `proof_is_public`: creator toggle, **default private**.
- **Freeze** skips one missed period penalty-free.
- **Miss with no freeze left → streak resets to 0, participant stays in the challenge.** They may have spent a
  friend's invite on that slot; don't burn it. A stricter "N resets → auto-remove" rule can be layered later as
  a counter check without a schema change.
- Reminders are sent every period.

**Platform.** Multi-language from day one (Farsi + English minimum), locale stored per user. **RTL support is
required.**

## Core Domain Models (living list)

- `User` — **the only model that exists today** (Fortify, PHP-attribute configured). Extend with `telegram_id`
  (unique), `telegram_username`, `first_name`, `language_code`, `locale`, `referred_by_user_id`,
  `channel_verified_at`, `is_admin`. Admin login stays email+password via Fortify; bot users are
  Telegram-identity-only. **Don't conflate the two auth paths.**
- `Challenge` — creator, title, description, `period_type`, `custom_period_days`, `starts_at`, `total_periods`,
  `timezone`, `visibility`, `proof_type`, `proof_is_public`, `default_freezes`, `status`, `announced_at`.
- `ChallengePeriod` — challenge, `index`, `starts_at`, `ends_at`. Materialised so reminders and rollover are
  queryable and idempotent rather than recomputed ad hoc.
- `ChallengeParticipant` — challenge, user, `joined_at`, `joined_period_index`, `status`, `current_streak`,
  `longest_streak`, `freezes_total`, `freezes_used`.
- `CheckIn` — participant, challenge_period, `status` (`pending|submitted|approved|rejected|missed|frozen`),
  `expected_phrase`, `submitted_text`, `proof_path`, `submitted_at`, `reviewed_by`, `reviewed_at`.
  **Unique on (participant, challenge_period).**
- `Invite` — inviter, `code` (unique), `invited_user_id`, `credited_at`, `status`.
- `CoinTransaction` — the ledger. user, signed `amount`, `reason`, `balance_after`, morph `reference`,
  `idempotency_key` (unique). Balance is derived from and reconciled against this table — **never** a bare
  mutable integer updated in isolation.
- `Entitlement` — user, `type` (`create_slot|join_slot`), `source`, `consumed_at`, `challenge_id`.
- `StarPayment` — user, `telegram_payment_charge_id` (**unique**), `invoice_payload`, `stars_amount`,
  `coin_amount`, `status`, raw payload.
- `TelegramUpdate` — `update_id` (**unique**), payload, `processed_at`. Webhook idempotency.
- `BotConversation` — user, `state`, `payload` JSON, `expires_at`. The SDK has **no FSM/conversation support**,
  so multi-step wizards (create-challenge: title → period → start → proof type → visibility) need explicit
  server-side state.
- `ReminderDispatch` — participant, period, `kind`, `scheduled_for`, `sent_at`. **Unique on
  (participant, period, kind)** so re-runs cannot double-send.
- `Setting` — admin-tunable key/value: invite→coin rate, slot and freeze prices, Stars packages, required
  channel, default freezes, token TTLs.

Use PHP **backed enums** for every status and type. No magic strings.

## Engineering Conventions (stable)

- Controllers stay thin → delegate to Action classes. Form Requests for all input validation. DB transactions
  for multi-write operations.
- **Every surface calls the same Actions.** Check-in from the bot, the Mini App and the admin panel must all
  route through one `SubmitCheckIn` action — never three copies of the rule.
- **Bot webhook:** record the update (idempotent on `update_id`), return **200 immediately**, then dispatch a
  queued job to process it. Telegram retries non-2xx and will hammer a slow endpoint.
- **Idempotency is a hard requirement, not a nicety:** `update_id` for updates,
  `telegram_payment_charge_id` for payments, unique `(participant, period)` for check-ins, unique
  `(participant, period, kind)` for reminders. Period rollover and reminder dispatch must be safe to re-run.
- **Coins:** every mutation goes through one `CoinLedger` service, inside a transaction, with `lockForUpdate()`
  on the user row and a required `idempotency_key`. Never read-then-write a balance without the lock. Enforce
  this even where it feels like overkill — this is real money.
- **Time:** store UTC. Period boundaries are computed in the **challenge's** timezone, and reminder scheduling
  must respect it. Never assume the server timezone.
- **Telegram rate limits** (~1 msg/sec per chat, ~30/sec global): reminder fan-out must be staggered at
  dispatch (`delay()`), never blasted. With the `database` queue and no Redis, keep worker concurrency modest
  and space sends deliberately.
- All new admin/website pages support dark/light (`useAppearance()`, already present) **and RTL**.
- Reuse existing packages/components/patterns before building new ones — say explicitly if something isn't
  cleanly reusable rather than silently duplicating it.
- Use Wayfinder helpers for routes in admin/website TSX; the Mini App SPA uses a typed API client instead.
- For anything security- or authorization-sensitive: confirm the intended design explicitly before building,
  and re-validate authorization on **every** request, not just at an initial action. **Never trust a
  client-supplied `challenge_id`, `participant_id` or Telegram id** — always re-resolve the actor server-side
  from the verified token and re-check ownership.
- State reasoning explicitly on genuine judgment calls rather than silently picking one approach.
- Don't introduce a new testing framework, dependency or UI library without flagging it back first.
- **Hard nos:** no Redis, no Filament, no production long-polling.

## Telegram Integration Rules (verified against core.telegram.org)

**`initData` validation — the argument order is the classic bug. Get it exactly right:**

```
secret_key       = HMAC_SHA256(<bot_token>, "WebAppData")   // token is the MESSAGE, "WebAppData" is the KEY
data_check_string = all received fields EXCEPT `hash` (and `signature`),
                    sorted alphabetically, formatted `key=<value>`, joined with \n (0x0A)
valid            = hex(HMAC_SHA256(data_check_string, secret_key)) === hash
```

- Compare with a **timing-safe** comparison (`hash_equals`), never `==`.
- Additionally validate `auth_date` (Unix timestamp) to reject outdated data. Telegram gives no fixed TTL;
  use a short configurable window for the token exchange and rely on our own token lifetime thereafter.
- Treat `initDataUnsafe` as untrusted display data only.
- Optional third-party path (not needed for us — we hold the bot token): a base64url **Ed25519** `signature`
  over a data-check-string that *prepends* `<bot_id>:WebAppData\n`, verified with Telegram's public key
  (production `e7bf03a2fa4602af4580703d88dda5bb59f32ed8b02a56c187fe7d34caed242d`, test
  `40055058a4ee38156a06562e52eece92a771bcd8346a8c4615cb7376eddf72ec`).

**Deep links / invites.** `https://t.me/<bot>/<app>?startapp=<code>` delivers the code to the Mini App both in
`start_param` inside initData **and** as the `tgWebAppStartParam` GET parameter — the GET parameter *is*
server-visible, unlike initData. Use `https://t.me/<bot>?start=<code>` for bot-side invite attribution.

**Telegram Stars.** Currency tag is **`XTR`**; pass an **empty string** as `provider_token`. Digital goods and
services only. Flow: `createInvoiceLink`/`sendInvoice` → `pre_checkout_query` (must be answered promptly with
`answerPreCheckoutQuery`) → `successful_payment` carrying `telegram_payment_charge_id`, which is the
idempotency key for crediting coins. Refunds via `refundStarPayment`.

## Testing Requirements (stable)

- **Pest** syntax exclusively. The starter kit's existing class-based PHPUnit auth tests may be migrated
  opportunistically, not in bulk.
- Every task ships with tests for its new functionality.
- Prioritise meaningful coverage on business logic — Actions, the coin ledger, period/streak/freeze
  calculation, invite crediting, `initData` verification, webhook idempotency, Stars payment handling — over
  trivial smoke tests.
- `sail artisan test` / `sail artisan test --coverage`. `expect()` syntax, datasets for repetitive cases (the
  six period types are a natural dataset), descriptive `it()`/`test()` names.
- Always `Http::fake()` the Bot API. **Never hit real Telegram endpoints in tests.**
- No JS/React test framework established — do not introduce one unprompted.

## Task Format

Goal → Before starting (when relevant) → What to Build → Tests (Pest, required) → Explicitly Out of Scope →
Code Rules → closing "Work only on this task" line. Tasks are numbered per area: **"Setup Task N"**,
**"Domain Task N"**, **"Bot Core Task N"**, **"Payments Task N"**, **"Mini App Task N"**, **"Admin Task N"**,
**"Website Task N"**.

## graphify

This project has a knowledge graph at graphify-out/ with god nodes, community structure, and cross-file relationships.

Rules:
- For codebase questions, first run `graphify query "<question>"` when graphify-out/graph.json exists. Use `graphify path "<A>" "<B>"` for relationships and `graphify explain "<concept>"` for focused concepts. These return a scoped subgraph, usually much smaller than GRAPH_REPORT.md or raw grep output.
- If graphify-out/wiki/index.md exists, use it for broad navigation instead of raw source browsing.
- Read graphify-out/GRAPH_REPORT.md only for broad architecture review or when query/path/explain do not surface enough context.
- After modifying code, run `graphify update .` to keep the graph current (AST-only, no API cost).
