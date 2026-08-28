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
- ✅ **Domain Task 8 — per-participant-per-period phrase generation** (unique `(period, phrase)` index,
  generate-once-and-persist, locale frozen at issue time, Farsi template + folds)
- ✅ **Domain Task 9 — `SubmitCheckIn` + review actions** (three proof types, actor resolved server-side,
  `CheckInRejection`, late-approval surfaced) — **Domain core complete**

## Phase 3 — Bot core
- ✅ **Bot Core Task 1 — webhook intake** (two secrets, 404 on mismatch, `update_id` idempotency,
  immediate 200, queued processing)
- ✅ **Bot Core Task 2 — outbound transport + webhook commands + update router** (`Http::fake()`-able
  transport, `telegram:set-webhook` / `telegram:webhook-info`, `kind()` → handler)
- ✅ **Bot Core Task 3 — channel gate + `/start` with invite attribution** (`getChatMember` fail-closed,
  join button, `ensure()` TTL cache, command router, one-message replies, per-recipient locale)
- ✅ **Bot Core Task 4 — create-challenge wizard** (`BotConversation` FSM, `callback_query` handler + router,
  inline keyboards, `CreateChallenge` + announcement post)
- ✅ **Bot Core Task 5 — join flow** (`JoinChallenge` action, `join_token` deep links, preview-then-confirm,
  announcement-channel join button, `JoinRejection`)
- ✅ **Bot Core Task 6 — check-in for all three proof types** (`/checkin` listing, `CheckInFlow`,
  `TelegramFileDownloader` via `getFile`, creator approve/reject callbacks, `ConversationRouter` check-in states)
- ✅ **Bot Core Task 7 — reminders + locale selection** (`challenges:roll-over` owns the lifecycle flips,
  `challenges:reminders` mints and dispatches, staggered `SendReminder` job, `/language` + `lg:` buttons) —
  **Bot core complete**

## Phase 4 — Stars payments
- ✅ `createInvoiceLink` (XTR, empty provider_token) → pre_checkout → successful_payment → credit
- ✅ Refund path (`refundStarPayment`) — **Phase 4 complete**

## Phase 5 — Mini App
- ✅ `POST /api/v1/miniapp/auth` (initData → Sanctum token) + minimal `/me`
- ✅ `/api/v1/miniapp/*` surface (challenges list + per-challenge status JSON)
- ✅ Gameish React SPA (details, status, freezes, progress; themeParams/BackButton/MainButton)

## Phase 6 — Admin panel
- ✅ Panel foundation: admin gate + settings/economy tuning UI
- ✅ Challenge moderation (list/detail/cancel) + image-proof review queue
- ✅ User/coin adjustments, invite/payment audit views, Stars refund surface

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

---

## Bot Core Task 2 — outbound transport, webhook commands, update router (done) — commit `14fbdfb`

The task Bot Core Task 1 deferred: how platform code reaches Telegram, how the webhook gets registered, and
which class acts on an update once it is recorded. No product behaviour — this is the seam every later bot task
sends its replies through, and settling it wrong means either real network calls in tests or a rewrite of every
send site.

**Built**

| File | Role |
|---|---|
| `app/Services/Telegram/LaravelHttpClient.php` | SDK `HttpClientInterface` over Laravel's HTTP client |
| `app/Providers/TelegramServiceProvider.php` | the single `Api` binding + the `UPDATE_HANDLERS` registry |
| `app/Console/Commands/Telegram/SetWebhookCommand.php` | `telegram:set-webhook` |
| `app/Console/Commands/Telegram/WebhookInfoCommand.php` | `telegram:webhook-info` |
| `app/Services/Telegram/HandlesUpdate.php` | the handler contract |
| `app/Services/Telegram/UpdateRouter.php` | `kind()` → handler lookup |
| `app/Jobs/Telegram/ProcessTelegramUpdate.php` | now delegates to the router |
| `composer.json` | `extra.laravel.dont-discover: ["irazasyed/telegram-bot-sdk"]` |

**The transport decision — settled, do not revisit.** Replace the SDK's own Guzzle client with an
`HttpClientInterface` adapter over Laravel's HTTP client, so `Http::fake()` intercepts every outbound Bot API
call and no test can reach real Telegram. CLAUDE.md's stated fallback was to abandon the SDK for raw `Http`
calls; that was not necessary, and keeping the SDK keeps its request building, response objects and typed
exceptions.

Two things had to happen for the guarantee to actually hold, and both are recorded on the provider:

- **The SDK's Laravel provider is un-discovered and `TelegramServiceProvider` replaces it.** The SDK reads its
  transport from `config('telegram.http_client_handler')` and `BotsManager::makeBot()` passes that value into
  the `Api` constructor as an *instance* — but config files must stay `var_export`-able for `config:cache`, so
  an object cannot live in one. The transport therefore has to be injected in code, which means owning the
  binding.
- **The `Telegram` facade is deliberately left unbound.** `Telegram::sendMessage()` goes through
  `BotsManager::__call()`, which builds its own bot and never consults the container's `Api` binding. Leaving
  both wired would leave two ways to reach Telegram with only one of them fakeable — a test could pass while
  quietly calling the real API from the other. Reaching for the facade now fails loudly. **One way in: resolve
  `Telegram\Bot\Api`.**
- **An empty `TELEGRAM_BOT_TOKEN` throws in the binding**, with that wording. Otherwise the SDK builds
  `.../bot/sendMessage` and reports Telegram's 404 as the problem.
- `extra.laravel.dont-discover` takes **package names**, not provider FQCNs. Easy to get wrong and silent when
  wrong.

**The two commands.** `telegram:set-webhook` is what makes the bot live at all — Task 1 shipped an endpoint
nothing had told Telegram about, so every update would have 404'd. It builds the URL from
`route('telegram.webhook', ['token' => …webhook_secret])`, passes `secret_token` from
`…webhook_header_secret`, and restricts `allowed_updates` to `TelegramUpdate::HANDLED_KINDS` — asking only for
what we record. Both secrets are required: it refuses rather than registering a URL that would then reject
every delivery. `telegram:webhook-info` is the diagnostic twin, and it **compares Telegram's registered URL
against this app's** — a bot pointed at a previous deploy is otherwise indistinguishable from a broken one. A
mismatch or a `last_error_message` exits FAILURE, so it is usable as a deploy check rather than only as
something to read.

**The router.** `TelegramUpdate::kind()` names the update, `TelegramServiceProvider::UPDATE_HANDLERS` maps that
name to a class, the container builds it. The map lives on the provider so the complete list of things the bot
reacts to is readable in one place. **Routing is deliberately not the same thing as processing:** the router
decides who acts, `ProcessTelegramUpdate` owns whether the update is then marked done. That separation is the
whole point — a handler that throws leaves `processed_at` null and the queue retries, and the router needs to
know nothing about the queue to make that true. `HandlesUpdate`'s docblock states the contract from the other
side: a handler that cannot finish **must throw**, because a quiet return claims the update was dealt with.

**`UPDATE_HANDLERS` is intentionally empty today.** A kind in `HANDLED_KINDS` but absent from the map is
recorded and logged rather than acted on — asking Telegram for a kind and knowing what to do with it are
separate deploys. Task 3 adds the first entry (`'message' => …`). Command parsing, `/start` and the channel
gate were **not** stubbed here on purpose: they are Task 3's design, and a placeholder handler would pre-empt
it. The router seam alone is justified because it is what makes the documented failure ordering testable.

**Tests — 40 new** across `TelegramTransportTest.php` (13), `SetWebhookCommandTest.php` (16) and
`UpdateRouterTest.php` (11). New global helpers: `telegramReplies`, `webhookInfo`, `webhookAccepted`,
`routerWith`. Notable coverage: a faked Bot API call asserted at the `Http::fake()` layer (which is the whole
transport claim); the facade being unbound; both commands refusing on an unset secret; `allowed_updates`
matching `HANDLED_KINDS`; Telegram's own refusal reason surfaced with a FAILURE exit; a URL pointing at another
deploy reported as a mismatch; container-built handlers; an unclaimed kind logged and returning false; and the
ordering test that matters — **a throwing handler leaves the update unprocessed** while an unrouted one is
stamped, so `unprocessed()` stays a triage queue rather than a landfill. `TelegramWebhookTest`'s four direct
`$job->handle()` calls became `dispatch_sync()` so the container injects the router.

**A test-infrastructure trap worth remembering — `Http::fake()` appends; it never replaces.**
`Factory::fake(['*' => …])` delegates to `stubUrl()`, which **merges a closure into `stubCallbacks`**; the
first non-null match wins. So a catch-all registered in `beforeEach` silently shadows every per-test stub, and
five tests failed while looking like production bugs: the webhook-info tests parsed the catch-all's scalar
`result: true`, found no URL, and took the "no webhook registered" branch before ever reaching the mismatch and
last-error branches they were written for. The fix is the pattern to keep: **`Http::preventStrayRequests()` in
`beforeEach`, and each test states its own reply.** The two `assertNothingSent` tests deliberately stub Telegram
as *willing*, so the assertion means "we chose not to ask" rather than "nothing was configured".

**A second trap — `expect([])->each->toBeIn(...)` performs zero assertions**, so PHPUnit marks the test risky
and the invariant is not actually checked. The registry test now asserts
`array_diff(array_keys(UPDATE_HANDLERS), HANDLED_KINDS)->toBe([])` — one real assertion that holds at size 0
and still means the same thing once the map fills up.

**A PHPStan note.** An empty `const array HANDLERS = []` on the router would let level 7 infer `array{}` and
flag the handler branch as dead code. Avoided by putting the registry on the provider with an
`@var array<string, class-string<HandlesUpdate>>` docblock and typing the router's constructor parameter by
docblock.

**Assumptions / follow-ups recorded**

- **`URL::forceRootUrl()` + `URL::forceScheme()` are required in console tests.** Setting `config(['app.url'])`
  mid-test does **not** reach `route()`: in console the URL generator takes its root and scheme from the
  request Laravel synthesises at boot. Relevant to every future artisan command that builds a URL.
- **`TelegramResponse::getResult()` does not throw** (`return $this->decodedBody['result'] ?? false;`) — it is
  `TelegramClient::sendRequest()` that raises `TelegramResponseException` on an error response. So a command
  that wants Telegram's own refusal reason must let the client throw rather than inspecting the result.
  Verified by reading the SDK, not assumed.
