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

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.5. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `vendor/bin/sail npm run build`, `vendor/bin/sail npm run dev`, or `vendor/bin/sail composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists (settled decisions, non-obvious traps, standing constraints). Framework and package guidelines that only apply to specific paths (testing, frontend, components) also live there, under `.ai/rules/boost` — this is not just recorded decisions, it is load-bearing guidance you have not seen inline. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.
- Record durable rules with `record-rule` so the next agent or teammate inherits them instead of working them out again. Pass a `glob` (e.g. `app/Http/Controllers/**`), a short `title`, and a few-line `note`. Always use `record-rule`, never your native memory or notes tool — native memory is personal and session-scoped; only `.ai/rules` is shared with the team and persists in the repo.

## Artisan

- Run Artisan commands directly via the command line (e.g., `vendor/bin/sail artisan route:list`). Use `vendor/bin/sail artisan list` to discover available commands and `vendor/bin/sail artisan [command] --help` to check parameters.
- Inspect routes with `vendor/bin/sail artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `vendor/bin/sail artisan config:show app.name`, `vendor/bin/sail artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `vendor/bin/sail artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `vendor/bin/sail artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== sail rules ===

# Laravel Sail

- This project runs inside Laravel Sail's Docker containers. You MUST execute all commands through Sail.
- Start services using `vendor/bin/sail up -d` and stop them with `vendor/bin/sail stop`.
- Open the application in the browser by running `vendor/bin/sail open`.
- Always prefix PHP, Artisan, Composer, and Node commands with `vendor/bin/sail`. Examples:
    - Run Artisan Commands: `vendor/bin/sail artisan migrate`
    - Install Composer packages: `vendor/bin/sail composer install`
    - Execute Node commands: `vendor/bin/sail npm run dev`
    - Execute PHP scripts: `vendor/bin/sail php [script]`
- View all available Sail commands by running `vendor/bin/sail` without arguments.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `vendor/bin/sail artisan test --compact` with a specific filename or filter.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-react-development` when working with Inertia client-side patterns.

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (`useHttp` hook), optimistic updates with automatic rollback, layout props (`useLayoutProps` hook), instant visits, simplified SSR via `@inertiajs/vite` plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- `Inertia::lazy()` / `LazyProp` has been removed. Use `Inertia::optional()` instead.
- Prop types (`Inertia::optional()`, `Inertia::defer()`, `Inertia::merge()`) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with `@inertiajs/vite` - no separate Node.js server needed during development.
- Event renames: `invalid` is now `httpException`, `exception` is now `networkError`.
- `router.cancel()` replaced by `router.cancelAll()`.
- The `future` configuration namespace has been removed - all v2 future options are now always enabled.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `vendor/bin/sail artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `vendor/bin/sail artisan list` and check their parameters with `vendor/bin/sail artisan [command] --help`.
- If you're creating a generic PHP class, use `vendor/bin/sail artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `vendor/bin/sail artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `vendor/bin/sail artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `vendor/bin/sail npm run build` or ask the user to run `vendor/bin/sail npm run dev` or `vendor/bin/sail composer run dev`.

=== wayfinder/core rules ===

# Laravel Wayfinder

Use Wayfinder to generate TypeScript functions for Laravel routes. Import from `@/actions/` (controllers) or `@/routes/` (named routes).

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/sail bin pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/sail bin pint --test --format agent`, simply run `vendor/bin/sail bin pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This application uses PHPUnit for testing. All tests must be written as PHPUnit classes. Use `vendor/bin/sail artisan make:test --phpunit {name}` to create a new test.
- If you see a test using "Pest", convert it to PHPUnit.
- Every time a test has been updated, run that singular test.
- When the tests relating to your feature are passing, ask the user if they would like to also run the entire test suite to make sure everything is still passing.
- Tests should cover all happy paths, failure paths, and edge cases.
- You must not remove any tests or test files from the tests directory without approval. These are not temporary or helper files; these are core to the application.

## Running Tests

- Run the minimal number of tests, using an appropriate filter, before finalizing.
- To run all tests: `vendor/bin/sail artisan test --compact`.
- To run all tests in a file: `vendor/bin/sail artisan test --compact tests/Feature/ExampleTest.php`.
- To filter on a particular test name: `vendor/bin/sail artisan test --compact --filter=testName` (recommended after making a change to a related file).

=== inertia-react/core rules ===

# Inertia + React

- IMPORTANT: Activate `inertia-react-development` when working with Inertia React client-side patterns.

</laravel-boost-guidelines>
