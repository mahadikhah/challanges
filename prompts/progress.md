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
- ✅ **Domain Task 1 — challenge core: backed enums, schema, models, factories**
- ✅ **Domain Task 2 — economy + infra schema** (`CoinTransaction`, `Entitlement`, `Invite`, `StarPayment`,
  `TelegramUpdate`, `BotConversation`, `ReminderDispatch`)
- ✅ **Domain Task 3 — `CoinLedger` service** (locked, transactional, idempotency-keyed; §6 coin-concurrency
  test with real forked writers)
- ✅ **Domain Task 4 — period materialisation** (all six `period_type`, challenge-timezone boundaries, DST- and
  month-end-correct, idempotent)
- ✅ **Domain Task 5 — entitlement grant/consume** (free baseline tops up, extra slots bought through
  `CoinLedger` atomically, consumption idempotent per challenge)
- ✅ **Domain Task 6 — streak / freeze / miss engine** (`SettleCheckIn` + `RollOverPeriod`; transition-as-token
  idempotency, participant mutex, `streak_resets_count`, no auto-remove)
- ✅ **Domain Task 7 — invite codes + brand-new-user crediting** (single-use codes, `wasRecentlyCreated`
  eligibility, `Claimed` vs `Credited`, paid once per invite row)
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

## Backlog — captured, not scheduled

Ideas raised mid-build that are **not** in `CLAUDE.md` or the seven-phase roadmap. Recorded here so they
aren't lost; each needs a scope decision before it becomes a task.

- **Creator-owned channels/groups per challenge** (`prompts/new-ideas-TODO.md`). A creator registers their own
  channel or group with the bot, adds the bot as admin, and the challenge gains social surface: daily or
  on-demand leaderboard posts, a message in the group whenever someone checks in, and similar.
  *Fits naturally after Phase 3* (it needs the check-in event and the reminder scheduler to exist first).
  **Open questions to settle before building:** proof/streak data leaving the challenge into a group is a
  privacy decision that interacts with `proof_is_public` (a private-proof challenge must not leak proofs via
  a leaderboard); verifying the creator actually administers the channel needs `getChatMember` on the
  *creator*, not just the bot; per-chat posting adds fan-out against the ~1 msg/sec per-chat rate limit; and
  a `ChallengeChat` model plus an idempotency key per (challenge, period, post kind) would be needed so a
  re-run cannot double-post.

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

### Domain Task 1 — challenge core: enums, schema, models, factories ✅

The shape of the product, in the type system and the schema, before any behaviour is written. No Actions here
on purpose: the engines in Tasks 3–9 are much easier to write against enums that already answer the
domain questions ("does this break a streak?") than against strings.

**Built:**
- Six backed enums in `app/Enums/`: `PeriodType`, `ChallengeVisibility`, `ProofType`, `ChallengeStatus`,
  `ParticipantStatus`, `CheckInStatus` — each carrying the predicates its callers would otherwise
  re-implement (`requiresCustomDays()`, `shouldAnnounce()`, `isAutoApproved()`/`requiresReview()`/
  `supportsPublicProof()`/`expectsText()`/`expectsFile()`, `acceptsJoins()`/`acceptsCheckIns()`,
  `owesCheckIns()`, `isSettled()`/`allowsSubmission()`/`incrementsStreak()`/`preservesStreak()`/
  `breaksStreak()`).
- `app/Enums/Concerns/HasTranslatedLabel.php` — `label()` + `options()` for pickers and inline keyboards,
  deriving the lang key from the class name (`CheckInStatus` → `enums.check_in_status.<value>`). One copy
  instead of six.
- `lang/{en,fa}/enums.php` — every case in both locales. `config/localization.php` `client_groups` now ships
  `enums` to the browser, so the Mini App renders statuses without a second catalogue.
- Five migrations: Telegram columns on `users`, then `challenges`, `challenge_periods`,
  `challenge_participants`, `check_ins`.
- Four new models (`Challenge`, `ChallengePeriod`, `ChallengeParticipant`, `CheckIn`) + a rewritten `User`,
  all with `#[Fillable]`, `#[Scope]` and full `@property` docblocks.
- Five factories, with states named after domain situations (`active()`, `publiclyVisible()`,
  `provenBy()`, `every()`, `timeline()`, `joinedAtPeriod()`, `withoutFreezes()`, `on()`, `withPhrase()`).

**Decisions:**
- **`email` and `password` are now nullable.** A bot user has no credentials and an admin has no
  `telegram_id`; the two auth paths stay disjoint rather than being forced through one shape. MySQL permits
  repeated `NULL`s in a unique index, so the `users.email` unique constraint still bites for admins while
  many credential-less bot users coexist — pinned by a test, since the whole design rests on it.
- **`is_admin` and `channel_verified_at` are deliberately not `#[Fillable]`.** Both are privilege state.
  A test asserts `User::create([... 'is_admin' => true])` produces a non-admin.
- **`telegram_id` is `unsignedBigInteger`** — Telegram ids passed 2^32 long ago.
- **No `draft` challenge status.** A half-built challenge lives in `BotConversation` (the wizard's state), so
  a `challenges` row is only ever written complete. Four statuses: `scheduled|active|completed|cancelled`.
- **`ChallengeStatus::Scheduled` and `Active` both accept joins** — late joiners catch up on the shared
  timeline, which is the settled product rule, so joining mid-flight is correct rather than an edge case.
- **No `ParticipantStatus` for repeated failure.** A miss costs the streak and nothing else; `Removed` is for
  moderation. `streak_resets_count` is materialised now so a stricter rule can be layered later as a counter
  check with no schema change. A test asserts the case list, so adding a "failed out" state is a conscious act.
- **`CheckInStatus::Rejected` is *not* terminal.** Resubmission is allowed until the period closes; only
  rollover decides Frozen vs Missed. Hence `unsettled()` covers Pending|Submitted|Rejected — those are all
  still the rollover sweep's problem — and `breaksStreak()` is true for `Missed` *only*. That single
  predicate is what keeps the Task 6 engine from having to reason about review state.
- **A freeze preserves a streak but does not extend it** (`incrementsStreak()` is Approved-only,
  `preservesStreak()` is Approved|Frozen). Buying out of a penalty is not the same as doing the thing.
- **Period windows are half-open** — `starts_at` inclusive, `ends_at` exclusive, and period N's `ends_at`
  *is* period N+1's `starts_at`. So `contains()` uses `lt`, not `lte`, and no instant belongs to two periods.
  A test asserts the shared boundary instant resolves to the later period only.
- **`rolled_over_at` added to `challenge_periods`** beyond CLAUDE.md's field list (the model list is
  explicitly "living"). Materialising the timeline is only half of what makes rollover idempotent; without a
  settled-at marker the sweep has no way to skip a period it already closed. `awaitingRollover()` is the
  work queue: elapsed and unsettled.
- **`ProofType::supportsPublicProof()` is `ImageApproval` only**, and `sharesProofPublicly()` requires *both*
  it and the creator's opt-in. A tap has nothing to show, and publishing an autogenerated phrase would hand
  every other participant the answer — the mechanic is per-participant precisely to prevent that.
- **Date arithmetic is deliberately absent from `PeriodType`.** It belongs with the Task 4 materialiser, which
  owns the challenge-timezone conversion; an enum method would invite a `now()`-in-server-tz call site.
- **Deletion policy:** challenge deletion cascades its timeline, roster and check-ins (they are meaningless
  without it), but a deleted *user* nulls out `referred_by_user_id` and `check_ins.reviewed_by` rather than
  destroying someone else's history.

**Trap worth remembering — `Illuminate\Support\Carbon` is the wrong model docblock type here.**
`AppServiceProvider` calls `Date::use(CarbonImmutable::class)`, so every `datetime` cast returns
`Carbon\CarbonImmutable`, which is **not** a subclass of `Illuminate\Support\Carbon` (that extends the
*mutable* `Carbon\Carbon`). PHPStan never caught it because it trusts `@property` annotations rather than
cross-checking them against the cast; it surfaced only as a runtime `TypeError` the first time a real
attribute was passed to a `Carbon`-hinted parameter. Corrected across all six models — including
`User`/`Setting`, which had shipped with the same wrong annotation. **Convention going forward:
`CarbonImmutable` for `@property`, `CarbonInterface` for parameters** (so callers may pass either).

**Trap worth remembering — Larastan wants generics on every relation.** `BelongsTo`/`HasMany` return types
need `@return BelongsTo<Related, $this>` at level 7. Fifteen methods, all flagged at once.

**Trap worth remembering — `index` is a MySQL reserved word.** Safe through the query builder (it
backtick-quotes), so `where('index', …)`/`orderBy('index')` work; a raw expression would not. Asserted.

**Tests:** `tests/Feature/Domain/ChallengeSchemaTest.php` — **95 tests / 261 assertions** in eight
`describe()` blocks. Beyond casts/uniques/relationships, the ones that pin real decisions: every unique index
actually bites (`users.telegram_id`, `(challenge_id, index)`, `(challenge_id, user_id)`, and
`(challenge_participant_id, challenge_period_id)` — the constraint that makes a double-tap, a retried webhook
and a re-run rollover all converge); many credential-less users coexist under the unique email index;
mass assignment can't grant admin; the half-open boundary; `owesPeriod()` excluding periods that closed
before a late joiner arrived, and returning false for every non-`Active` status; enum *values* (not names)
are what land in MySQL, so a case rename can't orphan rows; and a dataset asserting every case of all six
enums has a non-key label in **both** `en` and `fa`.

