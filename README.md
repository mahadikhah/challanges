# Challenges — Telegram & Bale Accountability Platform

> **فارسی:** این سند به فارسی نیز موجود است — [README.fa.md](README.fa.md)

Challenges is an accountability platform delivered as a Telegram and Bale bot with a Mini App: a creator
designs a repeating challenge — "read twenty pages daily", "work out three times a week", "do 30 push-ups
a day" — invites friends, and everyone checks in every period to keep a streak. Check-ins are proven with
anything from a single button tap to a timed, multi-step session with photo or voice proof at each step,
optionally pre-screened by AI. Missing a period burns a freeze or resets the streak, but never removes the
participant. Around that core sits an invite-driven coin economy: everyone gets one free create-slot and
one free join-slot, earns coins by inviting brand-new users or completing challenges, and spends them on
extra slots and freezes — with Telegram Stars and Bale Pay as the two ways to buy coins directly. Challenge
announcements and daily leaderboards post into group chats the creator owns, not a platform channel.

<!-- screenshot: bot challenge creation wizard -->

## Features

### Challenges & periods

- Six period types: `daily`, `weekly`, `monthly`, `seasonal`, `yearly`, and `custom` (N days).
- One **shared, fixed timeline** for all participants — period boundaries are computed in the challenge's
  own timezone, materialised as `ChallengePeriod` rows so reminders and rollover are queryable and
  idempotent, and a late joiner simply starts owing at their join period without penalty for the past.
- Freezes: each challenge carries a creator-set freeze allowance; a missed period consumes a freeze
  automatically instead of resetting the streak. Out of freezes → streak resets to zero, **participant
  stays in the challenge**.
- Reminders every period, staggered at dispatch to respect messenger rate limits.

### Proof types

- `button` — one tap, auto-approved.
- `text_autogen` — the system issues a unique, meaningful phrase **per participant**; typing it back
  auto-approves on normalised exact match. Per-participant (not per-period) so participants can't paste
  it to each other.
- `image_approval` — participant uploads a photo; the creator (or AI, see below) approves or rejects.
- `voice` — voice-message proof with a per-challenge duration cap.
- **Timed / stepped sessions** — a challenge period can be designed as an ordered list of steps with
  minimum wait times between them (e.g. stretch → wait 5 min → exercise → wait 10 min → cool down). The
  bot walks the participant through start → wait → step → wait → end, collecting proof at each step, and
  the check-in only settles when the full session completes.

<!-- screenshot: timed session step instructions -->

### Scoring

- **Binary scoring** (default): each approved period extends a streak; the leaderboard ranks by current
  streak.
- **Quantity scoring**: the creator sets a target value, a unit label, base points and whether a
  below-target submission counts at all. Approved periods score proportionally
  (`round(value / target × base points)`, uncapped above target); the leaderboard ranks by total score.

### Invites & coin economy

- Free baseline: **1 challenge created + 1 challenge joined** per user; more slots cost coins.
- Coins are credited for inviting **brand-new** users (no prior `/start`), challenge completion, and
  admin adjustments; spent on extra create-slots, join-slots and freezes.
- Every rate and price is admin-configurable — nothing is hardcoded.
- Every coin mutation flows through one `CoinLedger` service inside a transaction with
  `lockForUpdate()` and a required idempotency key; balances are derived from an append-only ledger.
- **No buy-in or prize-pool mechanics** — Stars are for digital goods only; completion rewards are flat
  and platform-funded.

### Payments — Telegram Stars & Bale Pay

- Telegram Stars: invoice link in `XTR` with an empty provider token, `pre_checkout_query` answered
  promptly, coins credited idempotently on `telegram_payment_charge_id`, refunds via `refundStarPayment`.
- Bale Pay: native Iranian rail priced in Rial, invoice + inquiry flow, idempotent crediting on Bale's
  transaction id. **Bale exposes no refund endpoint**, so Bale purchases are watch-only in the admin
  audit view (documented limitation, not an oversight).

### Creator-owned announcement chats

- A creator forwards a message from their own group/channel to link it to their challenge; the platform
  verifies bot membership and admin rights before accepting the link.
- Per-chat toggles: check-in announcements, a daily leaderboard post, and (only when the challenge's
  proofs are public) sharing the approved proof photo itself.

### AI-assisted proof approval

- Creators may hand proof review to AI: the platform generates suggested approval criteria from the
  challenge description, the creator edits or accepts them, and submitted photos are auto-approved or
  auto-rejected against those criteria with a confidence score.
- Every AI decision is auditable; an admin or creator override reverses a verdict and re-settles the
  check-in through the same Action a manual review uses.
- AI usage is budgeted and rate-limited per admin-configured settings; media-type enablement
  (image/voice/video) is admin opt-in, all defaulting off.