- **The 4 suite skips are all Fortify-feature-disabled** (`tests/TestCase.php:13`), *not* the coin-concurrency
  file. `pcntl` is present in the Sail image, so `CoinLedgerConcurrencyTest` genuinely runs locally — confirmed
  by a filtered run (8 passed, 45 assertions). **The `.github/workflows/tests.yml` follow-up therefore still
  carries both requirements: a MySQL service *and* `pcntl`, or CI silently loses §6 coin-concurrency coverage
  while reporting green.**
- **Nothing sweeps `unprocessed()` yet.** Carried from Task 1 and now slightly sharper: with the router in
  place a handler failure is the likely cause of a stuck row, and after three attempts nothing retries it. A
  bounded scheduled re-dispatch with an attempt counter belongs with the reminder scheduler.
- **No rate limiting on the webhook path.** Carried unchanged from Task 1.
- **`telegram:set-webhook` is not wired into any deploy step.** It has to be run once per environment, and
  after any change to either secret or to `HANDLED_KINDS`. `telegram:webhook-info` exits non-zero on a
  mismatch specifically so a deploy script can call it.

**Result — `sail composer ci:check` GREEN:** eslint ✓, prettier ✓, `tsc --noEmit` ✓, pint ✓,
phpstan lvl 7 (0 errors) ✓, tests **691 (687 pass, 4 skipped = Fortify 2FA disabled)**, 2002 assertions,
0 risky, +40 from this task. Graph: 2224 nodes / 3769 edges.

**Next:** Bot Core Task 3 — the channel gate and `/start`. `getChatMember` against
`services.telegram.required_channel`, blocking with a join button until confirmed and re-verifying on
privileged actions; it must **assert a non-empty `required_channel`** rather than treating an unset one as "no
gate", matching the webhook secrets' fail-closed reasoning. Then `/start` composing the pieces Domain core
already built: `firstOrCreate` on `telegram_id` (the instance whose `wasRecentlyCreated` `ClaimInvite` reads),
`ClaimInvite` for `?start=<code>` attribution, and `GrantFreeBaseline` on every arrival. This is where
`TelegramServiceProvider::UPDATE_HANDLERS` gains its first entry (`'message' => …`) and where command parsing
gets designed.

---

## Bot Core Task 3 — channel gate + `/start` with invite attribution (done)

The bot's front door. Everything Domain core built for arrivals — `firstOrCreate` on `telegram_id`,
`ClaimInvite`, `GrantFreeBaseline` — finally gets a caller, and `UPDATE_HANDLERS` gains its first entry.

**What landed**

- `ChatMemberStatus` — backed enum over Telegram's six statuses plus a non-Bot-API `Unknown` case, so a status
  Telegram invents later reads as "no" instead of falling through. `grantsAccess(?bool $isMember)` folds in the
  one status that needs a second field: `restricted` counts only while `is_member === true`, which is how a
  muted member is told apart from somebody restricted *and* removed.
- `VerifyChannelMembership` — two readings, and the split is the design. **`handle()` always asks Telegram**
  and is what `/start` uses, because the overwhelmingly likely reason somebody sends `/start` twice is that
  they just tapped the join button; a cached "no" would send them round the loop. **`ensure()` reuses a recent
  answer** and is what privileged actions (create, join, spend) will use, because at ~30 Bot API calls a second
  globally, one `getChatMember` per action competes with reminder fan-out. **The stamp is cleared on a "no"**,
  so a cached yes can never outlive the fact it recorded.
- `ChannelGateException` — `notConfigured()` (empty `required_channel`) and `notATelegramUser()` (an
  email-only Fortify admin handed to a bot path). An unset channel throws rather than degrading to "everybody
  is a member", matching the webhook secrets' fail-closed reasoning: **an unset required channel is a
  deployment that is not finished, not a deployment with the gate turned off.**
- `SettingKey::ChannelVerificationTtlMinutes` (default **10**) — how long a confirmed membership is trusted.
  Zero turns the cache off entirely and makes `ensure()` identical to `handle()`.
- `ResolveTelegramUser` — the bot has no sign-up step, so this action *is* registration. Returns the instance
  whose `wasRecentlyCreated` is meaningful (the `ClaimInvite` contract). Writes `locale` **only at creation**,
  refreshes Telegram-owned profile fields only when `isDirty()`, and never touches `is_admin` or
  `channel_verified_at`.
- `BotMessenger` — talks to one user in that user's own language. Exists because a queue worker's
  `app()->getLocale()` is whoever was processed last. `paragraphs()` joins lines into **one** `sendMessage`
  (roughly a message a second per chat). The chat id comes from our own row, never the payload.
- `BotCommand` + `HandlesBotCommand` + `CommandRouter` + `TelegramServiceProvider::BOT_COMMANDS` — the
  `UPDATE_HANDLERS` shape one level down, so "what a user can type" stays readable in one file.
- `MessageHandler` — refuses anything that is not a private chat, refuses a bot sender, **resolves the user
  exactly once**, then routes; unmatched input gets `bot.fallback.unknown`.
- `StartCommand` + `lang/{en,fa}/bot.php`.

**The ordering decision this task turns on**

`/start` runs **attribution before the gate decides anything**. Blocking first does not defer the invite
credit, it *loses* it: the user joins the channel, sends `/start` again, and by then their row already exists,
so `ClaimInvite` finds nobody brand-new to pay. The framing that resolves it — **the reward is for bringing a
person to the bot, which has already happened; the gate is about *using* the bot, which has not.** A blocked
user is still told their inviter was credited, in the same message as the join button.
`StartCommandTest::it('pays the inviter even when the arrival is then blocked at the gate')` is the regression
guard, and it is the reason this task is tested end-to-end through `dispatch_sync()` rather than per class: the
bug is an ordering bug between four collaborators, which no unit test of any one of them can see.

**The gate is invoked explicitly, not as per-message middleware.** Deliberate, and recorded in the
`HandlesBotCommand` docblock so the next task cannot forget it: **every command that creates, joins or spends
must call `ensure()` first.** Blanket per-message verification would spend a Bot API call on every stray "hi"
(`StartCommandTest::it('does not spend a gate check on it')` pins that), and §2.1's wording is "re-verify on
privileged actions", not on everything.

**SDK findings, verified by reading `vendor/`, not assumed**

- **The SDK does not serialise `reply_markup`.** Params go straight through as form fields, so a nested array
  would be rejected by Telegram. `BotMessenger::send()` `json_encode`s it. This also means the SDK's
  `Keyboard` class is not used anywhere — a loosely typed Collection subclass that fights PHPStan level 7 buys
  nothing over a plain array.
- `Api::getChatMember()` uses `$this->get(...)`, i.e. a **GET with query parameters**. So `Http::fake()`
  patterns must be `'*getChatMember*'` — `$request->url()` includes the query string and a pattern anchored on
  the method name alone never matches. Same trap for `'*sendMessage*'`.
- `BaseObject::getRawResult()` is `data_get($data, 'result', $data)`, so `result` *is* unwrapped and
  `$member->get('status')` reads Telegram's field directly. Preferred over the SDK's magic `__get`, which
  returns `mixed` and snake-cases behind the scenes.

**Tests — 3 files, 78 tests, +80 over the previous run**

- `ResolveTelegramUserTest` (18) — creation from a sender, the `wasRecentlyCreated` assertion that decides
  whether an inviter gets paid, name fallbacks (`first+last` → `username` → `Telegram <id>`, because
  `users.name` is NOT NULL), a changed username picked up, **a chosen `locale` never overwritten by a client
  language**, no write when nothing changed, `is_admin`/`channel_verified_at` left alone, four bad-id refusals.
- `ChannelGateTest` (27) — a dataset over all five real statuses, `restricted` × `is_member` × absent, the
  unknown-status fail-closed case, stamp set on yes and **cleared on no**, the channel and the user's own id
  actually asked, an admin moving the channel followed, both `ChannelGateException` paths asserting
  `Http::assertNothingSent()`, a Telegram error propagating as `TelegramSDKException` **and leaving an existing
  stamp untouched**, `joinUrl()` for `@name` / numeric / unconfigured, and five `ensure()` cases including the
  one that records the cache's cost: a fresh stamp is trusted even if the user has since left.
- `StartCommandTest` (33) — the whole inbound path, `dispatch_sync` → real `UPDATE_HANDLERS` →
  `MessageHandler` → `StartCommand`. Register/admit/greet in one message; reply addressed to their own chat id;
  baseline granted per settings and **topped up rather than doubled**; `welcome_back`; gate block with the join
  button and nothing granted; re-asked on every `/start`; admitted the moment they join; the link-less
  `-100…` channel; six invite paths (paid, **paid-then-blocked**, paid once across retries, `not_found`,
  `self_invite`, `already_claimed`, `invitee_already_attributed`, an upper-cased code); non-private chats and
  bot senders ignored with no user created and nothing sent — but **still stamped processed**, since ignoring
  is a decision rather than a failure; the fallback for unknown commands, free text, a bare slash and a
  text-less photo; `/start@botname`; and three locale tests including **one recipient's locale not leaking
  into the next**.

**Traps hit while writing these**

- **`Http::clearRecorded()` does not exist** on this Laravel version. Where a test puts several updates
  through, `latestBotMessage(int $ofTotal)` reads the last message *and* asserts the total, which keeps the
  one-message-per-update rule enforced rather than dropped.
- **A second `Http::fake()` call cannot change an existing stub** — `fake()` appends and the first match wins,
  so "left, then member" needed `Http::sequence()`, not two `fake()` calls. Same appending trap as Task 2,
  arriving from the other direction.
- `Http::response()` returns a `PromiseInterface`, not a `Response` — the `array_map` building that sequence
  had to be typed accordingly.
- An attribute the action never touched is **absent from the in-memory instance**, so `$user->is_admin` is
  `null` rather than the column default `false`. The assertion reads `$user->fresh()`.

**Pre-existing test updated:** `TelegramWebhookTest`'s `beforeEach` now configures a bot token and a required
channel and returns `['ok' => true, 'result' => []]` instead of a bare `Http::fake()`. Not a workaround — a
`message` update now routes to a real handler, so those webhook-plumbing tests exercise more than they did.

**Assumptions / follow-ups recorded**

- **`bot.start.next_steps` is a placeholder.** Replace it with a real command menu (and `setMyCommands`) once
  the create-challenge wizard lands.
- **The inviter is not notified when their invite credits.** A second `sendMessage` that 403s if they have
  blocked the bot; it belongs with the reminder infrastructure that already has to handle that failure mode.