Phrase normalisation gets its own block, because `text_autogen` auto-approves on match and a false negative
is a lost streak: whitespace/case/ordering behaviour, plus the Farsi-specific folds — Arabic Yeh/Kaf
(`ي`/`ك` → `ی`/`ک`, a keyboard difference, not a wrong answer), Eastern Arabic digits (`۴۲`/`٤٢` → `42`), and
ZWNJ treated as a space. Near-misses, reordered words, a missing phrase and an empty `expected_phrase` all
correctly fail — a corrupt row is not a free pass.

**Result — `sail composer ci:check` GREEN:** eslint ✓, prettier ✓, `tsc --noEmit` ✓, pint ✓,
phpstan lvl 7 (0 errors) ✓, tests **218 (214 pass, 4 skipped = Fortify 2FA disabled)**, +95 from this task.
All five migrations verified to reverse cleanly (`migrate:rollback --step=5` then `migrate`) before any model
code was written.

**Next:** Domain Task 2 — the economy and infrastructure schema (`CoinTransaction` ledger with unique
`idempotency_key`, `Entitlement`, `Invite`, `StarPayment` unique on `telegram_payment_charge_id`,
`TelegramUpdate` unique on `update_id`, `BotConversation`, `ReminderDispatch` unique on
`(participant, period, kind)`).

---

### Domain Task 2 — economy + infra schema: enums, migrations, models, factories ✅

Seven tables, seven backed enums, seven models, seven factories. Every one of the four idempotency keys named
in `CLAUDE.md` now exists as a real database constraint rather than an intention.

**Enums.** `CoinTransactionReason` (9), `EntitlementType`, `EntitlementSource`, `InviteStatus`,
`StarPaymentStatus`, `ReminderKind`, `ConversationState` (12).

**Decisions and assumptions**

- **The sign of a transaction is a property of its reason, not a caller argument.**
  `CoinTransactionReason::sign()` returns `±1` and `CoinLedger` (Task 3) will multiply an *absolute* magnitude
  by it. That is why admin adjustments are **two cases** — `AdminCredit` / `AdminDebit` — rather than one
  signed "adjustment": with a single case, a mistyped minus in a controller could mint coins.
  `CoinTransaction::hasConsistentSign()` is the read-side assertion that catches any row written around the
  ledger, and it deliberately treats `amount === 0` as inconsistent — a no-op entry is a bug, not a balance.
- **The ledger is append-only by convention, not by trigger.** Nothing in the app updates or deletes a row; a
  mistake is corrected with a compensating entry. Enforcing it in MySQL would also block `migrate:fresh`, and
  the value is in the convention being followed, which tests can assert.
- **Invite codes are single-use, and a user is attributable to at most one inviter for life.** `code` is
  unique (as `CLAUDE.md` specifies) *and* `invited_user_id` is unique. The second index is the structural half
  of "an invite credits coins only if the invited user is brand-new": even if the runtime check in Task 7 were
  bypassed, the database refuses to attribute the same person twice. Nullable, so unclaimed codes coexist
  (repeated `NULL`s under a MySQL unique index).
- **`InviteStatus` separates `Claimed` from `Credited`.** An invite used by someone who *already had* an
  account is attributed but unpaid. Collapsing the two would make "used but unpaid" indistinguishable from
  "never used", and the inviter would rightly ask why they weren't paid.
- **`star_payments` gained `paid_at` and `refunded_at`, beyond `CLAUDE.md`'s field list.** `status` alone
  cannot answer "when did this reverse?", which the refund path needs to be idempotent and support needs to
  answer. `telegram_payment_charge_id` is **nullable** unique: an invoice exists before Telegram issues a
  charge id, so the row is written at `createInvoiceLink` time and the id arrives with `successful_payment`.
  Hence `isRefundable()` requires *both* a paid status and a charge id — `refundStarPayment` needs the id, so
  a paid row without one cannot be refunded through the API and must not be advertised as refundable.
- **`invoice_payload` is unique too.** It is the only handle we control end-to-end, and it is what a
  `pre_checkout_query` carries back — the lookup key before a charge id exists.
- **Credit and refund idempotency keys are deliberately distinct** (`star_payment:credit:<id>` vs
  `star_payment:refund:<id>`). A refund has to be able to write even though the credit already did; one shared
  key would make the reversal a silent no-op.
- **`coin_amount` is frozen on the row at invoice time.** Deriving it from the `stars_packages` setting at
  credit time would let an admin re-pricing retroactively change what someone already paid for.
- **`TelegramUpdate::kind()` is derived, not stored.** Telegram owns that vocabulary and keeps adding to it; a
  column would need a migration to keep up. `fromTelegramId()` is documented as **lookup only** — trusting it
  for authorization would be trusting the request body, and every surface re-resolves the actor server-side.
- **`bot_conversations.user_id` is unique — one live flow per user.** Starting a new wizard replaces the old
  one, which matches how a chat actually behaves: there is one thread, so there is one place in it.
  `advanceTo()` **merges** rather than replaces the payload, so a step can be revisited without losing the
  answers gathered around it.
- **No `Idle` conversation state.** Absence of a row means idle. A row that says "nothing is happening" is a
  row that gets left behind, and then the next message gets fed into a dead wizard.
- **`ConversationState` is the one domain enum that is *not* translated.** These states are internal
  machinery, never shown; the prompts a user sees are separate lang lines chosen by the wizard. A test asserts
  `label()` does **not** exist on it, so adding one is a deliberate act rather than a copy-paste.
- **`ReminderKind::skipWhenSettled()` is true only for `PeriodEnding`.** There is no point telling someone
  their period is closing when they have already checked in — but "the challenge is starting" and "a new
  period opened" are still worth sending.
- **`entitlements.challenge_id` is `nullOnDelete`, and `consumed_at` survives it.** The slot was spent either
  way; clearing the spend along with the challenge would hand the slot back for free.
- `coin_transactions` carries `(user_id, id)` for the statement query and `(user_id, reason)` for
  admin filtering; `reminder_dispatches` carries `(scheduled_for, sent_at)` for the dispatch sweep.

**Tests — 110 new (`tests/Feature/Domain/EconomySchemaTest.php`), 301 assertions.** Every unique index is
asserted to actually bite: `coin_transactions.idempotency_key`, `invites.code`, `invites.invited_user_id`,
`star_payments.telegram_payment_charge_id`, `star_payments.invoice_payload`, `telegram_updates.update_id`,
`bot_conversations.user_id`, and the composite `(participant, period, kind)` — plus the counterpart proof that
repeated `NULL`s coexist under the nullable ones, since three of these designs depend on it. Datasets cover
the sign of all nine ledger reasons, the input each of the twelve conversation states expects, payment
terminality and reminder suppression. The `hasConsistentSign()` and zero-amount cases fabricate corrupt rows
on purpose, to prove the predicate names corruption rather than assuming it away.

**Result — `sail composer ci:check` GREEN:** eslint ✓, prettier ✓, `tsc --noEmit` ✓, pint ✓,
phpstan lvl 7 (0 errors) ✓, tests **328 (324 pass, 4 skipped = Fortify 2FA disabled)**, +110 from this task.
All seven migrations verified to reverse cleanly (`migrate:rollback --step=7` then `migrate`) before any model
code was written.

**Next:** Domain Task 3 — the `CoinLedger` service: one entry point for every coin mutation, inside a
transaction, with `lockForUpdate()` on the user row and a required `idempotency_key`. Balance derived from the
ledger and reconciled against it, never a bare mutable integer. Needs the §6 **coin-concurrency test** — real
parallel writers against MySQL, asserting no lost update and no double credit on a replayed key.

---

## Domain Task 3 — `CoinLedger` (done)

`app/Services/CoinLedger.php` is the only writer of `coin_transactions`. Everything that moves a coin —
Stars purchases, invite credits, completion rewards, slot and freeze purchases, refunds, admin corrections —
goes through `record()`, or through the `credit()`/`debit()` wrappers that additionally assert the reason
matches the direction the caller believed they were moving coins in.

**Decisions**

- **The `users` row is used purely as a per-user mutex.** The balance lives in `coin_transactions`, not on the
  user, so `lockForUpdate()` on `users` reads a row nobody cares about — that is the point. Every writer for a
  given user blocks on the same row before reading the balance, which serialises read-then-write per user.
  Locking the ledger rows instead would not work: the row that needs excluding is the one that does not exist
  yet.
- **Magnitude + reason, never a signed amount.** `credit($user, 100, ...)` and `debit($user, 15, ...)` both
  take a positive number; `CoinTransactionReason::sign()` decides the direction. A caller cannot pass `-50` to
  a credit because a caller never passes a sign at all. A magnitude of `0` or less throws
  `InvalidArgumentException` — zero would burn an idempotency key on a no-op.
- **Overdraft is allowed for exactly two reasons: `StarsRefund` and `AdminDebit`** (`allowsOverdraft()` on the
  enum). A user-initiated spend must never overdraw — you cannot buy a freeze you cannot afford. A clawback
  must: if someone buys 100 coins, spends them, and Telegram then refunds the Stars, the reversal has to
  complete or we have handed out the goods *and* given the money back. The resulting negative balance is the
  correct state — it blocks further purchases until cleared, which is exactly the desired consequence.
- **The overdraft guard is gated on `isDebit()`.** Subtle and worth stating: without that gate, crediting a
  user who sits at −100 would be *refused*, because `$balanceAfter < 0` is still true after adding coins. A
  credit can never be refused; it only ever moves a debt towards zero. Covered by a test.