### Multi-language & RTL

- Farsi and English out of the box; locale stored per user, every surface (bot, Mini App, admin) honours
  it. Farsi renders right-to-left everywhere, including the Mini App SPA.

### Admin panel

- Tune the whole economy: coin prices, slot and freeze prices, Stars/Rial packages, invite→coin rate,
  default freezes, token TTLs, AI limits and confidence threshold.
- Moderate challenges and users, review the AI-approval audit log and payment/invite audit views, adjust
  coin balances (through the ledger, never by hand-editing).

## Architecture

One Laravel application, **four surfaces**:

| Surface | Stack | Auth |
|---|---|---|
| Bot (Telegram + Bale) | webhook controllers behind a shared messenger-platform abstraction | URL secret (Telegram: + `X-Telegram-Bot-Api-Secret-Token` header; Bale: unguessable URL path — Bale has no webhook secret mechanism) |
| Mini App | standalone React SPA (`resources/js/miniapp/`), own Vite entry, versioned JSON API | Telegram `initData` verify → short-lived Sanctum bearer token |
| Admin panel | Inertia v3 + React 19 + TS | Fortify session + admin ability |
| Public website | Inertia (public pages) | none |

Every surface calls the **same Action classes** — a check-in submitted from the bot, the Mini App or an
admin review flows through one `SubmitCheckIn` pipeline, so the rules can never drift apart.

**MySQL-only, no Redis.** The production host provides neither Redis nor the option to add it, so the
queue, cache and sessions all use the `database` driver. Implications the code already accounts for:
queue workers keep modest concurrency, reminder fan-out is staggered with `delay()` rather than blasted,
and idempotency is enforced with unique DB constraints (`update_id`, `telegram_payment_charge_id`,
`(participant, period)` for check-ins, `(participant, period, kind)` for reminders) rather than
deduplication stores.

## Tech stack

| Layer | Package | Version |
|---|---|---|
| Framework | laravel/framework | ^13.17 (PHP ^8.3) |
| Database | MySQL | 8.4 |
| Bot SDK | irazasyed/telegram-bot-sdk | ^3.16 |
| AI | laravel/ai | ^0.11.0 |
| Auth (web) | laravel/fortify | ^1.37.2 |
| Auth (Mini App API) | laravel/sanctum | ^4.0 |
| Inertia (server) | inertiajs/inertia-laravel | ^3.0 |
| Route helpers | laravel/wayfinder | ^0.1.14 |
| Frontend | react / react-dom | ^19.2.0 |
| | @inertiajs/react | ^3.0.0 |
| | tailwindcss | ^4.0.0 |
| | vite | ^8.0.0 |
| | typescript | ^5.7.2 |
| Testing | pestphp/pest + pest-plugin-laravel | ^4.7 / ^4.1 |
| Static analysis | larastan/larastan (PHPStan level 7) | ^3.9 |
| Style | laravel/pint | ^1.27 |
| Local dev | laravel/sail | ^1.53 |

## Repository structure

```
app/
  Actions/            # All business logic, grouped by area (Challenges, CheckIns, Coins,
                      # Entitlements, Invites, Payments, Reminders, Ai, MiniApp, Telegram)
  Enums/              # Backed enums for every status/type — no magic strings
  Http/Controllers/   # Thin: Admin, MiniApp API, web, webhook
  Jobs/               # Queued sends (announcements, reminders) — re-runnable, claim-guarded
  Models/             # User, Challenge, ChallengePeriod, ChallengeParticipant, CheckIn, Invite,
                      # CoinTransaction, Entitlement, StarPayment, TelegramUpdate, BotConversation,
                      # ReminderDispatch, Setting, ChallengeChat, AiUsageRecord, ...
  Services/           # CoinLedger, Settings, Localization, Ai, Media, Telegram
resources/js/
  pages/              # Inertia admin + website pages
  miniapp/            # Mini App SPA — its own Vite entry, never bundled into the admin build
  components/ui/      # shadcn/ui (new-york) primitives shared by both frontends
routes/
  telegram.php  bale.php  api.php (v1/miniapp)  admin.php  web.php  console.php
lang/                 # en + fa, incl. every enum label
docs/                 # Setup + flow documentation (see links below)
```

## Documentation

- **[docs/user-flows.md](docs/user-flows.md)** — how the platform works for participants, creators and
  admins ([فارسی](docs/user-flows.fa.md))
- **[docs/setup-cpanel.md](docs/setup-cpanel.md)** — deploying to shared hosting / cPanel
  ([فارسی](docs/setup-cpanel.fa.md))
- **[docs/setup-vps.md](docs/setup-vps.md)** — deploying to an Ubuntu VPS ([فارسی](docs/setup-vps.fa.md))

