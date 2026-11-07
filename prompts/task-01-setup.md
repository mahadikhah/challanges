## Setup Task 1: Project Bootstrap

### Goal
Take the scaffolded `laravel/react-starter-kit` to a working baseline for this platform: a git repo, MySQL,
the Telegram bot SDK, Pest, Sanctum, the three route files, the Mini App Vite entry, and CSRF exclusions —
ending with `sail composer ci:check` green. No product behaviour yet; this is pure plumbing.

### Before starting
- Read `CLAUDE.md` and `prompts/main.md` in full.
- Ensure Docker is running; the repo currently has only the `laravel.test` Sail service and defaults to SQLite.
- This is the first task, so there is little to query yet — but after it, `graphify update .` so later tasks
  can `graphify query`.

### What to Build (in order)
1. **Git.** `git init`, commit the current scaffold as-is ("chore: initial scaffold"), then create and switch
   to branch `build/foundation`. All subsequent work happens on this branch.
2. **MySQL 8.4 in Sail.** Add a `mysql:8.4` service to `compose.yaml` (named volume, healthcheck,
   `MYSQL_DATABASE/USER/PASSWORD` from env), and make `laravel.test` `depends_on` it. Point `.env` and
   `.env.example` at it: `DB_CONNECTION=mysql`, host `mysql`, etc. **Keep** `QUEUE_CONNECTION=database`,
   `CACHE_STORE=database`, `SESSION_DRIVER=database`. Do **not** add a Redis service.
3. `sail up -d`, then `sail artisan migrate` — confirm the default tables land on MySQL.
4. **Bot SDK.** `sail composer require irazasyed/telegram-bot-sdk:^3.16`. Assert the resolved version is
   ≥ 3.16.0 (it is the first release allowing `illuminate/support: 9 - 13`). Publish its config.
5. **Sanctum.** `sail composer require laravel/sanctum`, publish, migrate. This backs the Mini App bearer token.
6. **Pest.** `sail composer require --dev pestphp/pest pestphp/pest-plugin-laravel`, then
   `sail artisan pest:install` (creates `tests/Pest.php`). Leave the existing PHPUnit auth tests in place —
   migrate opportunistically later, not now. Confirm a trivial `expect(true)->toBeTrue()` test runs.
7. **Route files.** Create empty-but-registered `routes/api.php`, `routes/admin.php`, `routes/telegram.php`.
   Register them in `bootstrap/app.php` `->withRouting(...)`: `api.php` under prefix `api`; add a versioned
   group scaffold for `/api/v1/miniapp`; `admin.php` under prefix `admin`; `telegram.php` for the webhook.
8. **CSRF exclusion.** Exclude the bot webhook path and `api/*` from Laravel 13's `PreventRequestForgery`
   middleware in `bootstrap/app.php`. Add a placeholder `POST` webhook route that returns 200 and prove it is
   reachable without a CSRF token.
9. **Mini App Vite entry.** Create `resources/js/miniapp/main.tsx` (minimal React mount), a Blade shell
   `resources/views/miniapp.blade.php` that loads it and the Telegram `telegram-web-app.js` script, and a
   catch-all web route serving that shell. Add `resources/js/miniapp/main.tsx` to the `input` array in
   `vite.config.ts` so admin and Mini App build as separate entries.
10. **Config + env.** Add a `config/telegram.php` (or `services.php` block) reading:
    `TELEGRAM_BOT_TOKEN`, `TELEGRAM_BOT_USERNAME`, `TELEGRAM_WEBHOOK_SECRET`, `TELEGRAM_REQUIRED_CHANNEL`,
    `MINIAPP_URL`, and the Ed25519 public keys. Add these keys (empty/placeholder) to `.env` and `.env.example`.
11. `sail artisan wayfinder:generate`, then `sail npm run build` to prove both Vite entries compile.

### Tests (Pest, required)
- App boots and migrations run on MySQL (a feature test hitting `/up` → 200).
- The webhook route is reachable via `POST` **without** a CSRF token (no 419) — proves the exclusion works.
- An `/api/v1/*` route is JSON and CSRF-exempt.
- A trivial Pest `expect()` test passes (proves Pest is the runner).
- `Http::fake()` is used anywhere the Bot SDK might be touched — never hit real Telegram.

### Explicitly Out of Scope
- No domain models, migrations, or enums (that is Domain Task 1).
- No bot update handling, no channel gate, no wizard logic.
- No i18n / RTL yet (Setup Task 2). No `Setting` model yet (Setup Task 3).

### Code Rules
- Follow `CLAUDE.md`. Reuse the scaffolded starter-kit pieces; do not rebuild auth/layouts/theme.
- Pin the bot SDK at `^3.16`; do not accept a lower resolved version.
- No Redis, no Filament, no long-polling. Everything runs via `sail`.

Work only on this task.