- **A cross-user idempotency key throws `LogicException` rather than returning the other user's entry.** This
  was a real bug found by the test, not a hypothetical: the replay lookup inside the lock is keyed on
  `idempotency_key` alone, so the second user's call would have been handed the first user's transaction and
  reported success — crediting the wrong person. `assertBelongsTo()` now refuses. A key shared across users is
  a caller bug (usually a key built from something not actually unique per user), so failing loudly is right.
  The `UniqueConstraintViolationException` catch in `write()` remains as the backstop for the case the lock
  cannot cover: the same key racing for two *different* users takes two *different* row locks, so neither
  serialises it.
- **`balance_after` is a cache, and there is a way to find out when it lies.** `balanceFor()` reads the newest
  entry's running total (one indexed lookup via `(user_id, id)`, so it does not degrade as the ledger grows);
  `sum()` recomputes authoritatively; `drift()` returns the difference and is `0` on a healthy ledger. A test
  fabricates a row with a wrong `balance_after` and asserts `drift()` names it rather than the ledger trusting
  it. This is what "reconciled against, never a bare mutable integer" means in practice.
- **`canAfford()` is documented as advisory only.** It answers "should I render this button?", not "may this
  spend proceed?" — the balance can move between the check and the spend, so the authoritative check is the one
  `record()` makes inside the lock. Named that way to discourage its use as a gate.
- **`InsufficientCoinsException` carries the numbers** (`balance`, `required`, `shortfall()`), so a bot reply
  can say "you need 30 more coins" in the user's own language without re-reading the ledger.

**Tests — 49 new, in two files.**

`tests/Feature/Domain/CoinLedgerTest.php` (41) covers reading a balance, writing an entry, refusing to
overdraw, and idempotency. Notable cases: drift detection; the sign of all nine reasons as a dataset; a spend
landing exactly on zero; a Stars refund clawing an already-spent balance to −100; further spending blocked
while negative but a credit still accepted; a replay returning the original and *ignoring* the replayed amount
(999 coins replayed onto a 100-coin key does not top the balance up); credit and refund keys for one payment
being distinct so the reversal can write.

`tests/Feature/Domain/CoinLedgerConcurrencyTest.php` (8) is the §6 requirement, and the parallelism is real —
each writer is a **forked process with its own MySQL connection**, contending through the actual InnoDB lock
manager. Two decisions made it work:

- **`DatabaseTruncation`, not `RefreshDatabase`.** `RefreshDatabase`'s wrapping transaction would hide every
  write from every other connection, which is precisely the thing under test; the suite would have passed while
  proving nothing.
- **`DB::disconnect()` in the parent before forking.** A child that inherits an open PDO and then lets it fall
  out of scope sends `COM_QUIT` down a socket its siblings are still using.

The lock itself is proven directly, not just inferred: a second connection with
`SET SESSION innodb_lock_wait_timeout = 1` is shown to *fail* while the first holds `FOR UPDATE`, and to pass
through once it commits. Then: 8 parallel credits produce 8 rows with `balance_after` exactly
`[10,20,…,80]` (a lost update would repeat or skip a value); 8 racers against a 50-coin float produce exactly
5 spends and 3 refusals with a final balance of exactly 0 and `min(balance_after) >= 0`; 8 processes replaying
one key produce **1** row; and 4 keys delivered twice each across 8 workers produce exactly 4.

**Assumptions / follow-ups recorded**

- The concurrency file **skips with an explicit message** when `pcntl_fork` is unavailable. The Sail image
  ships `pcntl` and `posix`, so it runs locally. **The `.github/workflows/tests.yml` follow-up now carries two
  requirements, not one:** a MySQL service *and* `pcntl`, or CI silently loses the coin-concurrency coverage
  while still reporting green.
- A test file that commits for real has to clean up *after* itself, not only before. `DatabaseTruncation`
  truncates at the **start** of each test, which says nothing about what the last one leaves behind — so the
  final concurrency test's 4 committed rows were visible to the next file's `RefreshDatabase` transaction,
  breaking 8 assertions there while both files passed in isolation. Fixed at both ends: an `afterEach` that
  clears what the file wrote, and count assertions scoped to the user under test rather than counting the whole
  table. **Worth remembering for every future non-transactional test.**
- The ledger is append-only by convention, not by database trigger (as recorded in Domain Task 2). Nothing in
  the application updates or deletes a `coin_transactions` row; `drift()` is the detection mechanism if
  something ever does.

**Result — `sail composer ci:check` GREEN:** eslint ✓, prettier ✓, `tsc --noEmit` ✓, pint ✓,
phpstan lvl 7 (0 errors) ✓, tests **377 (373 pass, 4 skipped = Fortify 2FA disabled)**, +49 from this task.

**Next:** Domain Task 4 — period materialisation. Generate `ChallengePeriod` rows for all six `period_type`
values from `starts_at` + `total_periods`, with boundaries computed in the **challenge's** timezone and stored
UTC. Idempotent (safe to re-run), and DST-correct: a daily challenge in a zone that shifts must still produce
one period per local day, not a 23- or 25-hour drift that accumulates.

---

## Domain Task 4 — period materialisation (done)

`app/Actions/Challenges/MaterialiseChallengePeriods.php`. Two entry points: `boundaries()` computes the
timeline the challenge's configuration implies and touches no database, and `handle()` persists it. Splitting
them means the whole DST and month-end surface is testable as pure arithmetic, and the persistence layer only
has to be tested for idempotency.

**Decisions**

- **The arithmetic happens in the challenge's timezone; only the results are UTC.** `starts_at` is stored UTC,
  so the first thing the action does is convert it *back* to the creator's wall clock, advance there, and
  convert each boundary to UTC. "Daily" means one period per **local day**, so boundaries advance in calendar
  units where PHP holds the wall-clock time fixed across a shift. Adding a flat 24 hours to a UTC instant
  would drift an hour on the changeover and stay drifted, so the check-in window would open at 23:00 for the
  rest of the challenge.
- **Every boundary is computed from the original anchor, never from its predecessor.** Stepping incrementally
  accumulates whatever a clamp did: a monthly challenge starting 31 January would go 31 Jan → 29 Feb → **29
  Mar** and lose the month-end anchor permanently. Multiplying the step off the anchor gives
  31 Jan → 28 Feb → 31 Mar → 30 Apr. Tested explicitly, both directions.
- **No-overflow month and year addition** (`addMonthsNoOverflow`, `addYearsNoOverflow`). PHP's native
  `+1 month` on 31 January yields 2 or 3 March, **skipping February entirely** — a monthly challenge would
  have no February period at all. A test asserts the month names are `Jan, Feb, Mar`, which is the failure
  this would have caused. Same for a 29 February yearly anchor: 28 February, not 1 March.
- **`Seasonal` = 3 months, a quarter.** Deliberately not the astronomical solstices: those differ by
  hemisphere, and a participant needs to know when their period closes, not when the earth tilted.
- **Boundaries are converted to UTC inside the action, not at the call site.** This is load-bearing, not
  tidiness: the query builder formats a `DateTimeInterface` binding **in whatever timezone the object itself
  carries**, so handing it a Tehran-local Carbon would write the local wall clock into a UTC column and lose
  three and a half hours without erroring. A test reads the raw column through `DB::table()` to prove the
  stored string is UTC.
- **`insertOrIgnore`, so idempotency comes from the unique index rather than a read-then-write.** Two callers
  racing is safe, and a re-run adds only what is missing. Critically it is **insert-only — never an upsert**:
  an existing period's boundaries are never moved, because a check-in may already hang from them. A test
  changes `starts_at` after materialising and asserts period 0 does *not* move. Rebuilding a live timeline is
  a deliberate, separate act, not a side effect of re-running this.
- **Raising `total_periods` and re-running extends the timeline**, keeping the original rows' ids — the one
  edit that is safe to make in place. Tested.
- **Chunked inserts (500/statement).** `total_periods` is an unsigned smallint, so a legal-but-pathological
  challenge could ask for tens of thousands of periods.
- **`boundaries()` refuses rather than guessing**: `total_periods < 1`, and a `custom` challenge with a
  missing/zero/negative `custom_period_days`, both throw `InvalidArgumentException`. A garbage timezone throws
  too — Carbon's `InvalidTimeZoneException` extends `InvalidArgumentException`, so callers have one thing to
  catch. `handle()` writes nothing when it refuses, since validation happens before the first insert.
- A stray `custom_period_days` on a non-custom type is **ignored**, via the existing `Challenge::customPeriodDays()`
  gate — a leftover 90 from an edited draft cannot quietly turn a weekly challenge into a quarterly one.

**Tests — 40 new (`tests/Feature/Domain/MaterialiseChallengePeriodsTest.php`), 63 assertions.** Six groups: the
shape of a timeline, each period type, the challenge timezone, daylight saving, month ends, refusals, and
idempotency. A dataset walks all six `period_type` values (plus `custom` with 1 day, which must be
indistinguishable from `daily`) over **three periods, so four boundaries** — enough to catch an off-by-one in
the step multiplier that a single period would hide. The DST group asserts both halves of the same fact: local
midnight stays local midnight *because* the elapsed time is 23 or 25 hours, `[24, 24, 23, 24]` across London's
spring shift and `[24, 24, 25, 24]` across the autumn one, plus a 365-period timeline through **both**
changeovers landing exactly on the right local midnight a year later. One test asserts a Tehran timeline is a
flat 24 hours through 22 March — the old changeover date — which documents why the platform's Farsi audience
never sees a short period: **Iran abolished DST in 2022.**

**Assumptions / follow-ups recorded**

