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
