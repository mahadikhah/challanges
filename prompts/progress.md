# Build Progress — Telegram Challenges Platform

Autonomous build ledger. Updated after every task. See `CLAUDE.md` (stable contract) and `prompts/main.md`
(spec + roadmap). Working branch: `build/foundation`.

Status key: ✅ done · 🔄 in progress · ⬜ not started

## Phase 1 — Setup
- ✅ **Setup Task 1 — Project Bootstrap** (`prompts/task-01-setup.md`)
- ✅ **Setup Task 2 — i18n + RTL layer**
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