- **`app.timezone` must stay UTC, and this is now a load-bearing constraint rather than a default.** An
  attempted test flipped the process timezone to prove the arithmetic ignored it, and the boundaries moved by
  five hours. The cause is Laravel, not the action: Eloquent round-trips a `datetime` attribute **through a
  string** and re-parses it using the process timezone, so assigning a zone-bearing `CarbonImmutable` does not
  survive the setter. Changing `app.timezone` therefore silently reinterprets every timestamp already in the
  database. The test was replaced with the invariant that genuinely belongs to this code — the timeline is a
  pure function of the challenge and **not of `now()`**, verified by travelling the clock 18 months and
  asserting nothing moves. Worth carrying into any future work that touches stored timestamps.
- A DST *spring-forward* gap can make a chosen local wall-clock time nonexistent for one day (e.g. 00:30 in a
  zone that jumps 00:00 → 01:00); PHP shifts it forward. Not currently asserted — the affected zones and
  anchor times are a narrow slice, and the boundary still lands on the right local day. Noted rather than
  silently assumed.
- No upper bound on `total_periods` is enforced here. That belongs in the create-challenge Form Request
  (Phase 3), and the chunked insert means a large value degrades rather than breaking.

**Result — `sail composer ci:check` GREEN:** eslint ✓, prettier ✓, `tsc --noEmit` ✓, pint ✓,
phpstan lvl 7 (0 errors) ✓, tests **417 (413 pass, 4 skipped = Fortify 2FA disabled)**, +40 from this task.

**Next:** Domain Task 5 — entitlements. Grant and consume `create_slot` / `join_slot`: the free baseline of one
created and one joined challenge per user, extra slots bought with coins through `CoinLedger`, and consumption
tied to a challenge so the spend survives the challenge being deleted. Every price comes from `Setting`;
nothing hardcoded.

---

## Domain Task 5 — entitlements (done)

Three Actions in `app/Actions/Entitlements/` — `GrantFreeBaseline`, `PurchaseEntitlement`, `ConsumeEntitlement` —
plus `App\Exceptions\NoEntitlementAvailableException` and one new public method on `CoinLedger`. Every price and
allowance is read from `Setting` at call time; there is no hardcoded 1, 50 or 25 anywhere in the three classes.
`EntitlementType::freeAllowanceSetting()`, `priceSetting()` and `purchaseReason()` already existed from Domain
Task 2 and are reused rather than reimplemented.

**Decisions**

- **`GrantFreeBaseline` tops up; it does not grant.** It counts the user's existing `FreeBaseline` rows of that
  type and inserts only the difference, which makes it safe to call on **every `/start`** — the common case,
  since most bot traffic is returning users. It also means an admin raising `FreeCreateSlots` from 1 to 2 lifts
  existing users on their next `/start` rather than only new sign-ups. Lowering it stops future top-ups but
  **never revokes**: a held slot may already be spent on a live challenge, and un-granting it would mean
  removing someone mid-streak. `owed()` floors at zero rather than implying a clawback.
- **Consumed rows count towards the allowance.** Having *had* the free challenge is what the allowance measures;
  otherwise `/start` would mint a fresh free slot after every join, forever. Tested explicitly, because the
  naive `available()`-based version of this check is the obvious way to write it and is wrong.
- **`CoinLedger::lockUser()` is now public** (the old private `lock()` is gone; `record()` calls the public one).
  This is the security-relevant change in the task, and the reason is a real race, not tidiness. A slot purchase
  has to insert the `Entitlement` **before** the debit, so the ledger entry's `reference` morph can point at it.
  If the insert happened outside the per-user mutex, two concurrent replays of the same purchase would both
  insert a slot while `CoinLedger` correctly charged only once — leaving **an unpaid slot behind**. Holding the
  mutex across the replay check *and* the insert closes that window. Re-taking the same lock inside `debit()` is
  a no-op, so there is no deadlock, and all three Actions take it, so every per-user economy operation now
  serialises on one row.
- **`lockUser()` throws when `DB::transactionLevel() === 0`.** A `lockForUpdate()` on an autocommitted select
  releases the instant the statement finishes, so a caller who forgot the transaction would get no
  serialisation and, worse, no warning. Failing loudly is the only safe behaviour for a money path.
- **A refused purchase leaves no slot.** Entitlement-first ordering means the rollback is the only thing between
  `InsufficientCoinsException` and a free slot, so that is asserted directly rather than assumed from the
  `DB::transaction` wrapper.
- **A replayed purchase returns the slot the original bought**, found through the ledger entry's `reference`
  morph. If that reference is not an `Entitlement` of the right type belonging to the right user, it throws
  `LogicException` instead of handing back something unrelated: a purchase key reused across slot types would
  otherwise return a join slot to someone buying a create slot and report success.
- **Selling a slot priced at 0 (or less) is refused.** A free extra slot is an allowance change —
  `GrantFreeBaseline`'s job — and writing a `CoinPurchase` row for zero coins would misreport the slot as paid
  for, which matters when a refund path later asks what was actually charged.
- **Consumption is recorded against the challenge, not merely timestamped.** That is what makes it idempotent: a
  double-tapped join finds the row already spent on that challenge and returns it rather than eating a second
  slot. `challenge_id` is `nullOnDelete` and `consumed_at` is not, so **deleting a challenge is not a refund
  route** — the slot stays spent. Both halves tested.
- **Create and join are metered separately on the same challenge.** `spentOn()` scopes by `type` *and*
  `challenge_id`, so a creator who also takes part spends two different slots on one challenge and neither
  satisfies a claim for the other.
- **Free slots are spent before paid ones** (`orderByRaw` on source, then `id`). A `FreeBaseline` slot is never
  billed and never refunded, so it has no residual value; a `CoinPurchase` slot cost real money. Spending the
  worthless one first leaves the user holding the one that is worth something.
- **`NoEntitlementAvailableException` carries the `EntitlementType`**, because the recovery path depends on it:
  the bot turns this into "you have used your free challenge — buy another create slot for N coins?" and needs
  to know which price to quote. `PurchaseEntitlement::priceOf()` is public for exactly that prompt.

**Tests — 41 new** (`tests/Feature/Domain/EntitlementsTest.php`, 38; `tests/Feature/Domain/CoinLedgerLockTest.php`, 3).
Four groups: the free baseline, buying an extra slot, spending a slot, and the mutex. Datasets cover both slot
types against their own price setting and ledger reason, and both non-positive prices.

**A test-infrastructure trap worth remembering.** The `lockUser()` guard **cannot be tested under
`RefreshDatabase`** — it wraps every test in a transaction, so `DB::transactionLevel()` is never 0 and the guard
can never fire. The first version of that test failed for exactly this reason. It now lives in its own file
using `DatabaseTruncation` purely because that trait leaves the ambient transaction level alone, with a comment
saying **do not add `RefreshDatabase` here** and a third test asserting `transactionLevel() === 0` so that a
future global `uses(RefreshDatabase::class)` in `tests/Pest.php` fails loudly instead of quietly hollowing the
file out. Nothing in that file writes, so unlike `CoinLedgerConcurrencyTest` it needs no `afterEach` cleanup.

**Assumptions / follow-ups recorded**

- **No release/refund path for a consumed slot.** Leaving a challenge does not return the slot, and nothing in
  scope here does. If "leave a challenge" ever refunds, it needs a deliberate Action with its own idempotency
  key — not an `update()` clearing `consumed_at`, which would let someone farm one free slot across unlimited
  challenges.
- **`entitlements` has no unique index**, so "one free create slot per user" is enforced by the count-then-insert
  under the mutex rather than by the schema. That is sufficient given every writer goes through
  `GrantFreeBaseline`, but it is a convention, not a constraint — worth revisiting if a seeder or admin tool ever
  writes entitlements directly.
- **Concurrency here is argued, not forked.** The mutex is the same one `CoinLedgerConcurrencyTest` already
  proves blocks with real forked writers; these tests pin the ordering and idempotency requirements rather than
  re-running the fork harness for three more Actions. If the free-baseline top-up ever moves off the shared lock,
  it needs its own parallel test.

**Result — `sail composer ci:check` GREEN:** eslint ✓, prettier ✓, `tsc --noEmit` ✓, pint ✓,
phpstan lvl 7 (0 errors) ✓, tests **458 (454 pass, 4 skipped = Fortify 2FA disabled)**, +41 from this task.

**Next:** Domain Task 6 — the streak / freeze / miss engine. Advance a participant through a closed period:
approved check-in extends `current_streak` and `longest_streak`; a miss with a freeze left burns one
(`freezes_used`) and leaves the streak intact; a miss with none resets the streak to 0, increments the new
`streak_resets_count`, and **leaves the participant in the challenge** (no auto-removal — baked-in decision).
Late joiners owe nothing before `joined_period_index`. Must be idempotent per `(participant, period)` so period
rollover can be re-run.

---

## Domain Task 6 — streak / freeze / miss engine (done)

Two Actions and one exception: `App\Actions\CheckIns\SettleCheckIn` (the only place a streak moves),
`App\Actions\Challenges\RollOverPeriod` (the sweep that closes a period), and
`App\Exceptions\PeriodNotEndedException`. Plus one method on `CheckInStatus` — `consumesFreeze()` — joining the
`incrementsStreak()` / `breaksStreak()` / `preservesStreak()` predicates that already existed from Domain Task 1.
No migration: `streak_resets_count` was added to `challenge_participants` in Domain Task 1 precisely so this task
would not need one.

**Decisions**

- **The status transition *is* the idempotency token.** No separate "already counted" flag, no `settled_at`
  column. `SettleCheckIn` refuses to re-settle a row whose status `isSettled()`, and the status write and the
  counter writes share one transaction — so a streak can only move on the transition *into* a settled status,
  which by definition happens once. A retried webhook, a double-tapped button and a twice-run sweep are all the
  same no-op. The alternative (a counter flag) would be a second source of truth that can disagree with the
  status.