- **The "I've joined" callback button is deferred to the wizard task**, along with the whole `callback_query`
  handler and its router — the gate currently asks the user to send `/start` again, which works and needs no
  new update kind.
- **A numeric `-100…` `required_channel` degrades the gate to a link-less prompt.** Logged as a warning on
  every blocked user, since it is a configuration choice that quietly makes the gate harder to pass.
- **A brand-new user whose first message is *not* `/start` still gets a `users` row**, which means a code they
  send afterwards can never pay their inviter. Acceptable: the deep link always sends `/start <code>`, and
  `ClaimInvite` failing closed is the documented trade (under-paying is a support conversation, over-paying is
  a mint).
- Carried unchanged: `.github/workflows/tests.yml` needs **both** a MySQL service *and* `pcntl`; nothing
  sweeps `unprocessed()`; no rate limiting on the webhook path; `telegram:set-webhook` is not in a deploy step;
  `entitlements` has no unique index; no release path for a consumed entitlement; nothing calls
  `RollOverPeriod` on a schedule; no completion detection or flat completion reward yet.

**Result — `sail composer ci:check` GREEN:** eslint ✓, prettier ✓, `tsc --noEmit` ✓, pint ✓,
phpstan lvl 7 (0 errors) ✓, tests **771 (767 pass, 4 skipped = Fortify 2FA disabled)**, 2158 assertions,
0 risky, +80 from this task. Graph: 2306 nodes / 3951 edges.

**Next:** Bot Core Task 4 — the create-challenge wizard. `BotConversation` (state + payload JSON + expiry) is
the model Domain Task 2 built for exactly this, because the SDK has no FSM. Five steps — title → period type
(a six-case `PeriodType` dataset) → start date and total periods → proof type → visibility — then
`MaterialisePeriods` and, for a public challenge, the announcement-channel post. This is where the
`callback_query` handler and its router land (inline keyboards for every enum choice), where
`ConsumeEntitlement` gets its first bot caller, and where `HandlesBotCommand`'s note about `ensure()` gets
honoured for the first time. Free text stops being a fallback and becomes how a user answers a question, so
`MessageHandler` grows a "does this user have an open conversation?" branch **before** command parsing.

## Bot Core Task 4 — create-challenge wizard (done)

The first multi-step conversation, and the first place a user writes to the domain from Telegram. The SDK has
no FSM, which is why `BotConversation` exists; this is the task that finally uses it.

**What landed**

- `ConversationState` — backed enum, one case per question (`awaiting_title` → `awaiting_description` →
  `awaiting_period_type` → `awaiting_custom_days` → `awaiting_timezone` → `awaiting_start_date` →
  `awaiting_total_periods` → `awaiting_proof_type` → `awaiting_visibility`). The state *is* the question, so
  "where are we" and "what did we ask" can never disagree.
- `ChallengeDraft` — a typed reader/writer over `BotConversation::$payload`. The alternative was
  `data_get($conversation->payload, 'period_type')` at fourteen call sites, which PHPStan can say nothing about
  and a typo turns into a silently empty answer.
- `CreateChallengeWizard` — owns the flow: `begin()`, `receiveText()`, `receiveChoice()`, `cancel()`. Every
  step validates against the same `CreateChallenge::limits()` the action enforces, so a user is never accepted
  into the next question only to be refused at the end.
- `BotCallback` + `WizardCallback` + `HandlesCallback` + `CallbackRouter` + `CallbackQueryHandler` — the
  `callback_query` half of the bot, mirroring Task 2's `BotCommand`/`CommandRouter` shape one update-kind over.
  `UPDATE_HANDLERS` gains its second entry.
- `ConversationRouter` — the "is this user mid-answer?" branch `MessageHandler` grows, consulted **before**
  command parsing. Expiry is checked on read, so a lapsed conversation is not an error state.
- `CreateChallenge` — the one place a `challenges` row is written, called by all three surfaces eventually.
  Validates, opens a transaction, writes the row, materialises the timeline via `MaterialisePeriods`, spends a
  create-slot via `ConsumeEntitlement`, and dispatches `AnnounceChallenge` for a public challenge.
- `ChannelBroadcaster` + `AnnounceChallenge` — the announcement-channel post. `BotMessenger`'s sibling, and
  separate from it because every decision that class makes is about a *recipient* (their chat id, their locale)
  and a channel has neither.
- `ChannelGatePrompt` — the join-button reply, extracted from `StartCommand` now that `/create` needs the same
  block. `HandlesBotCommand`'s note about calling `ensure()` first is honoured for the first time.
- `CreateCommand` + `CancelCommand`; `SettingKey::ConversationTtlMinutes` (default **60**);
  `Localization::foldDigits()`; `HasTranslatedLabel::translationKey()`.

**The ordering decision this task turns on**

**The claim comes before the post.** `ChannelBroadcaster::announce()` stamps `announced_at` in a single
conditional `UPDATE ... WHERE announced_at IS NULL`, and only then calls `sendMessage`. Posting first and
stamping after would double-post on any failure between the two — and **a duplicate channel post cannot be
recalled**, while a *missed* post is visible in `Challenge::awaitsAnnouncement()` and can be re-dispatched. So
a failed post releases the claim and rethrows, and the release is itself guarded on `announced_at` still
holding *our* timestamp, because by the time we go to release, another worker may have claimed and posted.
`ChannelBroadcasterTest::it('does not release a claim that now belongs to somebody else')` is the guard.

The same reasoning one level up: **`CallbackQueryHandler` acknowledges the query before running the handler.**
Telegram spins a loading state on the tapped button until `answerCallbackQuery` arrives, and the query id
expires. If the work throws, the update is retried — and by then the id is stale, so acknowledging afterwards
would fail and leave the spinner running forever. The acknowledgement is also best-effort: it is cosmetic, so
it must never be the reason an update is retried.

**Two production bugs the tests caught**

1. **Every wizard error message leaked its own placeholders.** Only `.prompt` lines were resolved with
   `promptReplacements()`; `.error` and `.expected` were fetched bare. So a user over the title limit was told
   "between 3 and :title_max characters" — in the one message whose whole job is to state the bound. Fixed with
   a `say()` helper, so every line in the flow's namespace resolves through the same replacements rather than
   each caller remembering to pass them.
2. **MySQL's TIMESTAMP capped the timeline at 2038-01-19.** `challenges.starts_at`,
   `challenge_periods.starts_at`/`ends_at` and `reminder_dispatches.scheduled_for` were all `timestamp()`. A
   2099 start date died with `SQLSTATE[22007] Incorrect datetime value` — and this is not a contrived date:
   `starts_at` is the one date a user picks freely, and a yearly challenge of any length walks past the ceiling
   from any start. All four are `dateTime()` now (DATETIME reaches 9999 and, unlike TIMESTAMP, is not
   re-interpreted through the session timezone, which matters for a platform whose correctness rests on storing
   UTC). Event stamps — `announced_at`, `joined_at`, `rolled_over_at`, `consumed_at`, `expires_at`, `sent_at` —
   stay TIMESTAMP: they only ever hold ~now, so the framework default is right there and the diff stays small.
   `CreateChallengeTest::it('materialises a timeline that runs past 2038')` is the permanent guard; nothing else
   in the app would notice if this regressed.

A third, smaller one: `ChannelBroadcaster` used `syncChanges()` after the claim UPDATE, which records the
change but never resyncs `$original` — so a successful post left the handed instance `isDirty()`. Now
`syncOriginalAttribute('announced_at')`, chosen over `syncOriginal()` so other pending changes survive.

**Decisions taken (recorded, not asked)**

- **The creator does not auto-join their own challenge.** Creating and participating are separately priced
  (`create_slot` vs `join_slot`), so auto-joining would spend a slot the user never agreed to spend. A creator
  who wants to take part joins like anybody else.
- **`proof_is_public` is not asked in the wizard.** Private is the default and the flow is already nine
  questions; the toggle only means anything for `image_approval`, and it belongs where a creator can see what
  they are publishing — the Mini App and the admin panel.
- **Timezone comes from a curated eight-entry list**, not a full IANA picker. 400-odd zones cannot be an inline
  keyboard, and the longest of these encodes to 39 of `callback_data`'s 64 bytes. A real searchable picker is
  Mini App work.
- **Start date is Gregorian `YYYY-MM-DD`**, with Today/Tomorrow buttons for the overwhelmingly common case.
  Persian digits are folded and `/` and `.` accepted as separators, so a Farsi keyboard's `۱۴۰۵/۰۶/۰۳` parses.
  **Jalali date entry is a follow-up**, not a nicety — a Farsi-speaking creator picking a date in a calendar
  they do not use is a real usability gap, but it needs a calendar library decision rather than a parser tweak.
- **Registered commands are routed before wizard text**, so a challenge title cannot begin with `/`. The trade
  is deliberate: a user who mistypes a title loses one keystroke, whereas a user who cannot type `/cancel`
  because the wizard swallowed it is trapped inside a flow.
- **A lapsed conversation is replaced, not reported.** `/create` is `updateOrCreate` on the unique `user_id`,
  so there is exactly one open flow per user and restarting is always safe.
- **The announcement carries no join button yet** — a public challenge is joined through the bot, and that flow
  lands next. A button that goes nowhere is worse than a post that says where to go.
- **A channel post resolves in the platform's fallback locale**, never `app()->getLocale()`. A channel has a
  mixed-language audience and no locale of its own, and a queue worker's ambient locale is whoever it served
  last — so the alternative is a post whose language is chosen at random.

**Tests — 5 files, 158 tests**

- `CreateChallengeWizardTest` (73) — the whole flow through `dispatch_sync(ProcessTelegramUpdate)` with the
  real routers and a real conversation row, because what is being tested is an interaction between five
  collaborators. Every step's happy path and refusal, the six-case `PeriodType` dataset, `/cancel` at each
  state, expiry, the gate blocking `/create`, and the replayed-`update_id` assertion §6 asks for.
- `CreateChallengeTest` (34) — the action's floor, whichever surface called: every field recorded, UTC
  conversion (Tehran midnight → `2099-05-31 20:30:00`), the freeze default vs an override, the `proof_type` ×
  `proof_is_public` matrix, and the rollback that matters — **a refused slot writes nothing at all**, no
  challenge and no periods, because a challenge with no slot spent is a free extra challenge and a spent slot
  with no challenge is a slot stolen.