## Environment variables

Generated from `.env.example`:

| Variable | Purpose |
|---|---|
| `APP_NAME` / `APP_ENV` / `APP_KEY` / `APP_DEBUG` / `APP_URL` | Standard Laravel app config |
| `APP_LOCALE` / `APP_FALLBACK_LOCALE` / `APP_FAKER_LOCALE` | Default locale (users' own preference overrides per user) |
| `DB_CONNECTION` / `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | MySQL connection — must be `mysql` in production |
| `SESSION_DRIVER` | **`database`** — no Redis available |
| `QUEUE_CONNECTION` | **`database`** — no Redis available |
| `CACHE_STORE` | **`database`** — no Redis available |
| `TELEGRAM_BOT_TOKEN` | Bot token from BotFather |
| `TELEGRAM_BOT_USERNAME` | Bot username, used for deep links (`t.me/<bot>?start=<code>`) |
| `TELEGRAM_WEBHOOK_SECRET` | Random path secret embedded in the webhook URL |
| `TELEGRAM_WEBHOOK_HEADER_SECRET` | Value checked against `X-Telegram-Bot-Api-Secret-Token` |
| `TELEGRAM_REQUIRED_CHANNEL` | Announcement channel every user must join before using the bot |
| `MINIAPP_URL` | Public Mini App URL (`APP_URL/miniapp` by default) |
| `BALE_BOT_TOKEN` | Bale bot token |
| `BALE_BOT_USERNAME` | Bale bot username |
| `BALE_WEBHOOK_SECRET` | Random path secret for the Bale webhook URL (Bale has no header secret) |
| `BALE_REQUIRED_CHANNEL` | Required Bale channel for the access gate |
| `BALE_PROVIDER_TOKEN` | Bale Pay provider token for Rial payments |
| `VITE_APP_NAME` | App name exposed to the frontends |

Everything else in `.env.example` keeps Laravel defaults. All economy rates, prices, limits and AI
settings live in the `settings` table via the admin panel — never in env.

## Running the project locally

```bash
# Install PHP dependencies, launch Sail (PHP + MySQL containers)
composer install
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate

# Frontend
./vendor/bin/sail npm install
./vendor/bin/sail npm run dev
```

## Testing & quality gate

```bash
./vendor/bin/sail composer test       # config:clear + pint --test + phpstan (level 7) + artisan test
./vendor/bin/sail composer ci:check   # the above + npm lint:check + format:check + types:check
```

The suite is Pest-only, runs against a real MySQL `testing` database (the coin-ledger concurrency tests
need real row locks), fakes every HTTP call to Telegram/Bale/AI providers, and covers the domain Actions,
coin ledger, period/streak/freeze engine, invite crediting, `initData` verification, webhook idempotency,
both payment rails, timed sessions, scoring, and every bot wizard.

## Known limitations & deferred items

Recorded honestly from the build's open-questions log:

- **Cross-platform account linking is deferred** — a Telegram user and a Bale user are separate accounts
  today; coins and slots don't travel between platforms.
- **Bale has no webhook secret mechanism** (verified against docs.bale.ai) — authenticity rests on an
  unguessable URL path secret plus server-side re-verification of every claim in the payload.
- **Bale Pay has no refund endpoint** — Bale purchases can be audited but not refunded from the panel.
- **Voice/video AI approval**: the AI-approval first pass is image-only; voice was deferred. Video
  AI-approval may be unavailable on shared hosting depending on the configured provider. All media-type
  AI defaults are **off** (admin opt-in).
- **AI confidence threshold ships uncalibrated** — it's an admin `Setting` with a reasonable default and
  needs real traffic to tune.
- **`quantity_partial_counts_as_done` defaults to `false`** — revisit once there's real usage data on
  which creators want.
- **Daily leaderboard post time** is a single admin-configurable hour (one global hour, not per-chat);
  the on-demand `/leaderboard` command is rate-limited to one per 5 minutes per chat.
- **Observability thresholds** (slow-query, exception-alert, heartbeat staleness) are `Setting`-backed
  with placeholder defaults, not tuned numbers.
- **No external dead-man's-switch** is wired yet; the heartbeat monitor exists, the external alerter is
  optional and undecided (self-host vs third-party).

## License

No license has been chosen yet. **Add a `LICENSE` before making this repository public** — until then,
default copyright applies and nobody else has permission to use, copy or distribute the code.

## Contributing

This repository is built against the task roadmap in `prompts/main.md` and its addenda. For the operating
conventions (architecture, testing requirements, hard constraints) read [`CLAUDE.md`](CLAUDE.md) first —
it is the stable contract every change is expected to follow.