- **The participant row is locked before anything is read.** `current_streak`, `longest_streak`, `freezes_used`
  and `streak_resets_count` are all read-then-write, and two settlements for one participant genuinely arrive
  together: the nightly sweep closing yesterday while the participant taps today's button. Without the lock both
  read the same streak and one increment silently vanishes. It also makes the freeze decision atomic — *"is a
  freeze available?"* and *"spend it"* are one step under the lock, so a participant with one freeze left cannot
  have two periods frozen by it. Tested directly.
- **`lockParticipant()` deliberately avoids `$checkIn->participant`.** An already-hydrated relation may hold
  counters from before another settlement committed, and the arithmetic would then write a stale streak back.
  Same reason `$checkIn->refresh()` runs inside the lock rather than trusting the caller's instance. Both have
  their own test.
- **A freeze protects a streak; it does not extend one.** `Frozen` touches `freezes_used` only. The participant
  did not do the thing — they spent a freeze to avoid the penalty — so `current_streak` and `longest_streak` both
  stand still. This is why `preservesStreak()` and `incrementsStreak()` are two separate predicates.
- **A reset touches `current_streak` only.** `longest_streak` is a personal best and stays on the record; the
  reset is counted in `streak_resets_count` and `status` is untouched, so the participant stays `Active`. Asserted
  explicitly, including three consecutive resets, because "no auto-removal" is a settled product decision and the
  test is what stops a future contributor helpfully adding one.
- **`SettleCheckIn::approve()` returns the settled row, and the caller must read its status.** Approving a period
  the rollover has already closed returns a `Missed` row rather than throwing or silently overwriting. Late
  review is a real scenario (a creator who reviews photos the next morning), and neither rewriting history nor
  losing the fact matters less than telling the caller. Recorded as a follow-up for Domain Task 9, which owns the
  message.
- **`Rejected` is settleable.** It is not an ending — resubmission is allowed while the period is open — so a
  better photo can still win the streak. Only the rollover decides a rejected period was actually lost.
- **`RollOverPeriod` refuses to run before `ends_at`.** Settling early is destructive in a way settling late is
  not: everyone who has not checked in yet would be marked `Missed` while they still had time. `hasEnded()`
  treats `ends_at` as exclusive, so the boundary instant closes the period — tested a second either side.
  `$now` is injectable so the scheduled sweep and the tests agree on the instant rather than racing the clock.
- **No early return on `rolled_over_at`.** This is the deliberate choice in the class. If a previous attempt
  marked the period swept but died partway through the participant list, an early return would leave the rest
  unsettled *forever*. Idempotency lives at the row level instead, so a re-run only ever completes work.
  `rolled_over_at` is the "already swept" marker that keeps the `awaitingRollover()` scope from re-scanning, not
  the guard — and it is not restamped on a re-run, so it keeps meaning *when the period was first closed*. Both
  behaviours tested, including a hand-built half-finished sweep.
- **Who owes a period is decided in `RollOverPeriod`; what a settlement does is decided in `SettleCheckIn`.**
  Two exclusions live in the query: late joiners (`joined_period_index <= period.index`) and anyone not `Active`.
  Neither class second-guesses the other, which is what keeps the check-in path and the sweep path from drifting.
- **A participant who never opened the bot still missed the period.** `firstOrCreate` on
  `(participant, period)` materialises the obligation during the sweep; the unique index makes that race-safe by
  turning a concurrent insert into a read of the winner's row.
- **Chunked at 200 participants.** A popular public challenge has a long list and the sweep already holds one
  settled check-in per participant, so the participant models are not also held all at once.

**Tests — 35 new** (`tests/Feature/Domain/StreakEngineTest.php`), three groups: approving, closing a missed
period, and rolling a period over. Notable coverage: one freeze cannot cover two periods; the full
freeze-budget-then-break sequence; a stale hydrated relation not eating an increment; late joiners spared history
and judged from their join index; a dataset over `left` / `removed` / `completed` proving a departed participant
stops accruing misses; another challenge's participants never touched; a re-run changing nothing; a half-finished
sweep healed; and one end-to-end timeline settling `Approved, Approved, Frozen, Missed` with all four counters
asserted.

**Assumptions / follow-ups recorded**

- **Challenge completion is not implemented and is deliberately out of scope.** `main.md` §5 has no Phase 2 task
  for it. Nothing yet moves a participant to `Completed` after the final period, moves a challenge to
  `Completed`, or credits the flat completion reward. When it lands it needs its own Action with its own
  idempotency key (`ChallengeCompletionCoinReward` from `Setting`, credited via `CoinLedger`) — **not** a hook
  inside `advance()`, which runs per period and would pay out repeatedly. Note that `RollOverPeriod` already
  skips `Completed` participants, so the ordering works out.
- **A late approval returning a `Missed` row needs surfacing in the UI.** Domain Task 9 (`SubmitCheckIn` +
  review actions) owns telling the creator "this period already closed" instead of reporting success. The domain
  layer reports it honestly; no surface reads it yet.
- **Nothing calls `RollOverPeriod` on a schedule yet.** The `awaitingRollover()` scope exists and is asserted, but
  the scheduled command that sweeps it belongs to the bot/reminder phase. Until then no period ever closes
  automatically.
- **Concurrency here is argued from the lock, not forked.** The participant mutex is the same mechanism
  `CoinLedgerConcurrencyTest` already proves blocks with real forked writers; these tests pin the atomicity
  requirements (one freeze, one reset, one increment) rather than re-running the fork harness.

**Result — `sail composer ci:check` GREEN:** eslint ✓, prettier ✓, `tsc --noEmit` ✓, pint ✓,
phpstan lvl 7 (0 errors) ✓, tests **493 (489 pass, 4 skipped = Fortify 2FA disabled)**, +35 from this task.

**Next:** Domain Task 7 — invites. Generate a unique `Invite` code per inviter, attribute it on `/start`, and
credit coins **only if the invited user is brand-new to the bot** (first-ever `/start`, no prior user row) —
through `CoinLedger` with an idempotency key so a replayed `/start` cannot pay twice. The invite→coin rate comes
from `Setting`. Self-invites and re-using a code for an existing user must credit nothing.

---

## Domain Task 7 — invites (done)

Two Actions in `app/Actions/Invites/` — `IssueInviteCode` and `ClaimInvite` — plus `App\Enums\InviteRejection`
and `App\Exceptions\InviteNotClaimableException`. No migration and no model changes: the `invites` table,
`InviteStatus`, `Invite::open()`/`credited()`/`deepLink()`, `User::sentInvites()`/`claimedInvite()`/`referrals()`
and `CoinTransactionReason::InviteCredit` all already existed from Domain Tasks 1–2 and are used as-is.

**Decisions**

- **Eligibility is `$invitee->wasRecentlyCreated`, not an argument.** This is the security-relevant choice in the
  task. The obvious signature is `handle(User $invitee, string $code, bool $isNew)`, and it is wrong: it puts
  *"should I be paid?"* in the hands of the caller on a money path, and the caller is a webhook handler acting on
  client input. `wasRecentlyCreated` is true only on the instance that performed the INSERT in this process, which
  is precisely the question being asked and is not reachable from the wire. It also **fails closed** — a
  re-loaded model reports false and the inviter goes unpaid. Under-paying is a support conversation;
  over-paying is a mint. The contract this creates is documented on `handle()`: **pass the instance the arrival
  created or found**, i.e. what `firstOrCreate` returned on this `/start`.
- **`Claimed` and `Credited` are both success.** An existing user redeeming a code is attributed —
  `referred_by_user_id` is set, the inviter sees them in `referrals()`, the code is spent — but no coins move.
  Collapsing the two would make "used but unpaid" indistinguishable from "never used", which was already the
  documented reason the enum has three cases.
- **A zero or negative rate settles as `Claimed`, not `Credited`.** Paying nothing is not a payment, and
  `wasPaid()` has to keep meaning what it says — it will matter to a refund or audit view later. An admin who
  zeroes `InviteCoinReward` has switched rewards off, not made them free. Tested with a dataset over `0` and `-5`.
- **The ledger key is `invite:<id>` — one payment per invite row, for all time.** Not per claim, not per
  `(inviter, invitee)`. Proven directly by resetting a claimed row through the query builder and re-claiming it:
  the status checks are bypassed and the ledger still refuses to pay twice. That is the last line of defence, so
  it is asserted rather than assumed.
- **The lock is on the invite row, not on either user.** The contended resource is the *code*: two brand-new
  users tapping one link must not both be attributed to it. Locking the invite and letting `CoinLedger` take the
  inviter's lock inside `credit()` also fixes the order as invite → user for every caller, so claims can never
  deadlock against each other or against a slot purchase.
- **`alreadyAttributed()` queries the database rather than reading `referred_by_user_id`** off the in-memory
  model, which may be a stale null. The unique index on `invited_user_id` is the real backstop; this check exists
  so the caller gets a domain exception it can explain instead of a constraint violation it cannot.
- **Codes stay single-use, because the schema says so.** `invites.code` unique + `invites.invited_user_id` unique
  means one row attributes exactly one arrival — a decision made and documented in Domain Task 2, not revisited
  here. `IssueInviteCode::handle()` therefore means *"the inviter's current link"*: it returns the oldest open
  code they already have rather than minting one per screen view, so a link copied yesterday still works today.
  `mint()` forces a fresh one for "give me another link". Two concurrent `handle()` calls can both mint; both
  codes are valid and both credit, so that is not worth a lock.
