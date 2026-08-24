# Build Progress — Telegram Challenges Platform

Autonomous build ledger. Updated after every task. See `CLAUDE.md` (stable contract) and `prompts/main.md`
(spec + roadmap). Working branch: `build/foundation`.

Status key: ✅ done · 🔄 in progress · ⬜ not started

## Phase 1 — Setup
- ✅ **Setup Task 1 — Project Bootstrap** (`prompts/task-01-setup.md`)
- ⬜ Setup Task 2 — i18n + RTL layer
- ⬜ Setup Task 3 — `Setting` model + admin-tunable config

## Phase 2 — Domain core (pure Actions, fully unit-tested, no Telegram coupling)
- ⬜ Migrations, models, backed enums
- ⬜ `CoinLedger` service (locked, transactional, idempotency-keyed)
- ⬜ Entitlement grant/consume
- ⬜ Period materialisation (all six `period_type`)
- ⬜ Streak / freeze / miss engine (+ `streak_resets_count`, no auto-remove)
- ⬜ Invite code generation + brand-new-user crediting rule
- ⬜ Per-participant-per-period phrase generation

## Phase 3 — Bot core
- ⬜ Webhook + `update_id` idempotency + immediate 200 + queued processing
- ⬜ Channel gate; `/start` with invite attribution
- ⬜ Create-challenge wizard (`BotConversation`); join flow
- ⬜ Check-in for all three proof types
- ⬜ Reminders (scheduler + staggered queued jobs); locale selection

## Phase 4 — Stars payments
- ⬜ `createInvoiceLink` (XTR, empty provider_token) → pre_checkout → successful_payment → credit
- ⬜ Refund path (`refundStarPayment`)

## Phase 5 — Mini App
- ⬜ `POST /api/v1/miniapp/auth` (initData → Sanctum token)
- ⬜ `/api/v1/miniapp/*` surface
- ⬜ Gameish React SPA (details, status, freezes, progress; themeParams/BackButton/MainButton)

## Phase 6 — Admin panel
- ⬜ Settings/economy tuning, challenge moderation, image-proof review, user/coin adjustments, audit views

## Phase 7 — Website
- ⬜ Marketing landing (fuller mirror later)

---

## Log

### Setup Task 1 — Project Bootstrap ✅
Branch `build/foundation` off `master`.

**Verified starting state** (already done in scaffold): git repo (4 commits), Pint, Larastan (lvl 7),
ESLint/Prettier, Wayfinder (generating), Fortify, graphify-out (fresh), Sail runtime 8.5.
**Gaps being filled:** MySQL service + env switch, telegram-bot-sdk ^3.16, Sanctum, Pest,
`api/admin/telegram` route files + registration, CSRF exclusion, Mini App Vite entry/shell/catch-all,
`config` telegram block + env keys.

**Decisions:**
- Pest is the test framework (CLAUDE.md/main.md mandate; overrides generic Boost "use PHPUnit" guideline).
- Test DB = MySQL `testing` database (domain needs real `lockForUpdate`/constraints; §6 coin-concurrency).
  `compose.yaml` mounts the vendored Sail `create-testing-database.sh` into the mysql init dir so the
  `testing` DB is created on first boot (no local copy).
- Used `install:api` for Sanctum + `routes/api.php` + api routing (idiomatic Laravel 13).
- Platform telegram keys live in `config/services.php` `telegram` block (SDK owns `config/telegram.php`).
- Mini App mount kept minimal; no `@telegram-apps/sdk` npm dep yet (Phase 5).

**Scaffold fixes to reach a green gate** (pre-existing, not introduced by this task):
- `config/sanctum.php`: restored the canonical `(string)` cast on `env('SANCTUM_STATEFUL_DOMAINS', …)`
  before `explode()` — the published stub omitted it, tripping PHPStan lvl 7 (`explode` wants `string`,
  `env` returns `bool|string`).
- `database/factories/UserFactory.php`: `withTwoFactor()` shipped as an empty `{}` body (violates its
  `: static` return type). Filled in the faithful 2FA state (`two_factor_secret`,
  `two_factor_recovery_codes`, `two_factor_confirmed_at`) the skipped `AuthenticationTest` expects.

**Result — `sail composer ci:check` GREEN:** eslint ✓, prettier ✓, `tsc --noEmit` ✓, pint ✓,
phpstan lvl 7 (0 errors) ✓, tests 41 (37 pass, 4 skipped = Fortify 2FA disabled). Both Vite entries
(`app.tsx` + `miniapp/main.tsx`) build. Verified live in-container: `POST /telegram/webhook/*` → 200
(CSRF-exempt), `GET /api/v1/ping` → JSON 200, `GET /up` → 200.

**Follow-ups noted:** `.github/workflows/tests.yml` will need a MySQL service (tests now target MySQL);
add `Laravel\Sanctum\HasApiTokens` to `User` in Phase 5 (Mini App bearer tokens).

**Next:** Setup Task 2 (i18n + RTL layer).
