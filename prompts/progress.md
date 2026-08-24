# Build Progress — Telegram Challenges Platform

Autonomous build ledger. Updated after every task. See `CLAUDE.md` (stable contract) and `prompts/main.md`
(spec + roadmap). Working branch: `build/foundation`.

Status key: ✅ done · 🔄 in progress · ⬜ not started

## Phase 1 — Setup
- ✅ **Setup Task 1 — Project Bootstrap** (`prompts/task-01-setup.md`)
- ✅ **Setup Task 2 — i18n + RTL layer**
- ✅ **Setup Task 3 — `Setting` model + admin-tunable config**

**Phase 1 complete.**

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

### Setup Task 2 — i18n + RTL layer ✅

Farsi + English from day one, one catalogue serving the bot, both Inertia surfaces and the Mini App SPA.

**Built:**
- `config/localization.php` — the allowlist (`en` ltr / `fa` rtl + native names), cookie name, and
  `client_groups` (which `lang/{locale}/*.php` files travel to the browser).
- `app/Services/Localization.php` — single source of locale knowledge: `codes()`, `isSupported()`,
  `fallback()`, `direction()`, `nativeName()`, `options()`, `payload()`, `clientCatalog()`.
- `app/Http/Middleware/SetLocale.php` — resolution + `View::share('locale'/'direction')`. Registered on
  both the `web` group (before `HandleInertiaRequests`, which reads the resolved locale) and `api`.
- `HandleInertiaRequests` spreads `payload()` into shared props; `app.blade.php` and `miniapp.blade.php`
  render `lang` + `dir`.
- `POST /locale` (`LocaleController` + `LocaleUpdateRequest`) → forever cookie, `back()`. Guest-accessible.
- Frontend: `lib/i18n.ts` (pure `translate()`), `hooks/use-translation.ts` (Inertia props),
  `miniapp/localization.ts` (reads the embedded `#localization` JSON), `components/language-switcher.tsx`,
  wired into `settings/appearance`. `types/localization.ts` + `global.d.ts` shared-prop typing.

**Decisions:**
- **Locale precedence:** `HasLocalePreference` (user) → cookie → `Accept-Language` → configured fallback.
  Keyed off the *contract*, not a column, so it starts working untouched when Domain Task 1 adds
  `User.locale`.
- **Cookie, not user column, as the durable store** — most visitors have no user row (bot users
  authenticate by Telegram identity), so a cookie is the only thing available pre-account.
- **Locale is treated as a security input.** It becomes a path segment in a `require`d filename, so it is
  allowlist-checked in the middleware, again in the Form Request, and again inside the service before any
  path is built. Traversal payloads are covered by tests.
- **Zero new npm dependencies.** No i18n library: the payload is a flat `group.key => line` map
  (`Arr::dot`) with the fallback locale merged underneath, so a missing Farsi line degrades to English
  wording instead of rendering a raw key. Keeps the Mini App bundle small and means bot/web/SPA copy
  cannot drift — there is no second JS-side catalogue.
- **`client_groups` allowlist** keeps server-only copy (bot replies, mail, validation) off the wire.
- **RTL needs no plugin.** Tailwind v4 logical utilities (`ms-*`/`me-*`/`ps-*`/`pe-*`/`text-start`) plus
  `rtl:`/`ltr:` variants driven by the `dir` attribute. Fixed `appearance-tabs.tsx` (`-ml-1`→`-ms-1`,
  `ml-1.5`→`ms-1.5`) as the first instance.
- Mini App payload is embedded via Blade `@json`, whose default flags hex-escape `<`/`>` (so a translation
  can never close the `<script>` tag) while leaving structural quotes intact — verified parseable, and
  asserted in the test.

**Trap worth remembering — Wayfinder:** `vite.config.ts` sets `wayfinder({ formVariants: true })`, but
`artisan wayfinder:generate` **defaults to no form variants**. Running it bare silently strips `.form` off
every helper and breaks `tsc` on nine existing pages. Always
`sail artisan wayfinder:generate --with-form` (or just let `npm run build`/`dev` regenerate).