- **Code alphabet excludes `i`, `l`, `o`, `0` and `1`.** Ten characters of the remaining 31 is ~49 bits — a
  collision is a non-event and a guess is pointless — and codes get read off one screen and typed into another,
  so every ambiguous character is a support message. `random_int` rather than `rand`, because a guessable code
  lets a stranger take credit for an arrival. Collisions are handled by **re-rolling against the unique index**,
  not by a pre-flight existence check, which is the only version that is safe under concurrency; five attempts,
  after which it rethrows, because at 49 bits a repeat collision means the generator is broken.
- **Codes are matched case-insensitively and trimmed.** `ClaimInvite::normalise()` is public so the bot echoes
  back the form that actually matched. This is what makes a link survive a phone keyboard capitalising the first
  letter.
- **One exception class with four named constructors, carrying an `InviteRejection`**, rather than four exception
  classes — every caller handles these together (`/start` catches and picks a reply) and the reason is what
  selects the reply. `InviteRejection` deliberately has **no** `label()`, for the same reason `ConversationState`
  has none: these select which sentence the bot sends, not a noun to render, and those sentences are their own
  lang lines. So no new `lang/*/enums.php` entries and nothing to add to the enum-translation dataset.

**Tests — 32 new** (`tests/Feature/Domain/InvitesTest.php`), five groups: minting, claiming as brand-new,
claiming as a returning user, refusals, and a retried `/start`, plus the ledger over time. Notable coverage: the
same link handed back on repeat asks; a fresh one minted only after the last was claimed; charset and length
asserted over 40 generated codes and uniqueness over 60; self-invite, unknown code, already-claimed and
already-attributed each asserting the `reason` *and* that nothing was written; two arrivals racing one code
paying the inviter once; the rate not applying retroactively (`10` then `25` → ledger `[25, 10]`); and
`drift() === 0` so the invite path cannot desynchronise the running balance.

**A trap that cost a debug cycle — now recorded in `.ai/rules/exceptions.md`.** `InviteNotClaimableException`
originally carried `public readonly string $code`. `Exception` already declares a non-readonly `$code`, so
redeclaring it readonly is a **compile-time fatal** raised when the class is autoloaded — and under Pest's agent
output format that killed the entire run with exit 1 and **zero bytes of output**, which reads like a segfault
rather than a type error. `php -l` passed; the real message only appeared via
`sail artisan tinker --execute 'throw …'`. The property is now `$inviteCode`, with a comment saying why, and the
rule is filed so the next exception with a payload does not repeat it. **Diagnostic worth remembering: a Pest run
that exits 1 with no output is a fatal during class loading, not a crash — reproduce it in tinker.**

**A second, smaller trap.** `$invite->update(['status' => Pending, …])` on a model whose in-memory attributes
already hold those values writes **nothing** — Eloquent only sends dirty attributes, and the row had been changed
underneath by another instance. A test that means "the row changed underneath the model" has to use the query
builder.

**Assumptions / follow-ups recorded**

- **A single-use code cannot be posted to a group chat and credit everyone.** First tap wins; the rest get
  `AlreadyClaimed`. That follows from the Task 2 schema and is the honest reading of it, but it is a real product
  limitation worth a decision in Phase 3: the bot can either mint a small batch of codes on `/invite`, or the
  schema gains a `max_uses` column / an `invite_claims` child table. **Not** something to change silently — it
  would need a migration and a rewrite of the uniqueness argument above.
- **Nothing calls these yet.** `/start` invite attribution, the channel gate and `GrantFreeBaseline` are composed
  in Bot Core (Phase 3). The `?startapp=` Mini App path also carries a code (`start_param` in initData **and** the
  server-visible `tgWebAppStartParam` GET parameter) and will reuse `ClaimInvite` unchanged.
- **`wasRecentlyCreated` does not survive a queue boundary**, which is fine as designed: the queued webhook job
  does its own `firstOrCreate` and so holds a genuinely fresh instance. If the user is ever created in one job
  and claimed in another, eligibility must be carried explicitly and deliberately — not by re-loading and hoping.
- **Factory-built users report `wasRecentlyCreated`**, so the test suite needs `returning()` (a re-load) to
  express "this person was already here". Documented in the test file, because the default reads the wrong way
  round.
- **No un-attribution path.** Nothing releases a claimed invite or reverses an `InviteCredit`. A refund would
  need its own Action, its own idempotency key, and a decision about whether the invitee stays attributed.

**Result — `sail composer ci:check` GREEN:** eslint ✓, prettier ✓, `tsc --noEmit` ✓, pint ✓,
phpstan lvl 7 (0 errors) ✓, tests **525 (521 pass, 4 skipped = Fortify 2FA disabled)**, +32 from this task.

**Next:** Domain Task 8 — per-participant-per-period phrase generation for `proof_type = text_autogen`. Generate
a unique, meaningful phrase for **each participant in each period** (per-participant, *not* per-period, so
participants cannot paste the phrase to each other and defeat the mechanic), store it on
`CheckIn.expected_phrase`, and match it on normalised exact comparison. `CheckIn::normalisePhrase()` and
`matchesExpectedPhrase()` already exist from Domain Task 1 — reuse them. Must be deterministic-per-row or
generated once and persisted, so a re-run of period materialisation does not change a phrase a participant is
already looking at. Phrases must work in both Farsi and English.



---

## Domain Task 8 — per-participant-per-period phrase generation (done)

**Commit:** `5deeea4 feat(check-ins): issue a per-participant, per-period proof phrase`

**What shipped**

| File | Role |
|---|---|
| `app/Actions/CheckIns/IssueCheckInPhrase.php` | issue / generate the phrase |
| `app/Actions/CheckIns/OpenCheckIn.php` | `(participant, period) → CheckIn`, extracted from `RollOverPeriod` |
| `app/Exceptions/PhraseUnavailableException.php` | `vocabularyMissing()` / `noFreePhrase()` |
| `…add_phrase_uniqueness_to_check_ins_table.php` | unique `(challenge_period_id, expected_phrase)` |
| `lang/en/phrases.php`, `lang/fa/phrases.php` | server-only vocabulary banks |
| `app/Services/Localization.php` | new `best(...$candidates)` + `toSupportedCode()` |
| `app/Http/Middleware/SetLocale.php` | now delegates to `best()` (≈30 lines → 5) |

**The decision that shaped the rest: distinctness is structural, not probabilistic.**
The attack this mechanic closes is *sharing* — the first person to check in pastes the phrase into the group
chat and nobody else has to do the thing — **not** guessing. A participant already knows their own phrase, so
entropy protects nothing. What has to hold is that two participants in the same period get different phrases.
So that is a **unique index on `(challenge_period_id, expected_phrase)`** with a re-roll on rejection, mirroring
`IssueInviteCode::mint()`. Hoping 216,000 combinations never collide would be a *statement about likelihood*
where the product needs a *guarantee*; the index also means a future bug that narrows the vocabulary fails loudly
instead of quietly handing a whole challenge the same words. NULLs are distinct in a MySQL unique index, so the
many rows that never carry a phrase (`button`, `image_approval`) are unaffected.

**Generate once, persist, never recompute** — chosen over deriving the phrase deterministically from
`(participant_id, period_id)`. A participant reads the phrase off a reminder and types it back hours later, so a
second call must not produce a different string. Derivation also fails a subtler test: it would silently *change*
the phrase if the user switched locale between reading and typing. Persisted, the locale is frozen at issue time —
there is a test for exactly that.

**Stored phrases are already in `CheckIn::normalisePhrase()` form.** `generate()` puts its own output through
that method, so the string shown to the participant and the string their answer is folded into cannot drift, and
the unique index compares *canonical* forms — `Blue Anchor 42` cannot slip past it alongside `blue anchor 42`.
This is why the banks use **ASCII digits in both locales**: the normaliser folds `۰-۹` and `٠-٩` to ASCII, so a
Farsi participant may type either and the canonical form stays ASCII.

**The row lock is not ceremony.** Two reminders for the same row can land together; without it both read a null
phrase, both generate, and the loser overwrites the phrase the participant is already reading. `handle()` locks
the check-in row, refreshes, and re-checks — the same lock-then-refresh shape as `SettleCheckIn`. The locked row
is deliberately discarded and the caller's instance refreshed instead, because that instance is the one that has
to come back carrying the phrase. Retrying the insert *inside* the open transaction is safe on MySQL, where a
duplicate-key error rolls back the statement and not the transaction. **That is MySQL-specific and load-bearing** —
on Postgres this would need a savepoint per attempt.

**Farsi is not the English template reversed.** Persian puts the adjective after the noun, so each locale owns
its own `template` (`:noun :adjective :number` vs `:adjective :noun :number`). Two further Farsi rules are
enforced by test: **no ZWNJ (U+200C)** — the normaliser folds it to a space, and nobody retypes an invisible
character consistently — and **Persian ی/ک (U+06CC/U+06A9), never the Arabic ي/ك**, since the normaliser folds
Arabic → Persian and a bank word must equal its own normalised form. A test asserts that over every word in both
banks.

**`Localization::best()` rather than a second copy of tag-folding.** `SetLocale` resolves a *request*
(user → cookie → `Accept-Language` → fallback). Anything addressing a *specific user outside a request* — a
queued reminder, a phrase — must resolve per user, because `app()->getLocale()` in a worker belongs to whoever
was handled last. Both now arrive at one allowlisting method. It tries the full tag before the primary subtag, so
a future `zh-hans` entry would match exactly rather than collapsing to `zh`; there are traversal tests, because
the result is interpolated into a path that gets `require`d.