- `CallbackQueryHandlerTest` (18) — acknowledge-before-work, the actor resolved from `from` and **never** from
  `callback_data` (a crafted payload naming a victim's id is put through and ignored), a stale button answered
  rather than dropped, a tap from a channel taken (unlike a message), and the acknowledgement-refused path.
- `ChannelBroadcasterTest` (17) — claim/release, twice-to-one-post, the interleaved-claim release guard, the
  fallback locale, and the two things it will not announce.
- `BotCallbackTest` (16) — unit, no container. `callback_data` is the one string that has to survive a round
  trip through a Telegram client and come back meaning the same thing, so encode and parse are pinned against
  each other: the 64-**byte** limit refused rather than truncated (truncation is worse — a shortened payload
  parses into a *different* intent), bytes not characters, and `wz::daily` read as a malformed button rather
  than as a step named `''`.

**Traps hit while writing these**

- **`Http::fake()` appends and the first match wins** — third time this has bitten, and the first time it
  produced *green tests that proved nothing*. Four acknowledgement-failure tests set a 400 inside the test
  body, which the `beforeEach` catch-all silently shadowed; both assertions passed either way, so the failure
  path was never once exercised. Fixed by installing the transport per test (`telegramTakesTaps()` /
  `telegramRefusesAcknowledgement()`), never in `beforeEach`. **The rule for this repo: if a test needs a
  specific response, no catch-all may exist for that endpoint.**
- **`Http::preventStrayRequests()` throws a `Throwable`, and `acknowledge()` swallows `Throwable` by design** —
  so a missing `answerCallbackQuery` fake logs instead of failing, and the omission is invisible.
- **`expect()->toThrow(Throwable::class)` asserts on the message, not the class.** Pest treats an argument
  failing `class_exists()` as a message, and an interface fails it — so the assertion quietly became "message
  contains 'Throwable'". Name the concrete class (`TelegramSDKException`).
- **`Challenge::periods()` carries its own `orderBy('index')`**, so an appended `orderByDesc('index')` is
  ignored — the relation's term comes first. Read the last period with `->get()->last()`.
- **A DATETIME column has no fractional seconds.** Comparing a microsecond-precision `now()` against what came
  back fails on the truncation rather than on the behaviour; `startOfSecond()` first.
- **A Pest helper defined in a test file only exists when that file is loaded.** The bot-reply readers moved
  from `StartCommandTest` into `tests/Pest.php` (plus `lastBotReply()`, `lastBotKeyboard()`, `keyboardOn()`),
  so any bot test file runs on its own.
- **`CheckIn::normalisePhrase()` and the wizard folded digits twice, separately.** Hoisted to
  `Localization::foldDigits()` — static and dependency-free so a model and a service can share one digit map
  rather than a copy per caller that drifts.

**Assumptions / follow-ups recorded**

- **`bot.start.next_steps` is still a placeholder and is now overdue.** The wizard exists, so there is a real
  command menu to describe and `setMyCommands` to call.
- **No `/challenges` listing.** A creator can create but cannot see what they created from the bot.
- **The eight timezones are not enough.** A user outside them has no way to name their own zone; they get the
  nearest offset or UTC.
- **Jalali start dates** (see decisions above).
- Corrected from an earlier note: **the 4 skipped tests are Fortify feature-gated auth tests**
  (`skipUnlessFortifyHas`), not the coin-concurrency tests — those run in the Sail container.
- `prompts/` gained `phase-11.md`, `phase-12.md`, `phase-13.md`, `main-addendum-3.md`, `main-addendum-4.md`,
  `goal-phases-11-12.md`, `goal-phase-13.md`, and renamed `main-2.md` → `main-addendum-2.md` and
  `goal-phases-8to10.md` → `goal-phases-8-10.md`. **The roadmap now runs to thirteen phases.** Unread; to be
  read when those phases come up. `prompts/phase-9.md` extends *this* wizard for `flow_type = timed_session`,
  so there is no conflict with what landed here.
- Carried unchanged: `.github/workflows/tests.yml` needs **both** a MySQL service *and* `pcntl`; nothing
  sweeps `unprocessed()`; no rate limiting on the webhook path; `telegram:set-webhook` is not in a deploy step;
  `entitlements` has no unique index; no release path for a consumed entitlement; nothing calls
  `RollOverPeriod` on a schedule; no completion detection or flat completion reward yet; the inviter is not
  notified when their invite credits; a numeric `-100…` `required_channel` degrades the gate to a link-less
  prompt.

**Result — `sail composer ci:check` GREEN:** eslint ✓, prettier ✓, `tsc --noEmit` ✓, pint ✓,
phpstan lvl 7 (0 errors) ✓, tests **930 (926 pass, 4 skipped = Fortify 2FA disabled)**, 2517 assertions,
0 risky, +159 against the 771 recorded last time (158 of them in the five files above).
Graph: 2565 nodes / 4568 edges / 240 communities.

**Next:** Bot Core Task 5 — the join flow, the other half of what `/create` just made possible. A public
challenge is discovered in the announcement channel and an invite-only one through a link, so joining has two
entry points into one action: `JoinChallenge` spends a `join_slot` via `ConsumeEntitlement`, writes a
`ChallengeParticipant` with `joined_period_index` — the field Domain Task 6 built so a late joiner owes nothing
for periods before they arrived — and seeds `freezes_total` from the challenge's `default_freezes`. This is
where the announcement post finally gets its join button, where `challenge_participants`' unique
`(challenge_id, user_id)` stops a double-join from costing two slots, and where a full challenge, a finished
one and the creator's own challenge each need an answer rather than a slot spent.

---

## Bot Core Task 5 — the join flow (done)

**What landed.** `JoinChallenge` (`app/Actions/Challenges/JoinChallenge.php`) — the one place a
`challenge_participants` row is written, so the bot, the Mini App and the admin panel cannot disagree about
what joining costs. It resolves `joined_period_index` from `ChallengePeriod::containing(now())` (0 when still
`Scheduled`, refusal when the timeline is spent), then one `DB::transaction`: `CoinLedger::lockUser()` →
existing-participant read → `ConsumeEntitlement(JoinSlot)` → create with `freezes_total` seeded from
`default_freezes`. Plus `JoinRejection` + `ChallengeNotJoinableException` (mirroring
`InviteRejection`/`InviteNotClaimableException`), `MintJoinToken` (~60 bits from the same unambiguous
alphabet, unique `challenges.join_token` column), `Challenge::joinPayload()/joinLink()/fromJoinPayload()/
isJoinPayload()`, the bot surface (`JoinChallengeFlow` preview-then-confirm, `JoinCallback` action `jn`,
`StartCommand`'s join-payload branch, `ChannelBroadcaster`'s url join button), and lang lines in both locales.

**Three decisions this task turns on.**

1. **The user row is locked before the participant is read, not after.** The unique `(challenge_id, user_id)`
   index stops the duplicate *row*; it cannot stop the duplicate *spend* — two concurrent joins could both
   find no participant, both consume a slot, and one would fail on the index having already burned a slot the
   user does not get back. `lockUser()` first serialises read-decide-write on that user. That ordering is also
   why the existence check comes **before** the spend: `ConsumeEntitlement`'s own idempotency is keyed on a
   spend already recorded against the challenge, and an admin-seeded participant has no such record — a
   returning user would be billed for a participation they already had. Tested: "it charges nothing when an
   admin put the participant there without a spend".
2. **Joining twice is not an error.** A double-tapped button and a Telegram retry both arrive as two calls for
   one intention, so the second call returns the existing participation (no exception, no second slot), and
   `wasRecentlyCreated` on the returned model is how the caller says "you are already in". A terminal
   participation (`Left`/`Removed`/`Completed`) is **refused, not reactivated**: `Removed` is a moderation
   decision that must not be user-undoable, `Left` was their own, and reactivation would mean inventing
   streak-restoration semantics. Rejoining is a creator/admin act — recorded below as a follow-up.
3. **The channel post's button is a `url` deep link, not `callback_data`.** A bot cannot open a conversation
   with a user who has never messaged it (403), and the audience a channel post addresses is exactly those
   people — a `callback_query` reply would 403 for its intended tappers. `https://t.me/<bot>?start=j_<token>`
   carries them into the bot first. Relatedly, **an invite-only challenge keyed on the sequential id would be
   enumerable, not private** — hence `join_token`, minted for every challenge (public ones get shared by link
   at least as often as found in the channel).

**The join payload must not fall through to invite attribution.** A dead `j_…` link handed to `ClaimInvite`
would be answered with "that invite link is no longer valid" — a message about the wrong thing. So
`StartCommand` resolves the join payload first (`Challenge::isJoinPayload`), answers `bot.join.not_found` when
it names nothing, and only otherwise runs invite attribution. The `j_` prefix cannot collide with an invite
code: `IssueInviteCode`'s alphabet excludes `_`.

**A preview, not a join on arrival.** Joining spends a slot and a deep link is opened by a tap; a link that
joined on open would spend somebody's one free join by accident. `/start j_…` shows title/description/period/
proof/freezes with a Join button; the tap re-verifies the gate (`ensure()`), then spends. `ensure()`'s TTL
cache is a real test consideration: a membership verified at `/start` is *borrowed* for the tap, so the
lapse test has to `travel()` past the TTL (or the cached "yes" answers and the outsider stub is never asked —
`Http::fake()` appends, so a second stub would be shadowed anyway).

**Tests — 2 files, 41 tests.** `tests/Feature/Domain/JoinChallengeTest.php` (22): what it writes, the
slot spend and its refusals, joining-twice (including the admin-seeded no-spend case), creator-joins-own,
closed/cancelled refusals carrying `JoinRejection`, ended-participation refusals spending nothing, and the
timeline-exhausted window (rollover moves status on a schedule, so a challenge can look active with no period
left — joining it would create a participant who can never check in once). The domain test helper runs the
**real** `MaterialiseChallengePeriods`, so `joined_period_index` is honest about where "now" falls.
`tests/Feature/Bot/JoinChallengeFlowTest.php` (19): the whole inbound path — preview content and button
data, no-spend-on-arrival, dead-link answers, not-an-invite-code, gate blocking, provisioning parity with an
ordinary `/start`, the tap joining/saying-already-in/charging-one-slot, actor-from-`from`-not-the-button,
no-slot price quoting, gate lapse via `travel()`, double tap, closed/ended refusals, tokenless button →
stale-button reply, and the preview's already-in and closed short-circuits. `ChannelBroadcasterTest` updated:
the `how_to_join` text line is gone, replaced by a `channelPostButtons()` helper asserting the url button and
its fallback-locale label.

**Traps hit while writing these.** (a) `ChallengeFactory` writes no timeline — the action does — so a join
helper that skips `MaterialiseChallengePeriods` sees "timeline exhausted" everywhere. (b) The free baseline
granted on `/start` means "no slot left" tests must not route the user through `/start` first. (c)
`Http::fake()` appends and first-match-wins, so an outsider stub installed after a member stub is shadowed —
the lapse test needed a `Http::sequence()`. (d) `ensure()` honours its TTL: a fresh `channel_verified_at`
answers the tap without asking Telegram, so the lapse needs `travel()`.

**Assumptions / follow-ups recorded.** Rejoining a challenge after `Left`/`Removed` is a creator/admin act
(not user-side) — no surface for it yet. `MintJoinToken` relies on the unique index as arbiter; a rare
race rolls the whole create back rather than retrying the transaction. The bot username comes from
`services.telegram.bot_username` — unset means `joinLink()` emits `https://t.me/?start=…`, which Telegram
rejects; wiring a check (or falling back to the bot's `getMe` username) is a small follow-up. Carried
unchanged: all standing follow-ups from Task 4, plus "the wizard's `created_private` line should now tell the
creator their challenge's join link" — the link exists (`$challenge->joinLink()`), the copy just does not
show it yet.

**Result — `sail composer ci:check` GREEN:** eslint ✓, prettier ✓, `tsc --noEmit` ✓, pint ✓, phpstan lvl 7
(0 errors) ✓, tests **972 (968 pass, 4 skipped = Fortify 2FA disabled)**, 2610 assertions, 0 risky, +42
against the 930 recorded last time (41 of them in the two files above). Graph: 2613 nodes / 4740 edges /
237 communities.

**Next:** Bot Core Task 6 — check-in for all three proof types. `SubmitCheckIn` already exists from Domain
Task 7 (`button` tap, `text_autogen` phrase match, `image_approval` photo with creator review), so the bot
surface is what lands: a `/checkin` entry point (or a per-period prompt), the photo upload path through
`getFile`, the creator's approve/reject buttons on pending image proofs, and the streak feedback on every
successful check-in. All of it goes through the same `SubmitCheckIn` action the Mini App and admin panel
will later call.

---

## Bot Core Task 6 — check-in for all three proof types (done)

**Scope.** The bot surface over the Domain Task 7 actions: `/checkin` lists what the
user owes right now, the proof arrives for `button` (one tap), `text_autogen` (the
issued phrase typed back) and `image_approval` (a photo, then the creator's verdict
on inline buttons). `SubmitCheckIn`, `ReviewCheckIn`, `IssueCheckInPhrase`,
`OpenCheckIn` and `SettleCheckIn` were already done and tested — this task adds
zero rules and all surface.

**What landed.**
- `app/Services/Telegram/CheckInFlow.php` — `begin()` (the `/checkin` listing: todo
  lines with a per-challenge button, done + streak, awaiting-review, nothing-due),
  `start()` (dispatches by proof type: the tap *is* the proof for `button`; the
  other two open a `BotConversation`), `receiveText()` / `receivePhoto()` (routed by
  the extended `ConversationRouter`; a wrong phrase or a photo-where-words-were-due
  is re-asked, everything else is refused with the reason and closes the flow).
- `app/Services/Telegram/TelegramFileDownloader.php` — photo ladder → largest size →
  `getFile` → bytes at `https://api.telegram.org/file/bot<token>/<path>` → stored
  under `check-in-proofs/Y/m/d/<hex>.jpg` on the local disk. The surface owns the
  Telegram transport; `SubmitCheckIn::uploadPhoto()` keeps taking a stored path.
- `app/Services/Telegram/Callbacks/CheckInCallback.php` (`ci:<join_token>`) and
  `ReviewCheckInCallback.php` (`rv:<check-in id>:a|r`) — the latter re-verifies the
  gate at the tap, delegates to `ReviewCheckIn` (which re-derives ownership from the
  row), acks the creator and tells the participant the verdict with their new streak.
  Rejection notifies the participant to resubmit — rejection is not an ending.
- `app/Services/Telegram/Commands/CheckInCommand.php` (`/checkin`), registered in
  `BOT_COMMANDS`; both callbacks in `CALLBACK_HANDLERS`.
- `ConversationRouter` now routes `AwaitingCheckInText` / `AwaitingCheckInPhoto`
  (replacing the log-and-fall-through placeholder) and takes the whole update so a
  photo message can be the answer itself.
- `TelegramUpdateFactory::photoFrom()` — a real `message.photo` ladder, no `text`.
- Lang: `bot.checkin.*` in en + fa, including `refused.*` addressed by
  `CheckInRejection` values and `review_refused.*` by the subset `ReviewCheckIn`
  throws. Farsi copy written, not transliterated.

**Decisions.**
- *The phrase is issued at the prompt, not at submission.* `askPhrase()` calls
  `IssueCheckInPhrase::forParticipant()` before showing it, so the string the
  participant reads is the persisted one their answer is compared against. (Submission
  still issues on demand — a Mini App user can arrive with no prompt ever sent.)
- *A wrong phrase does not close the flow.* It is the mechanic working; the
  conversation holds and a later correct phrase still lands. Every other rejection
  (closed, not-a-participant, settled, awaiting-review) is a state the participant
  cannot retry out of, so the flow closes and says which.
- *An infrastructure failure is ours, not theirs.* A Telegram/storage failure in
  `receivePhoto()` logs, says `photo_error`, and leaves the conversation open — no
  `Submitted` row exists, so nothing is half-recorded and the second attempt works.
- *The review verdict travels to both parties.* Creator gets an ack; participant gets
  approval-with-streak or rejection-with-resubmit-plea. Streaks are read after
  `$participant->refresh()` — `SettleCheckIn` moves counters on a freshly locked row.
- *A check-in conversation replaces a half-built wizard.* One `BotConversation` row
  per user is the existing constraint; reaching for `/checkin` when something is due
  beats preserving a draft, and `/create` restarts cheaply.

**Traps met.** `BotConversation` lives in `App\Models` — a missing import in
`ConversationRouter` surfaced only at runtime (typed param, not a `use`); `getFile`
travels as a GET so the `file_id` is on the URL, not in the body; the factory's
random en/fa `language_code` flips per-recipient copy mid-test (helpers now pin
`preferring('en')`); `active()` challenges open yesterday so "today" is period 2 —
computed from `currentPeriod()`, never assumed.

**Assumptions / follow-ups recorded.** A creator with no `telegram_id` (email-only
admin) cannot be notified of a submitted photo — logged, not lost; the queue review
path for that case is a follow-up. The `ci:` button reuses `join_token` as its
reference (unique, unguessable-enough, already on the row) rather than minting a
second token. Photos are stored on the `local` disk — moving to a public/protected
disk with a signed-URL viewer is a Mini App / admin task, recorded for Phase 5/6.
Carried unchanged: all standing follow-ups from Task 5.

**Result — `sail composer ci:check` GREEN:** eslint ✓, prettier ✓, `tsc --noEmit` ✓,
pint ✓, phpstan lvl 7 (0 errors) ✓, tests **994 (990 pass, 4 skipped = Fortify 2FA
disabled)**, 2694 assertions, +22 on the suite (all in
`tests/Feature/Bot/CheckInFlowTest.php`, which drives every step through
`ProcessTelegramUpdate` with `Http::fake()`d Bot API + file bytes). Graph: 2664
nodes / 4981 edges / 231 communities.

**Next:** Bot Core Task 7 — reminders: the scheduler that rolls periods over and
fans reminder jobs out staggered (`ReminderDispatch` unique on participant+period+kind),
plus the `/language` locale selection. Nothing currently calls `RollOverPeriod` on a
schedule, so challenges never close their periods — that is the heart of the next task.

## Bot Core Task 7 — reminders + locale selection (done)

**Scope.** The two things the bot must do with no user present: keep every challenge's
clock (`challenges:roll-over` — the only caller of `RollOverPeriod`, plus the
Scheduled→Active→Completed lifecycle flips) and remind people of their obligations
(`challenges:reminders` + the staggered `SendReminder` job). Plus `/language`, the one
command that changes *who the bot is for a user* rather than what it does for them.

**What landed.**
- `app/Actions/Reminders/ScheduleChallengeReminders.php` — the first of three
  separable decisions: *what should exist*. Mints `ReminderDispatch` rows for every
  period that opens or closes within a 24h horizon (bulk `insertOrIgnore`; the unique
  `(participant, period, kind)` index is the arbiter). Kinds: `ChallengeStarting`
  (period 0's opener — never also a "period 1 open", which would be one message said
  twice), `PeriodOpened` (index ≥ 1), `PeriodEnding` (a configurable lead before the
  close, clamped to the period's own start). Only participants who owe the period
  (`active()` and `joined_period_index <= index`) get rows — late joiners are never
  reminded of periods that were never theirs.
- `app/Actions/Reminders/DispatchDueReminders.php` — the second decision: *what goes
  out now*. One batch of 30 due rows, each handed to `SendReminder` with a
  `delay(position seconds)` stagger. Batch × stagger (30s) < one cron tick (60s), so
  the batch drains before the next sweep could re-dispatch in-flight rows.
- `app/Jobs/Telegram/SendReminder.php` — the send, and the third decision: *is it
  still true?* `lockForUpdate` on the row, re-checks `sent_at` (the idempotency
  token, stamped only after a successful send), suppresses when the period has since
  been swept or the check-in has been settled, composes the copy in the challenge's
  own timezone, and stamps.
- `app/Console/Commands/Challenges/RollOverDuePeriodsCommand.php` (`challenges:roll-over`)
  — activates started challenges, sweeps ended periods (chunked, `PeriodNotEndedException`
  tolerated: the clock may move between query and settle), completes timelines whose
  periods are all swept. Completion reward deliberately NOT paid here — a coin
  movement, deferred to the payments phase per the recorded plan.
- `app/Console/Commands/Challenges/SendRemindersCommand.php` (`challenges:reminders`)
  — schedules for every non-terminal challenge with a near-future period, then
  dispatches what is due.
- `routes/console.php` — both commands every minute; docblock records the shared-host
  contract (`schedule:run` + `queue:work --stop-when-empty --max-time=55` each minute).
- `LanguageCommand` (`/language`) + `LanguageCallback` (`lg:<code>`): one row of
  buttons labelled with each locale's own name for itself, a tap validated against
  the `Localization` allowlist (a crafted payload cannot pick a locale we cannot
  serve), confirmation sent *after* the change so it is the first message in the new
  language. `SettingKey::ReminderEndingLeadHours` (default 3, admin-tunable) added to
  the registry. Lang: `bot.reminder.*` (one line per `ReminderKind`) and
  `bot.language.*` in en + fa.

**Decisions.**
- *Three idempotency domains, three owners.* Existence (the unique index), dispatch
  (rows re-dispatch harmlessly if a worker dies — they stay due until `sent_at`),
  and the send itself (`lockForUpdate` + re-check + stamp-after-send). Each is safe
  to re-run independently, so a missed cron self-heals on the next tick.
- *The stagger is the rate limit.* Telegram allows ~1 msg/sec per chat; the batch
  trickles one send a second rather than blasting, and the batch is sized to drain
  within one cron minute so the next sweep cannot double-dispatch.
- *Suppression is a send-time question.* A period swept between minting and sending,
  or a check-in settled in the meantime, stamps the row without sending — the moment
  to be reminded about is gone. This is why `SendReminder` re-derives the truth
  rather than trusting the row it was handed.
- *No rows for windows that passed during downtime.* A window already gone when the
  scheduler first sees it never gets a row, so nobody gets a "period open!" three
  days late for a period that closed. Deliberate, documented on the action.
- *`/language` is ungated* for the same reason `/cancel` is: a user blocked at the
  gate still deserves to read the blocking message in a language they understand.
- *Rollover owns the lifecycle flips.* Scheduled→Active and Active→Completed belong
  to the clock, not to whichever user action happens to notice the date first.

**Traps met.** PHP arrays cannot key by enum — `kindsFor()` first returned an
`array<ReminderKind, …>` map (an "Illegal offset type" waiting for the first real
run) and now returns a list of `['kind' => …, 'scheduled_for' => …]` pairs.
`eachById()` returns bool, not a count. `travelTo($start->subHour())` mutates a
mutable Carbon in place, so the shared fixture's `$start` is deliberately immutable.
The participant factory grants one freeze by default, so a "missed" test must pin
`freezes_total => 0` or the miss quietly becomes a freeze.

**Assumptions / follow-ups recorded.** The §6 reminder-boundary verification now has
an automated test (freeze the clock, sweep twice at each boundary, exactly one send)
— the remaining §6 items are the live-token webhook replay and the payments/Mini App
rejections. The completion transition exists but credits nothing yet (payments
phase). Reminder materialisation cost at scale is bounded by the 24h horizon but not
otherwise optimised (documented on the action). Carried unchanged: all standing
follow-ups from Task 6.

**Result — `sail composer ci:check` GREEN:** eslint ✓, prettier ✓, `tsc --noEmit` ✓,
pint ✓, phpstan lvl 7 (0 errors) ✓, tests **1012 (1008 pass, 4 skipped = Fortify 2FA
disabled)**, 2751 assertions, +18 on the suite across
`tests/Feature/Bot/ReminderSweepTest.php` (6), `ChallengeRolloverCommandTest.php`
(7) and `LanguageCommandTest.php` (5) — every one driven through the real command or
the real `ProcessTelegramUpdate` with `Http::fake()`d Bot API. Graph: 2716 nodes /
5169 edges / 231 communities.

**Next:** Phase 4 — Stars payments. `createInvoiceLink` with `XTR` currency and an
empty `provider_token`, the `pre_checkout_query` answered promptly, and the
`successful_payment` credit keyed on `telegram_payment_charge_id` through
`CoinLedger` — the third §6 idempotency verification.

### Payments Task 1 — Stars purchase and refund ✅
Both Phase-4 checklist items landed together: the purchase flow and the refund
path share the same row and the same ledger, so splitting them would have left
the refund action tested only against hand-built state.

**The flow.** `/shop` (gated) lists the admin-tuned `StarsPackages` setting,
one button row per package carrying a *package index* — never a price.
`CreateStarsInvoice` reads the setting itself at tap time, writes the Pending
`StarPayment` row *before* calling `createInvoiceLink` (XTR, empty-string
`provider_token`, one price line, title/description resolved in the payer's
locale), and replies with the link as a URL button. `pre_checkout_query`
(own update kind, own handler) is answered against the row's own `stars_amount`
— a decline mutates nothing, because a stale or malformed query is not a
judgement on the invoice. `successful_payment` rides inside a `message` with no
text, so `MessageHandler` intercepts it after user resolution and before command
parsing; `CompleteStarsPayment` validates amounts against the row (we priced it,
not the client), flips Pending→Paid and credits through `CoinLedger` in one
transaction. `RefundStarsPayment` is Telegram-first: the raw
`refundStarPayment` post, then Refunded, then the clawback debit — a Telegram
refusal leaves the row Paid and the whole refund retryable.

**Decisions:**
- *Three layers of charge-id idempotency* (documented on `CompleteStarsPayment`):
  the row found by `invoice_payload` **scoped to the user** under `lockForUpdate`
  (a learned payload cannot credit the wrong payer), the status transition +
  credit in one transaction, and the unique charge-id index + ledger key
  `star_payment:credit:<charge_id>`. The refund key is deliberately distinct
  (`star_payment:refund:<charge_id>`), so a refund and a replay of the credit
  can never collide.
- *Prices are read server-side at invoice time.* The button carries an index;
  the action re-reads the setting, so a stale keyboard cannot buy at a stale
  price — and an admin reprice between list and tap is honoured, tested.
- *Refunds may overdraw.* `StarsRefund.allowsOverdraft()` is true by design: a
  user who bought, spent, and was refunded has had both goods and money; a
  negative balance is the ledger saying so until cleared.
- *Invoice copy belongs to the action.* `createInvoiceLink`'s title/description
  (what Telegram itself shows in the payment sheet) resolve from `bot.shop.*`
  in the payer's locale inside `CreateStarsInvoice`, so every surface that ever
  sells coins words the sheet identically. The title is Telegram-capped at 32
  chars, so it stays terse.
- *No wrapper for `refundStarPayment` in SDK 3.16* — it travels as a raw
  `$api->post('refundStarPayment', [...])` over the same fakeable
  `LaravelHttpClient` transport, so tests still see it on the wire.
- *Abandoned Pending rows are not swept yet* (noted on `StarPaymentStatus`);
  they are carts, and a sweep-to-Failed command is an admin-phase nicety.

**§6 verification — now automated.** Three deliveries of the same
`successful_payment` (fresh `update_id`s each time, so the webhook's own
dedup never sees the second or third) carrying one `telegram_payment_charge_id`
→ exactly one `CoinTransaction`, balance 110, drift 0. Remaining §6 items:
the live-token webhook replay (manual, needs a real bot) and the Mini App
initData rejections (Phase 5).

**Traps met.** `Http::recorded()`'s callback filter *preserves original keys* —
a `createInvoiceLink` that is not the first recorded request is not at offset 0,
hence `->values()` before indexing (the shared `botMessages()` helper has always
done this; the new local helpers had to learn it too). `Http::fake()` registers
per test, not per `beforeEach`: the gate-blocked test registers its own
`getChatMember → left` stub as the first matching pattern, which is the only
order that wins. The bot's `/shop` listing is `paragraphs()` — prompt plus one
blank-line-separated line per package — so the assertion is `toContain` on the
prompt, not `toBe` on the whole message.

**Result — `sail composer ci:check` GREEN:** eslint ✓, prettier ✓, `tsc --noEmit`
✓, pint ✓, phpstan lvl 7 (0 errors) ✓, tests **1038 (1034 pass, 4 skipped =
Fortify 2FA disabled)**, 2847 assertions, +26 on the suite across
`tests/Feature/Payments/StarsPurchaseTest.php` (18) and
`tests/Feature/Payments/RefundStarsPaymentTest.php` (8) — all driven through the
real `ProcessTelegramUpdate`/actions with `Http::fake()`d Bot API, never a real
Telegram endpoint. Graph: 2760 nodes / 5339 edges / 251 communities.

**Next:** Phase 5 — Mini App. `POST /api/v1/miniapp/auth` verifying
`Telegram.WebApp.initData` (HMAC-SHA256 with the token-as-message/"WebAppData"-
as-key argument order, `hash_equals`, `auth_date` window) and issuing a
short-lived Sanctum bearer token, then the `/api/v1/miniapp/*` surface and the
React SPA behind it — including the §6 tampered-hash and stale-`auth_date`
rejection tests.

### Mini App Task 1 — initData authentication ✅
The Mini App's identity exchange: `POST /api/v1/miniapp/auth` swaps a signed
`Telegram.WebApp.initData` for a short-lived Sanctum bearer token, and a minimal
`GET /api/v1/miniapp/me` proves the token round-trips and gives the SPA its
boot shape (user + coin balance).

**The pieces.** `InitDataVerifier` implements the exact HMAC chain CLAUDE.md
pins down — `secret_key = HMAC_SHA256(<bot_token>, "WebAppData")` with the
token as the *message*, data-check-string over every field except `hash` and
`signature`, sorted alphabetically, `key=value` joined with `\n`, compared with
`hash_equals` (lowercased first, since `hash_equals` is byte-exact and a client
round-trip could uppercase the hex). `AuthenticateMiniAppUser` resolves the
user through the *same* `ResolveTelegramUser` action the bot uses (initData's
decoded `user` object is the same shape as an update's `from`), then mints a
Sanctum token with the `miniapp` ability and an `expires_at` from the
`MiniAppTokenTtlMinutes` setting. `VerifiedInitData` carries the decoded user
plus the other fields, where a deep link's `start_param` will surface later.

**Decisions:**
- *One refusal message, every reason.* Tampered vs malformed vs stale differ
  only in the `Log::warning` — the HTTP response never says which, so a
  forger can't learn how close their forgery was.
- *Tokens are minted per exchange, not deduplicated.* Each Mini App open
  brings a fresh initData and gets a fresh token; the previous one ages out.
  `sanctum:prune-expired` is scheduled daily to keep the table from growing
  one app-open at a time.
- *auth_date window ≠ token lifetime.* `InitDataMaxAgeSeconds` governs only
  the exchange; after that the Sanctum token's own `expires_at` governs
  (Sanctum's guard checks it natively — no global `SANCTUM_TOKEN_EXPIRATION`
  needed, which would have leaked into every token the app ever issues).
- *The channel gate is not at auth.* Authenticating is not a privileged act;
  the gate belongs to the Mini App surface that can show a join-prompt UX.
  Recorded as an open question for the surface task, alongside what the Mini
  App does for a user whose gate is closed.
- *Mini App auth creates the user row* (same action as `/start`). Invite
  attribution is unaffected in practice — invite links open the bot
  (`?start=`), not the Mini App — but the interaction is real and deliberate.
- *Sanctum middleware aliases registered manually* in `bootstrap/app.php`:
  this Sanctum version ships `CheckForAnyAbility`/`CheckAbilities` without
  registering the `ability`/`abilities` aliases itself.
- *`/me` re-resolves the actor from the bearer token alone* — the pattern
  every later `/api/v1/miniapp/*` endpoint follows per CLAUDE.md's
  "never trust a client-supplied id" rule.

**§6 — two more verification targets closed, automated.** A tampered hash
(`strrev` of a real one), a payload re-signed over different contents, a
correctly-signed-but-foreign-bot-token payload, and a stale `auth_date` (one
second past the window) are each rejected with 401 and *no user or token rows
created*; the window's exact edge is accepted; both TTLs honour admin-tuned
settings. Remaining §6: the live-token webhook replay (manual, needs a real
bot) and the real-device Mini App launch.

**Result — `sail composer ci:check` GREEN:** eslint ✓, prettier ✓, `tsc
--noEmit` ✓, pint ✓, phpstan lvl 7 (0 errors) ✓, tests **1059 (1055 pass,
4 skipped = Fortify 2FA disabled)**, 2896 assertions, +21 in
`tests/Feature/MiniApp/MiniAppAuthTest.php`. Graph: 2782 nodes / 5361 edges.

**Next:** the `/api/v1/miniapp/*` surface — challenges the user is in,
per-challenge status (streak, freezes used/remaining, current period
progress), driven by Eloquent API Resources over the existing domain Actions
and queries, all behind `auth:sanctum` + `ability:miniapp`.

### Mini App Task 2 — challenges/status JSON surface ✅

`GET /api/v1/miniapp/challenges` lists the participations the token's user is
in, and `GET /api/v1/miniapp/challenges/{id}` shows one challenge's full
picture — the shared timeline, their streak and freezes, the period open
right now with whether they still owe it a check-in, and the history of
settled periods since they joined. `ChallengeResource` wraps the
`ChallengeParticipant` (eager-loading `challenge.periods` + `checkIns`), so
"the challenge" and "how I am doing in it" are one shape, not two endpoints
the SPA has to stitch. +10 tests in
`tests/Feature/MiniApp/ChallengesSurfaceTest.php`.

**Decisions:**
- *The list is participations, not challenges.* Creating does not enrol, so a
  challenge the user runs but never joined is not on their dashboard — the
  creator's surface is the admin panel / review queue, later.
- *Every enum ships as `{value, label}`* via `HasTranslatedLabel`, resolved in
  the ambient locale `SetLocale` picked (the token user's stored preference
  first, then `Accept-Language`, then fallback) — the SPA never hardcodes a
  label. Tested in both languages.
- *`owes_check_in` is owed AND still actionable:* `owesPeriod()` (status +
  `index >= joined_period_index`) *and* the check-in row, if any, still
  `allowsSubmission()` — an approved or under-review period is not owed; a
  rejected one is (resubmission allowed while open).
- *History starts at `joined_period_index`* — the late-joiner rule, one
  place. A period closed but not yet swept by rollover shows `pending`
  (momentary, the sweep runs every minute; the SPA's grid still needs the
  slot).
- *404 uniformity:* `show` resolves the participant from the token's user and
  the route id together; somebody else's challenge is indistinguishable from
  a nonexistent one — no id enumeration.
- *`HasTranslatedLabel` became a real interface*
  (`App\Enums\Contracts\HasTranslatedLabel`), implemented by all twelve
  label-bearing enums, with the trait keeping the implementation. Reason:
  only classes and interfaces can appear in native type positions, and the
  resource's `enum()` needed `BackedEnum&HasTranslatedLabel` as an actual
  parameter type. Cost: a one-line `implements` + import per enum.

**Result — `sail composer ci:check` GREEN:** eslint ✓, prettier ✓, `tsc
--noEmit` ✓, pint ✓, phpstan lvl 7 (0 errors) ✓, tests **1069 (1065 pass,
4 skipped)**, 2922 assertions. Graph: 2824 nodes / 5490 edges.

**Next:** the Mini App SPA itself — the React app in
`resources/js/miniapp/` consuming this surface through a typed API client,
wiring Telegram `themeParams`/`BackButton`/`MainButton`, and settling where
the channel gate lives in the Mini App UX.

### Mini App Task 3 — the React SPA and its one-tap check-in ✅

The Mini App is real: `resources/js/miniapp/` is now the gameish dashboard
(boot → list ↔ detail), and `POST /api/v1/miniapp/challenges/{id}/check-in`
is the one endpoint it can act through. +10 tests
(`tests/Feature/MiniApp/MiniAppCheckInTest.php`, +2 in
`tests/Feature/LocalizationTest.php`); the SPA itself has no JS test
framework (per CLAUDE.md, none is being introduced), so the PHP side tests
everything the SPA depends on: the check-in wiring, the gate, the refusal
reasons, and that the `miniapp` catalogue actually ships to the client.

**Decisions:**
- *One write endpoint, one proof type.* `button` challenges check in from the
  Mini App via `SubmitCheckIn::tap` — the same action the bot calls, which is
  the CLAUDE.md architecture proving itself. Phrase and photo proofs keep
  their bot path for now; recorded as follow-ups.
- *The channel gate lives at the write endpoint, not at auth.* Answering the
  open question from Task 1: authenticating is not privileged, submitting
  proof is. A stale gate is re-verified against Telegram; a blocked user gets
  a 403 with the join link (or an honest "there is no link" for a numeric
  channel id).
- *Refusals are machine-readable; the sentence is the SPA's.* The endpoint
  returns `{reason}` and 422/403; the SPA translates from its own catalogue.
  A reason with no sentence degrades to the generic one, never a raw key.
- *The token lives in memory only* — module-private in `api.ts`, one fetch
  wrapper, no interceptors. Each app open re-exchanges initData, which is the
  server's designed per-exchange minting.
- *No `@telegram-apps/sdk` dependency*: the used slice of the platform script
  is typed locally in `telegram.ts` (with `null` for "opened in a plain
  browser", which every caller must degrade for). Adding a client library for
  six calls is not worth a dependency on a mobile network.
- *Own stylesheet, not the admin's `app.css`*: the Mini App's colours come
  from `themeParams` (mapped onto `--tg-*` CSS vars by `theme.ts`, with
  Telegram's light defaults as the in-CSS fallback), which the admin bundle's
  dark/light cookie system can never serve. Tailwind sources are pinned to
  the miniapp directory so each bundle carries only its own classes.
- *MainButton shows only while it can work* — period owed *and* proof is a
  tap; other proof types get a "check in via the bot" hint instead of a
  button that could not succeed. BackButton (version-gated ≥ 6.1) leaves a
  detail for the list.
- *Detail receives the whole `ChallengeView`, never an id* — no refetch on
  first paint, and the check-in response replaces the whole state.
- *Deep-link `start_param` is ignored* for now: resolving a join code needs a
  join endpoint the API does not have. Follow-up.

**Follow-ups added:** Mini App phrase check-in (needs `expected_phrase`
exposed to the participant's own view), photo upload (needs a file surface),
deep-link start_param → join flow, Jalali dates for `fa` (existing).

**Result — `sail composer ci:check` GREEN:** eslint ✓, prettier ✓, `tsc
--noEmit` ✓, pint ✓, phpstan lvl 7 (0 errors) ✓, tests **1079 (1075 pass,
4 skipped)**, 2988 assertions; `vite build` compiles both entries.

**Next:** Phase 6 — the admin panel (Inertia + React + TS): settings/economy
tuning, challenge moderation, image-proof review queue, user and coin
adjustments, invite/payment audit views, and the refund surface calling
`RefundStarsPayment`.

---

## Admin Task 1 — panel foundation + settings/economy tuning

**Branch/commit:** `feat(admin): settings panel — registry-driven tuning surface` (+ separate
`docs(progress)` commit).

**What was built**

- **The gate.** `EnsureUserIsAdmin` middleware on every `/admin/*` route — `auth` runs first so a
  guest is redirected to login (not 403), then `is_admin` is re-checked on every request. The Form
  Request re-checks `authorize()` again, per the CLAUDE.md rule about re-validating authorization on
  every request. `is_admin` remains un-fillable on `User`; tests mint admins through the factory's
  `admin()` state.
- **The surface.** `routes/admin.php`: `GET /admin/settings`, `PUT/DELETE /admin/settings/{setting}`.
  All Inertia. Wayfinder helpers regenerated (`resources/js/routes/admin/settings/`).
- **The form is generated, not enumerated.** `Admin\SettingsController` builds its rows from
  `SettingKey::cases()` via a GROUPS presentation map (economy / baseline / access / reminders).
  Adding a tunable to the registry makes it appear on the page with zero further registration; the
  label comes from `lang/{en,fa}/admin.php` keyed by the enum value.
- **Writes only through the service.** `Settings::set()`/`forget()` — never the `Setting` model —
  so every write is type-checked against the registry and the cache is flushed (proved by a test
  reading the new value back immediately). Double validation: the Form Request rules are derived
  from the key's declared `SettingType` (integer/text/json-packages rules), so nothing can satisfy
  validation that storage would reject. Unknown key → uniform 404.
- **`Settings::isOverridden()`** added — drives the "overridden · default: X" hint and the
  reset-to-default button, which DELETEs the override row.
- **The React page** (`resources/js/pages/Admin/Settings.tsx`): grouped cards, per-type editors —
  number/text inputs (integers forced `dir="ltr"` for RTL locales), an editable Stars-packages
  table with add/remove rows, a checkbox branch ready for the first `Boolean` registry key. Uses
  `useTranslation()` (`admin.*` added to `client_groups`), flash toasts via sonner, breadcrumbs via
  `Page.layout`. RTL-safe: logical utilities only.
- **Sidebar entry.** `AppSidebar` adds an "Admin → Settings" link when `auth.user.is_admin` (the
  User TS type gained `is_admin?: boolean`; the model already serialises it). Offered, not relied
  on — the server re-checks on every request.
- **Tests** (`tests/Feature/Admin/SettingsPanelTest.php`, 13): guest redirect, non-admin 403 on
  all three routes, full 15-row grouped shape with defaults/labels/types, override persists with
  cache flush, overridden flag appears, invalid value 422 (no row written), unknown key 404,
  reset reverts to default, Stars-package table rewrite + malformed rejection with the catalogue
  message, required-channel text update.

**Decisions**

- *PUT/DELETE per row*, not one giant form: each setting is its own small `useForm`, so a bad value
  on one row can't block the rest, and reset is naturally per-setting.
- *Boolean editor written but unused* — no `Boolean` registry key exists yet; the branch exists so
  the first one needs no page change.
- *Breadcrumbs stay English at module scope* (Inertia `Page.layout` is static — no hooks there);
  page body copy is fully translated. Same trade the existing settings pages make; a proper fix is
  the starter-kit → `t()` conversion follow-up.
- *Intra-row key order of stored packages* can differ from the submitted order after the
  request→JSON round-trip; the test compares rows semantically, the UI is keyed by index anyway.

**Result — `sail composer ci:check` GREEN:** eslint ✓, prettier ✓, `tsc --noEmit` ✓, pint ✓,
phpstan lvl 7 (0 errors) ✓, tests **1092 (1088 pass, 4 skipped)**, 3097 assertions; `vite build`
compiles both entries.

**Next:** Admin Task 2 — challenge moderation and the image-proof review queue (list challenges by
status, creator-side proof approval from the panel through the same review Action the bot uses),
then user/coin adjustments via `CoinLedger`, invite/payment audit views, and the Stars refund
surface.

---

## Admin Task 2 — challenge moderation + image-proof review queue

**Branch/commit:** `feat(admin): challenge moderation and the image-proof review queue` (+ separate
`docs(progress)` commit).

**What was built**

- **`CancelChallenge` action** (`app/Actions/Challenges/CancelChallenge.php`) — the first
  cancellation path in the codebase (the bot's `/cancel` only ends wizard conversations). One-way
  door: terminal statuses refused with `ChallengeNotCancellable`. Authorization derived from the
  row — creator or platform admin. Participants are notified by the bot.
- **`SendBotMessage` job** (`app/Jobs/Telegram/SendBotMessage.php`) — the generic queued one-line
  bot send: user re-resolved by id at send time (deleted/web-only users end quietly), locale per
  recipient, `tries = 5`. The fan-out half of everything that is not a reply.
- **Staggered notification** — `CancelChallenge` dispatches one `SendBotMessage` per *active*
  participant with `delay($position * 1s)`, matching `DispatchDueReminders`' stagger, inside the
  transaction so nothing sends if the cancellation does not commit.
- **`NotifyCheckInVerdict` service** — the participant-side verdict message extracted out of
  `ReviewCheckInCallback` so the bot's inline buttons and the panel's queue send the *same* words
  for the same outcome. PHPStan caught a genuine bug in the extraction (an undefined `$rejected`
  after the refactor) before it could ship — the guard is real.
- **Review queue surface** (`Admin\ReviewQueueController` + `Admin/Reviews.tsx`): every
  `Submitted` check-in (image proofs) with challenge, participant, period and a proof thumbnail.
  Approve/reject go through `ReviewCheckIn` — the same action the bot calls, no second copy of the
  rule — and notify the participant synchronously; a refusal (rollover beat the click) becomes an
  error toast keyed by `CheckInRejection::value`, not a 500.
- **Proofs served through a gated route** (`GET /admin/reviews/{checkIn}/proof`) — streamed from the
  `local` disk behind `auth` + admin; 404 when the row has no photo or the file is gone. The proof
  path never appears in a URL; the admin URL is the only door.
- **Moderation surface** (`Admin\ChallengesController` + `Admin/Challenges.tsx` /
  `Admin/Challenges/Show.tsx`): newest-first listing (deterministic `created_at, id` order), status
  filter, title search, simple pagination with the query string carried; detail view with the
  challenge's shape (enums as `{value, label}`) and participants with streaks/freezes; a confirmed
  Cancel button on non-terminal challenges.
- **Middleware priority fix** (`bootstrap/app.php`): without it, route-model binding answered
  before `EnsureUserIsAdmin`, so a non-admin probing admin URLs learned from a 404 which row ids
  exist. The priority list now puts the gate before `SubstituteBindings` — every id reads the same
  to a non-admin (403). Tests pin this with nonexistent ids.
- **Sidebar**: admins get Challenges / Proof review / Settings nav items. Wayfinder regenerated.
- **Tests** — `tests/Feature/Admin/ReviewQueueTest.php` (11): gates on every route, queue contents
  (settled rows and non-photo challenges excluded), proof route auth/404/content-type, approve via
  the shared action (streak moves, participant notified in-request, `Http::assertSent`), reject,
  already-settled toast, empty queue, id probing. `tests/Feature/Admin/ChallengeModerationTest.php`
  (8): gates, listing shape/order, status+title filters (unknown status = no filter), detail shape,
  cancel → status + 3 staggered `SendBotMessage` (delays 0/1/2s, left participants excluded),
  terminal refusal. `tests/Feature/Domain/CancelChallengeTest.php` (6): creator may, admin may,
  bystander refused, second cancellation refused, notification stagger/targeting, nobody-to-tell.

**Decisions**

- *Verdict notification is synchronous, cancellation notification is queued.* A review is one
  message in direct response to an admin click; a cancellation can be hundreds, which is exactly
  what the stagger exists for.
- *Only `active` participants hear about a cancellation* — `left`/`removed` participants are done
  being told things.
- *No new UI components*: no `table.tsx` exists in the shadcn set, so the listings use the same
  plain-`<table>` markup the Settings packages editor uses, rather than importing a component
  library.
- *Breadcrumbs stay English at module scope* (same trade as Task 1 — `Page.layout` is static);
  body copy fully translated, en + fa.
- *`SendBotMessage` is deliberately not `SendReminder`-shaped*: no row, no `sent_at` stamp, no
  idempotency token — it is one message to one user, retryable as a unit. If a future announcement
  needs exactly-once delivery, that is a new dispatch table in the `ReminderDispatch` style, not
  this job.

**Result — `sail composer ci:check` GREEN:** eslint ✓, prettier ✓, `tsc --noEmit` ✓, pint ✓,
phpstan lvl 7 (0 errors) ✓, tests **1118 (1114 pass, 4 skipped)**, 3302 assertions; `vite build`
compiles both entries.

**Next:** Admin Task 3 — users, coins and audits: user lookup with balance, admin coin adjustments
through `CoinLedger` (never a bare write), invite and Stars-payment audit views, and the Stars
refund surface calling `refundStarPayment`.

## Admin Task 3 — users, coin adjustments, audit views, Stars refunds

**Commit:** (this commit)

**What shipped**

- **`App\Actions\Coins\AdjustUserCoins`** — the support-desk lever. `credit()`/`debit()` map onto
  `AdminCredit`/`AdminDebit` (a caller cannot reach for any other reason) and hand off to
  `CoinLedger::record()`, so the panel's coin writes get the same row lock, idempotency key and
  sign-from-reason rule as every other coin mutation. The idempotency key is a fresh UUID per call:
  Telegram flows replay the *same* update (key derived from Telegram's ids); an admin form
  submission is a new human decision every time — there is nothing external to key on. The
  reference morph points at the admin who acted: the ledger row names its own author, which is the
  whole audit trail. Admin debits may overdraw (`AdminDebit::allowsOverdraft`) — a manual clawback
  a balance could veto is one that cannot happen; the test pins this.
- **Users surface** (`Admin\UsersController` + `Admin/Users.tsx` / `Admin/Users/Show.tsx`):
  search by name / telegram username / first name, and — when the query is bare digits — by
  Telegram id (the one thing support reliably has in hand). Balances come from
  `CoinLedger::balanceFor()`, never a column. The detail page shows the statement (latest 20
  entries: reason label, signed amount, running balance) **and the drift** between the cached
  running total and `sum()` — a corrupt ledger is visible from the panel instead of discovered in
  a dispute. Adjust form posts `{amount, direction}` through `AdjustCoinsRequest` (positive
  integer, `credit|debit` — the request shape *is* the whole vocabulary).
- **Payments audit + refund** (`Admin\PaymentsController` + `Admin/Payments.tsx`): newest-first
  listing of every `StarPayment` with user, stars, coins, status, charge id and refundability;
  confirm-then-refund posts to the shared `RefundStarsPayment` action (Telegram first, row second,
  clawback through the ledger — the panel gets no private path). `LogicException` (unrefundable
  row) and `TelegramSDKException` (no token / Telegram refused) both become toasts; the action's
  ordering means the row stays paid and retryable.
- **Invites audit** (`Admin\InvitesController` + `Admin/Invites.tsx`): read-only — whether a code
  earned coins was settled by `ClaimInvite` at the invited user's first `/start`, so an audit that
  could be hand-edited would be an audit nobody could trust. Shows inviter / invited / status /
  credited_at.
- **`SweepAbandonedStarPayments` action + `payments:sweep-abandoned` command** (daily schedule) —
  pending payments older than 24h become `Failed`. Pending rows never credited a coin, so the
  sweep moves nothing anyone owes; it exists so the audit stays a list of purchases rather than a
  landfill of abandoned carts. The window is an operational constant, deliberately not a
  `Setting` (not a rate or price).
- **Token-less read resilience** (fix for a live report): the Bot API binding throws at
  construction when `TELEGRAM_BOT_TOKEN` is unset, and the review/payments controllers
  constructor-injected actions that transitively hold `Api` — so *browsing* those pages 500'd on a
  box without a token. Both controllers now method-inject their actions: read routes never resolve
  the Telegram client. The review verdict also split its try/catch: the verdict lands even when
  the participant notification cannot be sent (`notify_failed` toast distinguishes the two).
  Pinned by `reads the audit pages without a bot token being set`.
- Sidebar gains Users / Star payments / Invites; en+fa copy for all of it; Wayfinder regenerated.

**Tests** — `tests/Feature/Admin/UsersAndCoinsTest.php` (10) + `PaymentsAndInvitesTest.php` (9):
route gates, listing order/search (id/username/name), statement shape with drift, credit writes
through the ledger with the admin as reference, debit, overdraft-allowed clawback, validation
(zero/negative/missing amount, unknown direction), payments listing shape, refund through the
shared action (one wire call, balance clawed to 0), unrefundable toast, token-less reads, invite
states, sweep window boundary (23h stays, 25h+ goes, paid untouched).

**Result — `sail composer ci:check` GREEN:** pint ✓, phpstan lvl 7 (0 errors) ✓, eslint ✓,
prettier ✓, tsc ✓, tests **1143 (1139 pass, 4 skipped)**, 3562 assertions.

**Next:** Phase 7 — Website: the marketing landing (Inertia, `routes/web.php`), then Phases 8–13
per `prompts/phase-8.md` onward.