**Tests:** `tests/Feature/LocalizationTest.php` (HTTP: precedence incl. user-preference-over-cookie via a
`HasLocalePreference` double, allowlist rejection dataset — unsupported/`../en`/deep traversal/absolute
path/empty, `lang`+`dir` for both directions, Inertia shared props incl. flat-key catalogue, switch route
cookie + redirect-back + guest access + invalid-input dataset, Mini App shell payload + catch-all) and
`tests/Feature/LocalizationServiceTest.php` (service unit: codes, allowlist, direction, misconfigured
`app.fallback_locale` guard, picker shape, payload, `Arr::dot` flattening, fallback backfill, client-group
scoping, placeholder passthrough, server-side `__()` replacement). Both use `withoutVite()` where they
render Blade, so they don't depend on a built manifest.

**Result — `sail composer ci:check` GREEN:** eslint ✓, prettier ✓, `tsc --noEmit` ✓, pint ✓,
phpstan lvl 7 (0 errors) ✓, tests **76 (72 pass, 4 skipped = Fortify 2FA disabled)**, +35 from this task.
Both Vite entries build (`app.tsx`, `miniapp/main.tsx` both present in the manifest).

**Deliberately deferred (plumbing is done; these are content/sweep work):**
- Farsi `validation.php` + Fortify auth message translations — content, not wiring.
- Converting the starter-kit pages' hardcoded English (`"Appearance settings"`, `AppearanceTabs` labels,
  auth/settings copy) to `t()`. Those pages get revisited in Phases 6–7; doing it now would be a large
  diff touching files this task otherwise doesn't need.
- Full RTL audit of the ~25 shadcn/ui components and `app-sidebar`/`app-header` — same reason.
- No JS test framework, so `lib/i18n.ts`'s `translate()` is unit-tested only indirectly (the payload it
  consumes is asserted server-side). CLAUDE.md forbids introducing one unprompted.
- Consider caching the flattened catalogue if it grows (currently per-request memoised only).

**Next:** Setup Task 3 (`Setting` model + admin-tunable config).

### Setup Task 3 — `Setting` model + admin-tunable config ✅

CLAUDE.md: *"Every rate and price is admin-configurable. Never hardcode one."* This is the layer that makes
that true, built before any code needs a price so no phase has an excuse to inline one.

**Built:**
- `app/Enums/SettingType.php` — `Text|Integer|Boolean|Json`, with `matches(mixed): bool` (the write-time
  gate) and `describe(): string` (the error/admin-form wording).
- `app/Enums/SettingKey.php` — **the registry**: 12 cases, each carrying its own `default()` and `type()`.
  Economy (`invite_coin_reward`, `create_slot_coin_price`, `join_slot_coin_price`, `freeze_coin_price`,
  `challenge_completion_coin_reward`, `stars_packages`), baseline (`free_create_slots`, `free_join_slots`,
  `default_challenge_freezes`), access/tokens (`required_channel`, `miniapp_token_ttl_minutes`,
  `initdata_max_age_seconds`).
- `settings` migration — `key` unique, nullable `json` `value`. No `type` column.
- `app/Models/Setting.php` (`value` → `json` cast) + `SettingFactory`.
- `app/Services/Settings.php` — `get()`, typed `string()/integer()/boolean()/array()`, `all()`, `set()`,
  `forget()`, `flush()`.

**Decisions:**
- **A row means "an admin overrode this"; the enum case is the default.** So `get()` answers correctly
  against an empty table: a forgotten seeder can't take pricing down, and a newly added tunable needs no
  backfill migration.
- **Therefore no `SettingsSeeder`** — one was generated, then deleted by design. Materialising a row per
  default converts every default into a permanent override, so a *better* default shipped in a later release
  could never reach an existing install. That also contradicts what `forget()` means.