**`OpenCheckIn` extracted** from `RollOverPeriod::obligationFor()`. Three callers now need "the row for this
(participant, period)" — the rollover sweep, the bot's check-in prompt, a reminder issuing a phrase — and
`firstOrCreate` there is race-safe rather than merely convenient (it delegates to `createOrFirst`, which catches
the unique violation and re-reads the winner). It deliberately does **not** check `owesPeriod()`: who owes a
period belongs to the caller that decided to open the row, and answering it in two places is how the two answers
start to disagree.

**`phrases` is not in `localization.client_groups`**, so the bank never reaches a browser bundle. Asserted, not
assumed. It leaks nobody's phrase, but advertising the shape of every phrase buys nothing.

**Assumptions / follow-ups recorded**

- **No fan-out.** There is no `IssueCheckInPhrase::forPeriod()`, because it would duplicate
  `RollOverPeriod::participantsOwing()` (late joiners, non-`Active` participants). The **reminder job in Phase 3
  owns the fan-out** and calls `forParticipant()` per participant — which is also where the staggered
  `delay()` for Telegram's rate limit belongs.
- **Nothing issues a phrase yet.** Domain Task 9's `SubmitCheckIn` is the first real caller; the bot prompt and
  the reminder follow in Phase 3.
- **`MINT_ATTEMPTS = 5`.** With ~200k combinations, five collisions in a row means the vocabulary is far too
  small for the challenge's size, so it throws rather than loops. If a challenge ever plausibly has thousands of
  participants in one period, add words — not attempts.
- **A blank phrase is not treated as unissued.** Only `null` is. `expected_phrase` is written by this class alone
  and never to a blank, so treating blank as unissued could only paper over a corrupt row — and would re-roll a
  phrase somebody is looking at to do it.
- **The number is never zero-padded** (`10`–`99`). A leading zero is a support message, not entropy.

**Result — `sail composer ci:check` GREEN:** eslint ✓, prettier ✓, `tsc --noEmit` ✓, pint ✓,
phpstan lvl 7 (0 errors) ✓, tests **572 (568 pass, 4 skipped = Fortify 2FA disabled)**, 1739 assertions,
+47 from this task.

**Next:** Domain Task 9 — `SubmitCheckIn` plus the review actions, closing out Domain core. One Action every
surface calls: resolve the actor's participant row server-side (never a client-supplied `participant_id`), open
the obligation via `OpenCheckIn`, and branch on `proof_type` — `button` auto-approves, `text_autogen` compares
via `matchesExpectedPhrase()` and auto-approves on a match, `image_approval` stores the proof and leaves the row
`Submitted` for the creator. Then `ApproveCheckIn` / `RejectCheckIn` for the creator, authorising on challenge
ownership. **Must surface the late-approval case:** `SettleCheckIn::approve()` returns a `Missed` row untouched
when the rollover already closed the period, and a creator reviewing late has to be told that rather than shown
a success message.

---

## Domain Task 9 — `SubmitCheckIn` + review actions (done) — commit `92b4710`

Closes out **Domain core**. Every surface now has one place to send proof and one place to record a verdict.

**Built**

- `app/Actions/CheckIns/SubmitCheckIn.php` — three entry points named for what the participant *did*:
  `tap()`, `typePhrase()`, `uploadPhoto()`. Each asserts the challenge actually asks for that proof type, then
  shares `openSubmittable()`: proof-type check → challenge accepts check-ins → resolve participant → resolve
  open period → `OpenCheckIn` → settled/awaiting-review guards.
- `app/Actions/CheckIns/ReviewCheckIn.php` — `approve()` / `reject()` for the creator, plus `authorise()`.
- `app/Enums/CheckInRejection.php` — 10 cases. No `label()`, matching `InviteRejection`: these select which
  sentence a surface sends, and those sentences are their own lang lines.
- `app/Exceptions/CheckInRejectedException.php` — one class, `reason` + optional `checkIn`, 10 named ctors.
- `app/Models/ChallengePeriod.php` — `#[Scope] containing(?CarbonInterface)`: `starts_at <= $m < ends_at`, the
  query-side twin of the existing `contains()`.

**Decisions**

- **A returned `CheckIn` always means the proof was accepted; everything else throws.** The `SettleCheckIn`
  precedent is "return the row, caller reads the status", and it was the wrong shape here: a wrong phrase leaves
  the row `Pending` and a second tap leaves it `Approved`, so a surface distinguishing success from failure by
  reading a status would eventually render "checked in!" for a typo. `InviteNotClaimableException` +
  `InviteRejection` already established the pattern for routine, catchable refusals — this follows it.
- **Nothing is taken from the caller but the proof.** The signatures ask for the *verified actor* and a
  `Challenge`; there is nowhere to put a `participant_id`, so the class of bug CLAUDE.md warns about is not
  merely rejected, it is unwritable. The period is resolved from the clock for the same reason: a stale callback
  button from last week's reminder cannot backdate a check-in.
- **Three named methods, not a proof DTO.** A DTO would mean a new base folder (Boost forbids without approval)
  and the named-verb shape matches `SettleCheckIn::approve()`/`close()`.
- **`typePhrase()` issues the phrase on demand** via `IssueCheckInPhrase::handle()`. A participant can open the
  Mini App before any reminder went out; comparing against a null phrase would refuse an answer nobody could
  have known. The mismatch path still leaves the phrase persisted, so the surface can then show it.
- **A wrong phrase is not stored.** `submitted_text` means "the proof this row was settled on"; filling it with
  a typo would put a `submitted_at` on a row that was never submitted.
- **The successful phrase write and its settlement share one transaction**, so a settlement lost to the rollover
  takes the recorded submission down with it rather than leaving a `Missed` row claiming an on-time submission.
- **A photo resubmission clears `reviewed_by`/`reviewed_at`.** Otherwise the new photo looks already decided and
  drops out of the creator's queue unseen.
- **Approving is deliberately *not* idempotent.** `SettleCheckIn::approve()` returns an already-`Approved` row
  untouched by design, so the `!== Approved` race-backstop cannot see a double approval — the first version of
  this let a second admin silently overwrite the creator's `reviewed_by`. Caught by the test, fixed with
  `guardAwaitingVerdict()` running *before* the settle. The backstop stays for the genuine race.
- **`guardAwaitingVerdict()` distinguishes `AlreadySettled` from `NotAwaitingReview`** — "that period closed as
  missed" and "there is no photo on the table" are different sentences, and a creator clicking an old inline
  button deserves the right one. It refreshes inside the transaction, so a stale instance cannot decide.
- **Admins may review.** They answer the support ticket when a creator goes quiet mid-challenge and a queue of
  photos strands everybody's streak. A creator who is also a participant may approve their own photo: they chose
  the proof type and could have picked `button`, and it is visible in `reviewed_by` rather than hidden.
- **Rejection is not an ending.** It returns the row to a state that accepts another photo; only the rollover
  decides the period was lost. One rule in one place — the streak engine only ever looks for `Missed`.

**Tests** — `tests/Feature/Domain/SubmitCheckInTest.php` + `ReviewCheckInTest.php`, **54 tests / 115
assertions**. New global helpers: `provenBy`, `enrol`, `refusalFor`, `awaitingVerdict`, `verdictRefusal`.
All 10 rejection reasons are exercised. Notable coverage: each proof type's happy path; a phrase retyped
shouted / padded / double-spaced; another participant's phrase refused; A's tap only ever settling A's row;
`Left`/`Removed`/`Completed` participants and `Scheduled`/`Completed`/`Cancelled` challenges refused; the
`ends_at`-exclusive boundary in both directions; a late approval of a rollover-`Missed` row leaving
`reviewed_by` null; ownership derived from the row when a creator holds another challenge's check-in id.

**Assumptions / follow-ups recorded**

- **`uploadPhoto()` takes a stored path, not an upload.** Resolving a Telegram `file_id` or moving an
  `UploadedFile` belongs to the surface; keeping it out is what lets the bot and the API share the method.
  Phase 3/5 own that, including the storage disk and any size/mime validation.
- **No `Setting`-driven grace period.** A submission is accepted only while its period is open. If late
  check-ins are ever wanted, that is a `Setting` read inside `openPeriod()`, not a new Action.
- **`ChallengePeriod::containing()` assumes non-overlapping periods**, which `MaterialisePeriods` guarantees.
  It takes `->first()`, so an overlap would silently pick the lowest id.
- **No notification on a verdict.** The creator's approve/reject does not yet tell the participant — that is a
  Phase 3 job, and it should read `reviewed_by` to know who to name.
- **Still no completion detection.** Nothing marks a challenge or participant `Completed`, so the flat
  completion reward (a `Setting`) is unimplemented. Carried from Task 6; now the last Domain-core gap.

**Result — `sail composer ci:check` GREEN:** eslint ✓, prettier ✓, `tsc --noEmit` ✓, pint ✓,
phpstan lvl 7 (0 errors) ✓, tests **626 (622 pass, 4 skipped = Fortify 2FA disabled)**, 1854 assertions,
+54 from this task. Graph: 2149 nodes / 3592 edges.

**Next:** **Domain core is complete.** Per the roadmap the next phase is Bot Core Task 1 — the webhook
controller: record the update idempotently on `update_id`, return 200 immediately, dispatch a queued job to
process it. Then the channel gate (`getChatMember` on `/start`, asserting a non-empty `required_channel`).
**Before starting, read `prompts/phase-8.md`, `phase-9.md`, `phase-10.md`, `main-2.md` and
`goal-phases-8to10.md`** — these appeared untracked during Task 8 and were not authored by this build loop;
they may extend the definition of done past the original seven phases and could reorder what comes next.

---

## Roadmap extended to ten phases (noted 2026-08-25, no code)

Five prompt files appeared untracked during Task 8 and were not authored by this build loop:
`new-ideas-TODO.md` (the user's raw asks), `main-2.md` (spec addendum §2.6–2.8, §3.5–3.7, §5, §7),
`phase-8.md`, `phase-9.md`, `phase-10.md`, and `goal-phases-8to10.md` (a second build driver).

- **Phase 8 — creator-owned chats.** Register a channel/group as a challenge's home chat; check-in
  announcements, daily + on-demand leaderboard. Dual admin verification (bot *and* creator), re-checked before
  every post. `ChallengeChat.share_proof_media` defaults false and **cannot** be true on a
  `proof_is_public = false` challenge.
- **Phase 9 — timed & stepped challenges.** `flow_type = timed_session` (orthogonal to `proof_type`),
  `challenge_steps` / `checkin_sessions` / `checkin_step_submissions`, min-wait gates, voice duration limits.
  Sessions settle **through the existing `SettleCheckIn`**, never a parallel engine.
- **Phase 10 — AI-assisted proof approval.** `approval_mode = ai` for `image_approval`; platform-authored
  system prompt, creator criteria passed as delimited data and screened before storage, locked output schema,
  low-confidence/error → manual queue. Needs a new `ReverseCheckIn` for admin overrides of settled rows.

**Build order is unchanged.** `goal-phases-8to10.md` §"Before the first run" says to stop and finish Phases 1–7
with the original `goal.md` first, because Phase 9 calls the Phase 2 settlement Actions directly and Phase 8
posts on top of Phase 3's bot core. So: Bot Core next, as planned; Phases 8–10 after Website.

**Two things to carry forward**

- **`main-2.md` §3.5 assumes settlement events already exist** — "creator chats … subscribe to the same
  check-in/settlement events the Mini App and bot already produce." **They do not.** Nothing in Domain core
  dispatches an event. A `CheckInSettled` event on `SettleCheckIn` is therefore net-new work owned by Phase 8
  Task 2, not a free side effect. Not built speculatively now: an event with no listener is infrastructure
  guessing at its own consumers, and Phase 8 knows the payload it needs.
- **File names in `goal-phases-8to10.md` do not match what is on disk.** It cites
  `prompts/main-addendum-2.md` and `task-08-01`/`08-02`/`09-01`…`10-02`; the actual files are
  `prompts/main-2.md` and `prompts/phase-8.md`/`phase-9.md`/`phase-10.md`. Same content, different names —
  resolve by content, not by path.

---

# Phase 3 — Bot core

## Bot Core Task 1 — webhook intake (done) — commit `807989c`

**Built**

- `app/Http/Requests/Telegram/WebhookRequest.php` — `authorize()` verifies both secrets, `rules()` requires an
  integer `update_id`.
- `app/Http/Controllers/Telegram/WebhookController.php` — invokable, two lines of body.
- `app/Actions/Telegram/IngestTelegramUpdate.php` — `handle(int $updateId, array $payload): TelegramUpdate`.
- `app/Jobs/Telegram/ProcessTelegramUpdate.php` — first job in the app; `app/Jobs/` is new but standard.
- `routes/telegram.php` — placeholder closure replaced by the controller.
- `config/services.php` + `.env` + `.env.example` — new `webhook_header_secret` / `TELEGRAM_WEBHOOK_HEADER_SECRET`.

**Decisions**

- **Two independent webhook secrets, both required.** A path segment *and* the
  `X-Telegram-Bot-Api-Secret-Token` header, from two different config keys. The asymmetry that matters: a URL
  ends up in reverse-proxy access logs, shell history and screenshots of a `setWebhook` call; a header does not.
  That is precisely why Telegram added `secret_token` on top of "use a secret path", and reusing one value for
  both would throw the benefit away — with two, a leaked URL alone still cannot forge an update. Compared with
  `hash_equals`.
- **Fail closed on an unset secret.** An empty `webhook_secret` or `webhook_header_secret` refuses everything.
  The alternative — treating "not configured" as "no check" — is an open write endpoint that looks healthy.
- **404 on a secret mismatch, not 403.** A probe should not be able to tell a real webhook path with a wrong
  secret from a path that was never routed. `{token?}` is optional precisely so a call with no token reaches the
  check and gets the same 404 rather than a routing error that confirms the URL shape. Which check failed is
  logged (`check` => `unconfigured|path|header`) so an operator debugging a silent bot has the signal a caller
  is denied.
- **422 on a body with no integer `update_id`.** It is the idempotency key for the entire pipeline; a body
  without one is not an update, and a Form Request is where CLAUDE.md puts input validation.
- **An ingest failure is *not* swallowed into a 200.** This is the one place the "always return 200" rule is
  wrong: a 200 tells Telegram the update arrived, and Telegram never redelivers what it believes was delivered,
  so a database blip would lose the update permanently. Letting it surface as a 500 buys a redelivery, which is
  the only mechanism that can recover it. Everything that could be slow is already in the queue, so a non-2xx
  here can only mean "we genuinely do not have this yet".
- **`wasRecentlyCreated` gates the dispatch.** `firstOrCreate` catches the unique-index violation a simultaneous
  delivery causes and re-reads, so the losing delivery declines to queue a second job. One update, one job, with
  the guarantee coming from the database rather than a lock.
- **Not `ShouldBeUnique`.** It would add a cache-lock dependency for a weaker version of a guarantee the unique
  index already gives. The job's own `processed_at` guard covers the rest.
- **`processed_at` is stamped only after the work succeeds.** A throw leaves the row unprocessed and the queue
  owns the retry (`tries = 3`, `backoff = [5, 30]`); a stamped row is a decision already made. The job refreshes
  first, so a serialised copy from an earlier attempt cannot re-do settled work.
- **An unhandled `kind()` is stamped, not left pending.** Telegram adds update kinds faster than we adopt them;
  logging and stamping keeps `unprocessed()` meaningful as a triage queue instead of a landfill.

**Tests** — `tests/Feature/Bot/TelegramWebhookTest.php` (25) + `tests/Feature/WebhookRouteTest.php` (1,
rewritten in place to configure the new secrets — route wiring only, behaviour moved to the new file).
**26 tests / 63 assertions.** New global helpers: `deliver`, `messageUpdate`. Coverage: 200 + queued + nothing
processed inline; payload stored verbatim including unknown keys; six rejection shapes as a dataset (wrong path,
no path, wrong header, missing header, empty header, secrets swapped) each asserting nothing recorded and
nothing queued; both unconfigured-secret cases; the diagnostic log naming `path` vs `header`; 422 on a missing
and on a non-integer `update_id`; three deliveries of one `update_id` → one row, one job; a differing
redelivery keeping the first payload; an already-processed row not requeued; the 500-on-ingest-failure path via
a container-bound throwing double; job idempotency, refresh-over-serialised-copy, and the unhandled-kind stamp.

**Assumptions / follow-ups recorded**

- **Nothing registers the webhook with Telegram yet.** `setWebhook` must be called with the path secret in the
  URL *and* `secret_token` set to `TELEGRAM_WEBHOOK_HEADER_SECRET`, or the bot is silently dead (every update
  404s). A `telegram:set-webhook` artisan command belongs in Task 2, together with the outbound-transport
  decision below — deliberately deferred rather than half-built here, since Task 1 is inbound-only.
- **Outbound transport is still undecided, and it is a real fork.** CLAUDE.md mandates `Http::fake()` for all
  Bot API calls in tests, but `irazasyed/telegram-bot-sdk` uses its own Guzzle client, which `Http::fake()` does
  **not** intercept. So either the SDK is configured with an injected client in tests, or platform code calls
  the Bot API through a thin service over Laravel's `Http` client (CLAUDE.md's own stated fallback). Task 2 must
  settle this **before** writing any send path; getting it wrong means either real network calls in tests or a
  rewrite of every send site.
- **A permanently failed job leaves the update unprocessed forever.** We answer 200, so Telegram will not
  redeliver. `TelegramUpdate::unprocessed()` exists for exactly this, but nothing sweeps it — a scheduled
  re-dispatch (bounded, with an attempt counter so a poison update cannot loop) is worth adding once the bot
  does real work.
- **No rate limiting on the webhook path.** A leaked URL *and* header would let someone flood the endpoint with
  well-formed updates. Every one records a row and queues a job, so the blast radius is queue depth and disk.
  A throttle keyed on IP is cheap and belongs here eventually; not added now because the correct limit depends
  on real update volume.
- **`update_id` is `int`.** Telegram documents it as a 32-bit-safe integer that resets when the bot's pending
  queue is cleared, so it is unique per delivery stream rather than globally forever. Nothing here depends on
  monotonicity — only on uniqueness within the retention window — but a bot token swap would restart the
  sequence and could collide with retained rows. Not a concern until tokens rotate.

**Result — `sail composer ci:check` GREEN:** eslint ✓, prettier ✓, `tsc --noEmit` ✓, pint ✓,
phpstan lvl 7 (0 errors) ✓, tests **651 (647 pass, 4 skipped = Fortify 2FA disabled)**, 1915 assertions,
+25 from this task. Graph: 2173 nodes / 3638 edges.

**Next:** Bot Core Task 2 — **settle the outbound transport question first** (see above), then the channel gate:
`getChatMember` on `/start` against `services.telegram.required_channel`, blocking with a join button until
confirmed, re-verified on privileged actions. It must assert a non-empty `required_channel` rather than treating
an unset one as "no gate" — same fail-closed reasoning as the webhook secrets. That task also owns the update
router (`kind()` → handler) that `ProcessTelegramUpdate` currently stands in for, and `telegram:set-webhook`.