- **No `type` column.** The enum already declares each key's shape, and a second copy in the database can
  disagree with it. `type()` is the single source.
- **Writes reject the wrong shape rather than coercing it** (`InvalidArgumentException`, message
  `Setting [freeze_coin_price] expects an integer.` — usable directly in the admin form). These are prices;
  a silently-cast `'15'`/`true` is worse than a loud failure. `SettingType::Integer` deliberately uses
  `is_int()`, so numeric strings, floats and booleans are all rejected.
- **Reads never throw on bad data.** A hand-edited row of the wrong shape falls back to the registry
  default — a corrupt setting must not be able to take check-ins down. The distinction is deliberate:
  strict on the way in, forgiving on the way out.
- **A wrong *accessor*, however, is a `LogicException`** (`integer(StarsPackages)`), because that's a bug at
  the call site, not bad data.
- **One cache entry holds every override** (`settings.overrides`, `rememberForever`), plus a per-request
  memo. The cache store is the `database` driver (no Redis), so per-key entries would turn one webhook into
  a dozen SELECTs; a test pins that four reads cost **1** query. Cache **tags are unavailable** on the
  `database` driver, hence one key and a whole-entry bust on write.
- **Both `Settings` and `Localization` are now singletons** (`AppServiceProvider::register`). Settings
  *requires* it — `set()` clears the memo on the instance it was called on, so a second instance would keep
  serving the pre-write value. Localization merely benefits: it was being resolved four times per request,
  re-reading and re-flattening the lang files each time (a real Task 2 inefficiency, fixed here).
- Anything writing to `settings` outside `set()`/`forget()` must call `flush()`; asserted by a test.
- `config/services.php`: `required_channel` hardened to `(string) env(...)`, because
  **`Config::string()` throws when a key exists holding `null`** — the default argument only applies when
  the key is *absent*.

**Trap worth remembering — MySQL JSON key order.** A native `json` column **sorts object keys** on storage,
so `['stars' => 25, 'coins' => 25]` reads back as `['coins' => 25, 'stars' => 25]`. Order *within a list* is
preserved (so the Stars package ordering users see is stable), but any assertion or comparison on an
associative payload must be order-insensitive — `toEqual()`, not `toBe()`. Relevant to Phase 4, which reads
`stars_packages` back out to build invoices.

**Trap worth remembering — `make:enum`.** Once `app/Enums/` exists, `make:enum Enums/SettingType` writes to
`app/Enums/Enums/SettingType.php`. Pass the bare name.

**Tests:** `tests/Feature/SettingsTest.php` — 47 tests in six `describe()` blocks: the registry (a dataset
asserting **every** case's default matches its own declared type; coverage of the tunables CLAUDE.md names;
`all()` ordering), defaults (resolve against an empty table; free baseline is 1+1; Stars packages are
invoice-shaped; env-derived default read through config), overrides (override wins, `set()` persists +
busts a warmed cache, no duplicate rows, `forget()` reverts, structured round-trip, unknown key ignored),
write validation (6-case wrong-type dataset, exact message, wrong-accessor `LogicException`, corrupt-row
fallback), caching (4 reads = 1 query via `DB::listen`, raw write needs `flush()`, singleton identity), and
`SettingType::matches()` (11 cases).

**Result — `sail composer ci:check` GREEN:** eslint ✓, prettier ✓, `tsc --noEmit` ✓, pint ✓,
phpstan lvl 7 (0 errors) ✓, tests **123 (119 pass, 4 skipped = Fortify 2FA disabled)**, +47 from this task.

**Follow-up noted:** the admin UI for editing these lands in Phase 6 — it can render `all()` and drive
inputs off `type()`, and should surface `set()`'s exception message as the field error.

**Next:** Phase 2, Domain core — migrations, models and backed enums, then the pure Actions (`CoinLedger`
with `lockForUpdate()`, entitlements, period materialisation for all six `period_type`, streak/freeze/miss
engine, invite crediting, per-participant-per-period phrase generation), unit-tested before any surface.
