# Graph Report - challenges  (2026-08-25)

## Corpus Check
- 307 files · ~107,947 words
- Verdict: corpus is large enough that graph structure adds value.

## Summary
- 1958 nodes · 3167 edges · 210 communities (156 shown, 54 thin omitted)
- Extraction: 98% EXTRACTED · 2% INFERRED · 0% AMBIGUOUS · INFERRED: 63 edges (avg confidence: 0.78)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `1a0478ff`
- Run `git rev-parse HEAD` and compare to check if the graph is stale.
- Run `graphify update .` after code changes (no API cost).

## Community Hubs (Navigation)
- delete-user.tsx
- composer.json
- PasswordValidationRules.php
- scripts
- ChallengePeriod
- Illuminate\Database\Eloquent\Relations\BelongsTo
- devDependencies
- cn
- optionalDependencies
- sidebar.tsx
- EntitlementType.php
- Illuminate\Foundation\Testing\RefreshDatabase
- app-header.tsx
- dropdown-menu.tsx
- compilerOptions
- Challenge
- components.json
- ConversationState.php
- Localization
- AGENTS.md
- SubmitCheckIn Action
- Inertia React Development
- dependencies
- Inertia React Development
- use-appearance.tsx
- ReminderDispatchFactory
- SettingKey.php
- Laravel Fortify Development
- Illuminate\Database\Eloquent\Builder
- Laravel Fortify Development
- Challenge.php
- CoinTransactionReason.php
- Tailwind CSS Development
- Tailwind CSS Development
- DatabaseSeeder.php
- placeholder-pattern.tsx
- use-clipboard.ts
- index.ts
- require-dev
- eslint.config.js
- icon.tsx
- Detection Checklist
- globals
- @inertiajs/react
- @inertiajs/vite
- laravel-vite-plugin
- lucide-react
- InviteFactory
- @radix-ui/react-checkbox
- @radix-ui/react-collapsible
- @radix-ui/react-dialog
- @radix-ui/react-dropdown-menu
- @radix-ui/react-navigation-menu
- @radix-ui/react-select
- clsx
- @radix-ui/react-slot
- @radix-ui/react-tooltip
- react
- react-dom
- sonner
- tailwind-merge
- tailwindcss
- @tailwindcss/vite
- tw-animate-css
- @types/react
- @types/react-dom
- typescript
- vite
- @vitejs/plugin-react
- Apple Touch Icon (Laravel logo)
- Dependabot (github-actions)
- keywords
- Process
- Detection Checklist
- Process
- Architecture Best Practices
- Queue & Job Best Practices
- Security Best Practices
- Architecture Best Practices
- Queue & Job Best Practices
- Security Best Practices
- Build Progress — Telegram Challenges Platform
- Advanced Query Patterns
- Database Performance Best Practices
- Events & Notifications Best Practices
- Wayfinder Development
- Advanced Query Patterns
- Database Performance Best Practices
- Events & Notifications Best Practices
- Wayfinder Development
- require
- command
- Caching Best Practices
- Eloquent Best Practices
- Migration Best Practices
- Caching Best Practices
- Eloquent Best Practices
- Migration Best Practices
- scripts
- Project Goal — Autonomous Build Driver
- User
- .agents/skills/laravel-best-practices/SKILL.md
- Blade & Views Best Practices
- Error Handling Best Practices
- Task Scheduling Best Practices
- Testing Best Practices
- EntitlementFactory
- Blade & Views Best Practices
- Error Handling Best Practices
- Task Scheduling Best Practices
- Testing Best Practices
- Setup Task 1: Project Bootstrap
- CheckIn
- Collection Best Practices
- HTTP Client Best Practices
- utils.ts
- Routing & Controllers Best Practices
- Conventions & Style
- Validation & Forms Best Practices
- Collection Best Practices
- HTTP Client Best Practices
- Mail Best Practices
- Routing & Controllers Best Practices
- Conventions & Style
- Validation & Forms Best Practices
- config
- Configuration Best Practices
- .claude/skills/laravel-best-practices/SKILL.md
- psr-4
- laravel
- package.json
- laravel-boost
- eslint-import-resolver-typescript
- Illuminate\Database\Eloquent\Model
- eslint-plugin-react
- TelegramUpdateFactory
- prettier
- prettier-plugin-tailwindcss
- concurrently
- ChallengeParticipant
- SecurityTest
- ChallengeFactory
- ChallengePeriodFactory
- CheckInFactory
- UserFactory
- Illuminate\Database\Eloquent\Relations\HasMany
- ChallengeParticipantFactory
- breadcrumbs.tsx
- CoinTransactionFactory
- @radix-ui/react-separator
- new-ideas-TODO.md
- StarPaymentFactory
- PasswordResetTest
- User.php
- alert.tsx
- toggle-group.tsx
- ProfileUpdateTest
- @radix-ui/react-avatar
- useIsMobile
- babel-plugin-react-compiler
- @stylistic/eslint-plugin

## God Nodes (most connected - your core abstractions)
1. `cn()` - 125 edges
2. `User` - 99 edges
3. `ChallengePeriod` - 30 edges
4. `Challenge` - 27 edges
5. `TestCase` - 26 edges
6. `ChallengeParticipant` - 25 edges
7. `CoinTransaction` - 22 edges
8. `CoinLedger` - 22 edges
9. `CheckIn` - 20 edges
10. `Settings` - 19 edges

## Surprising Connections (you probably didn't know these)
- `Admin Panel` --references--> `FortifyServiceProvider`  [INFERRED]
  prompts/main.md → app/Providers/FortifyServiceProvider.php
- `User model Telegram extension` --references--> `User`  [EXTRACTED]
  CLAUDE.md → app/Models/User.php
- `CI Tests Workflow` --references--> `ci:check`  [EXTRACTED]
  .github/workflows/tests.yml → composer.json
- `Admin Panel` --conceptually_related_to--> `AppSidebar()`  [INFERRED]
  prompts/main.md → resources/js/components/app-sidebar.tsx
- `endedPeriod()` --references--> `Challenge`  [EXTRACTED]
  tests/Feature/Domain/StreakEngineTest.php → app/Models/Challenge.php

## Import Cycles
- None detected.

## Hyperedges (group relationships)
- **Four surfaces, one Laravel app** — prompts_main_bot_surface, prompts_main_miniapp_surface, prompts_main_admin_surface, prompts_main_website_surface [EXTRACTED 1.00]
- **Idempotency keys across the system** — claude_md_telegram_update_model, claude_md_star_payment_model, claude_md_checkin_model, claude_md_reminder_dispatch_model [EXTRACTED 1.00]
- **Three proof types** — prompts_main_proof_button, prompts_main_proof_text_autogen, prompts_main_proof_image_approval [EXTRACTED 1.00]

## Communities (210 total, 54 thin omitted)

### Community 0 - "delete-user.tsx"
Cohesion: 0.10
Nodes (26): DeleteUser(), Heading(), InputError(), PasswordInput(), Props, TextLink(), Button(), buttonVariants (+18 more)

### Community 1 - "composer.json"
Cohesion: 0.18
Nodes (10): autoload-dev, psr-4, description, license, minimum-stability, name, prefer-stable, Tests\\ (+2 more)

### Community 2 - "PasswordValidationRules.php"
Cohesion: 0.05
Nodes (23): CreateNewUser, ResetUserPassword, emailRules(), nameRules(), profileRules(), Controller, LocaleController, ProfileController (+15 more)

### Community 3 - "scripts"
Cohesion: 0.05
Nodes (40): scripts, ci:check, dev, lint, lint:check, post-autoload-dump, post-create-project-cmd, post-root-package-install (+32 more)

### Community 4 - "ChallengePeriod"
Cohesion: 0.17
Nodes (8): RollOverPeriod, PeriodNotEndedException, ChallengePeriod, Carbon\CarbonInterface, Illuminate\Support\Collection, endedPeriod(), obligation(), openPeriod()

### Community 5 - "Illuminate\Database\Eloquent\Relations\BelongsTo"
Cohesion: 0.10
Nodes (3): Invite, ReminderDispatch, Illuminate\Database\Eloquent\Relations\BelongsTo

### Community 6 - "devDependencies"
Cohesion: 0.12
Nodes (17): eslint-config-prettier, @eslint/js, eslint-plugin-import, eslint-plugin-react-hooks, @laravel/vite-plugin-wayfinder, devDependencies, eslint, eslint-config-prettier (+9 more)

### Community 7 - "cn"
Cohesion: 0.11
Nodes (25): Badge(), badgeVariants, Card(), CardContent(), CardDescription(), CardFooter(), CardHeader(), CardTitle() (+17 more)

### Community 8 - "optionalDependencies"
Cohesion: 0.13
Nodes (15): @laravel/multiplex, lightningcss-linux-x64-gnu, lightningcss-win32-x64-msvc, optionalDependencies, @laravel/multiplex, lightningcss-linux-x64-gnu, lightningcss-win32-x64-msvc, @rollup/rollup-linux-x64-gnu (+7 more)

### Community 9 - "sidebar.tsx"
Cohesion: 0.10
Nodes (30): footerNavItems, mainNavItems, NavFooter(), NavMain(), NavUser(), SheetDescription(), Sidebar(), SidebarContent() (+22 more)

### Community 10 - "EntitlementType.php"
Cohesion: 0.10
Nodes (14): ConsumeEntitlement, GrantFreeBaseline, EntitlementType, PurchaseEntitlement, freeAllowanceSetting(), CoinTransactionReason, SettingKey, priceSetting() (+6 more)

### Community 11 - "Illuminate\Foundation\Testing\RefreshDatabase"
Cohesion: 0.10
Nodes (9): Illuminate\Foundation\Testing\RefreshDatabase, Illuminate\Foundation\Testing\TestCase, PasswordConfirmationTest, RegistrationTest, VerificationNotificationTest, DashboardTest, ExampleTest, TestCase (+1 more)

### Community 12 - "app-header.tsx"
Cohesion: 0.13
Nodes (15): mainNavItems, Props, rightNavItems, AppLogo(), AppLogoIcon(), Sheet(), SheetContent(), SheetFooter() (+7 more)

### Community 13 - "dropdown-menu.tsx"
Cohesion: 0.10
Nodes (24): Avatar(), AvatarFallback(), AvatarImage(), DropdownMenu(), DropdownMenuCheckboxItem(), DropdownMenuContent(), DropdownMenuGroup(), DropdownMenuItem() (+16 more)

### Community 14 - "compilerOptions"
Cohesion: 0.10
Nodes (19): resources/js/**/*.d.ts, resources/js/**/*.ts, resources/js/**/*.tsx, compilerOptions, allowJs, baseUrl, esModuleInterop, forceConsistentCasingInFileNames (+11 more)

### Community 15 - "Challenge"
Cohesion: 0.15
Nodes (6): MaterialiseChallengePeriods, PeriodType, Challenge, Carbon\CarbonImmutable, CarbonImmutable, challengeOn()

### Community 16 - "components.json"
Cohesion: 0.11
Nodes (17): aliases, components, hooks, lib, ui, utils, iconLibrary, rsc (+9 more)

### Community 17 - "ConversationState.php"
Cohesion: 0.17
Nodes (9): expectsCallback(), expectsPhoto(), expectsText(), isCheckInStep(), isCreateChallengeStep(), static, BotConversationFactory, ConversationState (+1 more)

### Community 18 - "Localization"
Cohesion: 0.12
Nodes (9): HandleAppearance, HandleInertiaRequests, SetLocale, Localization, Illuminate\Contracts\Translation\HasLocalePreference, Illuminate\Foundation\Configuration\Middleware, Illuminate\Http\Request, Inertia\Middleware (+1 more)

### Community 19 - "AGENTS.md"
Cohesion: 0.06
Nodes (33): APIs & Eloquent Resources, Application Structure & Architecture, Artisan, Conventions, Deployment, Do Things the Laravel Way, Documentation Files, Foundational Context (+25 more)

### Community 20 - "SubmitCheckIn Action"
Cohesion: 0.06
Nodes (38): BotConversation (model), Challenge (model), ChallengeParticipant (model), ChallengePeriod (model), CheckIn (model), CoinTransaction (ledger), Entitlement (model), Invite (model) (+30 more)

### Community 21 - "Inertia React Development"
Cohesion: 0.07
Nodes (27): Basic Link Component, Basic Usage, Client-Side Navigation, Common Pitfalls, Deferred Props, Documentation, Form Component (Recommended), Form Component Reset Props (+19 more)

### Community 22 - "dependencies"
Cohesion: 0.22
Nodes (9): class-variance-authority, dependencies, class-variance-authority, @radix-ui/react-label, @radix-ui/react-toggle, @radix-ui/react-toggle-group, @radix-ui/react-label, @radix-ui/react-toggle (+1 more)

### Community 23 - "Inertia React Development"
Cohesion: 0.07
Nodes (27): Basic Link Component, Basic Usage, Client-Side Navigation, Common Pitfalls, Deferred Props, Documentation, Form Component (Recommended), Form Component Reset Props (+19 more)

### Community 24 - "use-appearance.tsx"
Cohesion: 0.08
Nodes (37): i18n + RTL Requirement, AppearanceToggleTab(), LanguageSwitcher(), Toaster(), Appearance, applyTheme(), getStoredAppearance(), handleSystemThemeChange() (+29 more)

### Community 25 - "ReminderDispatchFactory"
Cohesion: 0.31
Nodes (3): static, ReminderDispatchFactory, ReminderKind

### Community 26 - "SettingKey.php"
Cohesion: 0.11
Nodes (9): SettingType, type(), Setting, SettingKey, SettingType, Settings, SettingKey, static (+1 more)

### Community 27 - "Laravel Fortify Development"
Cohesion: 0.12
Nodes (16): Available Features, Best Practices, Custom Authentication Logic, Documentation, Email Verification Setup, Key Endpoints, Laravel Fortify Development, Passkeys Setup (+8 more)

### Community 28 - "Illuminate\Database\Eloquent\Builder"
Cohesion: 0.08
Nodes (6): BotConversation, ConversationState, StarPayment, TelegramUpdate, Illuminate\Database\Eloquent\Builder, Illuminate\Database\Eloquent\Factories\HasFactory

### Community 29 - "Laravel Fortify Development"
Cohesion: 0.12
Nodes (16): Available Features, Best Practices, Custom Authentication Logic, Documentation, Email Verification Setup, Key Endpoints, Laravel Fortify Development, Passkeys Setup (+8 more)

### Community 30 - "Challenge.php"
Cohesion: 0.13
Nodes (4): label(), options(), isAutoApproved(), requiresReview()

### Community 31 - "CoinTransactionReason.php"
Cohesion: 0.16
Nodes (7): isCredit(), isDebit(), sign(), InsufficientCoinsException, Illuminate\Database\Eloquent\Relations\MorphTo, inParallel(), Closure

### Community 32 - "Tailwind CSS Development"
Cohesion: 0.14
Nodes (13): Basic Usage, Common Patterns, Common Pitfalls, CSS-First Configuration, Dark Mode, Documentation, Flexbox Layout, Grid Layout (+5 more)

### Community 33 - "Tailwind CSS Development"
Cohesion: 0.14
Nodes (13): Basic Usage, Common Patterns, Common Pitfalls, CSS-First Configuration, Dark Mode, Documentation, Flexbox Layout, Grid Layout (+5 more)

### Community 34 - "DatabaseSeeder.php"
Cohesion: 0.60
Nodes (3): DatabaseSeeder, Illuminate\Database\Console\Seeds\WithoutModelEvents, Illuminate\Database\Seeder

### Community 36 - "use-clipboard.ts"
Cohesion: 0.40
Nodes (3): CopiedValue, CopyFn, UseClipboardReturn

### Community 37 - "index.ts"
Cohesion: 0.11
Nodes (19): AppContent(), Props, AppShell(), Props, AppSidebarHeader(), Breadcrumbs(), SidebarInset(), SidebarTrigger() (+11 more)

### Community 38 - "require-dev"
Cohesion: 0.15
Nodes (13): require-dev, fakerphp/faker, larastan/larastan, laravel/boost, laravel/pail, laravel/pao, laravel/pint, laravel/sail (+5 more)

### Community 46 - "Detection Checklist"
Cohesion: 0.17
Nodes (11): A. Validation & HTTP input, B. Controllers & routing, C. Authorization, D. Eloquent & models, Detection Checklist, E. Architecture & organization, F. Frontend & views, G. Database & migrations (+3 more)

### Community 90 - "keywords"
Cohesion: 0.67
Nodes (3): keywords, framework, laravel

### Community 92 - "Process"
Cohesion: 0.17
Nodes (11): Edge cases, Glob mapping, Ground Rules (read before you start), Infer Conventions, Process, Step 0: Orient, Step 1: Predefined sweep, Step 2: Open-ended pass (+3 more)

### Community 93 - "Detection Checklist"
Cohesion: 0.17
Nodes (11): A. Validation & HTTP input, B. Controllers & routing, C. Authorization, D. Eloquent & models, Detection Checklist, E. Architecture & organization, F. Frontend & views, G. Database & migrations (+3 more)

### Community 94 - "Process"
Cohesion: 0.17
Nodes (11): Edge cases, Glob mapping, Ground Rules (read before you start), Infer Conventions, Process, Step 0: Orient, Step 1: Predefined sweep, Step 2: Open-ended pass (+3 more)

### Community 95 - "Architecture Best Practices"
Cohesion: 0.17
Nodes (11): Architecture Best Practices, Code to Interfaces, Convention Over Configuration, Default Sort by Descending, Single-Purpose Action Classes, Use Atomic Locks for Race Conditions, Use `Concurrency::run()` for Parallel Execution, Use `Context` for Request-Scoped Data (+3 more)

### Community 96 - "Queue & Job Best Practices"
Cohesion: 0.18
Nodes (10): Always Implement `failed()`, Batch Related Jobs, Implement `ShouldBeUnique`, Queue & Job Best Practices, Rate Limit External API Calls in Jobs, `retryUntil()` Needs `$tries = 0`, Set `retry_after` Greater Than `timeout`, Use Exponential Backoff (+2 more)

### Community 97 - "Security Best Practices"
Cohesion: 0.18
Nodes (11): Audit Dependencies, Authorize Every Action, CSRF Protection, Encrypt Sensitive Database Fields, Escape Output to Prevent XSS, Keep Secrets Out of Code, Mass Assignment Protection, Prevent SQL Injection (+3 more)

### Community 98 - "Architecture Best Practices"
Cohesion: 0.17
Nodes (11): Architecture Best Practices, Code to Interfaces, Convention Over Configuration, Default Sort by Descending, Single-Purpose Action Classes, Use Atomic Locks for Race Conditions, Use `Concurrency::run()` for Parallel Execution, Use `Context` for Request-Scoped Data (+3 more)

### Community 99 - "Queue & Job Best Practices"
Cohesion: 0.18
Nodes (10): Always Implement `failed()`, Batch Related Jobs, Implement `ShouldBeUnique`, Queue & Job Best Practices, Rate Limit External API Calls in Jobs, `retryUntil()` Needs `$tries = 0`, Set `retry_after` Greater Than `timeout`, Use Exponential Backoff (+2 more)

### Community 100 - "Security Best Practices"
Cohesion: 0.17
Nodes (11): Audit Dependencies, Authorize Every Action, CSRF Protection, Encrypt Sensitive Database Fields, Escape Output to Prevent XSS, Keep Secrets Out of Code, Mass Assignment Protection, Prevent SQL Injection (+3 more)

### Community 101 - "Build Progress — Telegram Challenges Platform"
Cohesion: 0.11
Nodes (18): Backlog — captured, not scheduled, Build Progress — Telegram Challenges Platform, Domain Task 1 — challenge core: enums, schema, models, factories ✅, Domain Task 2 — economy + infra schema: enums, migrations, models, factories ✅, Domain Task 3 — `CoinLedger` (done), Domain Task 4 — period materialisation (done), Domain Task 5 — entitlements (done), Log (+10 more)

### Community 102 - "Advanced Query Patterns"
Cohesion: 0.20
Nodes (9): Advanced Query Patterns, Create Dynamic Relationships via Subquery FK, Prefer `whereIn` + Subquery Over `whereHas`, Sometimes Two Simple Queries Beat One Complex Query, Use `addSelect()` Subqueries for Single Values from Has-Many, Use Compound Indexes Matching `orderBy` Column Order, Use Conditional Aggregates Instead of Multiple Count Queries, Use Correlated Subqueries for Has-Many Ordering (+1 more)

### Community 103 - "Database Performance Best Practices"
Cohesion: 0.20
Nodes (9): Add Database Indexes, Always Eager Load Relationships, Chunk Large Datasets, Database Performance Best Practices, No Queries in Blade Templates, Prevent Lazy Loading in Development, Select Only Needed Columns, Use `cursor()` for Memory-Efficient Iteration (+1 more)

### Community 104 - "Events & Notifications Best Practices"
Cohesion: 0.20
Nodes (9): Always Queue Notifications, Events & Notifications Best Practices, Implement `HasLocalePreference` on Notifiable Models, Rely on Event Discovery, Route Notification Channels to Dedicated Queues, Run `event:cache` in Production Deploy, Use `afterCommit()` on Notifications in Transactions, Use On-Demand Notifications for Non-User Recipients (+1 more)

### Community 105 - "Wayfinder Development"
Cohesion: 0.20
Nodes (9): Common Methods, Common Pitfalls, Documentation, Generate Routes, Import Patterns, Quick Reference, Verification, Wayfinder Development (+1 more)

### Community 106 - "Advanced Query Patterns"
Cohesion: 0.20
Nodes (9): Advanced Query Patterns, Create Dynamic Relationships via Subquery FK, Prefer `whereIn` + Subquery Over `whereHas`, Sometimes Two Simple Queries Beat One Complex Query, Use `addSelect()` Subqueries for Single Values from Has-Many, Use Compound Indexes Matching `orderBy` Column Order, Use Conditional Aggregates Instead of Multiple Count Queries, Use Correlated Subqueries for Has-Many Ordering (+1 more)

### Community 107 - "Database Performance Best Practices"
Cohesion: 0.20
Nodes (9): Add Database Indexes, Always Eager Load Relationships, Chunk Large Datasets, Database Performance Best Practices, No Queries in Blade Templates, Prevent Lazy Loading in Development, Select Only Needed Columns, Use `cursor()` for Memory-Efficient Iteration (+1 more)

### Community 108 - "Events & Notifications Best Practices"
Cohesion: 0.20
Nodes (9): Always Queue Notifications, Events & Notifications Best Practices, Implement `HasLocalePreference` on Notifiable Models, Rely on Event Discovery, Route Notification Channels to Dedicated Queues, Run `event:cache` in Production Deploy, Use `afterCommit()` on Notifications in Transactions, Use On-Demand Notifications for Non-User Recipients (+1 more)

### Community 109 - "Wayfinder Development"
Cohesion: 0.20
Nodes (9): Common Methods, Common Pitfalls, Documentation, Generate Routes, Import Patterns, Quick Reference, Verification, Wayfinder Development (+1 more)

### Community 110 - "require"
Cohesion: 0.20
Nodes (10): require, inertiajs/inertia-laravel, irazasyed/telegram-bot-sdk, laravel/chisel, laravel/fortify, laravel/framework, laravel/sanctum, laravel/tinker (+2 more)

### Community 111 - "command"
Cohesion: 0.20
Nodes (9): command, enabled, type, mcp, laravel-boost, $schema, artisan, boost:mcp (+1 more)

### Community 112 - "Caching Best Practices"
Cohesion: 0.22
Nodes (8): Caching Best Practices, Configure Failover Cache Stores in Production, Use `Cache::add()` for Atomic Conditional Writes, Use `Cache::flexible()` for Stale-While-Revalidate, Use `Cache::memo()` to Avoid Redundant Hits Within a Request, Use `Cache::remember()` Instead of Manual Get/Put, Use Cache Tags to Invalidate Related Groups, Use `once()` for Per-Request Memoization

### Community 113 - "Eloquent Best Practices"
Cohesion: 0.22
Nodes (8): Apply Global Scopes Sparingly, Avoid Hardcoded Table Names in Queries, Cast Date Columns Properly, Define Attribute Casts, Eloquent Best Practices, Use Correct Relationship Types, Use Local Scopes for Reusable Queries, Use `whereBelongsTo()` for Relationship Queries

### Community 114 - "Migration Best Practices"
Cohesion: 0.22
Nodes (8): Add Indexes in the Migration, Generate Migrations with Artisan, Keep Migrations Focused, Migration Best Practices, Mirror Defaults in Model `$attributes`, Never Modify Deployed Migrations, Use `constrained()` for Foreign Keys, Write Reversible `down()` Methods by Default

### Community 115 - "Caching Best Practices"
Cohesion: 0.22
Nodes (8): Caching Best Practices, Configure Failover Cache Stores in Production, Use `Cache::add()` for Atomic Conditional Writes, Use `Cache::flexible()` for Stale-While-Revalidate, Use `Cache::memo()` to Avoid Redundant Hits Within a Request, Use `Cache::remember()` Instead of Manual Get/Put, Use Cache Tags to Invalidate Related Groups, Use `once()` for Per-Request Memoization

### Community 116 - "Eloquent Best Practices"
Cohesion: 0.22
Nodes (8): Apply Global Scopes Sparingly, Avoid Hardcoded Table Names in Queries, Cast Date Columns Properly, Define Attribute Casts, Eloquent Best Practices, Use Correct Relationship Types, Use Local Scopes for Reusable Queries, Use `whereBelongsTo()` for Relationship Queries

### Community 117 - "Migration Best Practices"
Cohesion: 0.22
Nodes (8): Add Indexes in the Migration, Generate Migrations with Artisan, Keep Migrations Focused, Migration Best Practices, Mirror Defaults in Model `$attributes`, Never Modify Deployed Migrations, Use `constrained()` for Foreign Keys, Write Reversible `down()` Methods by Default

### Community 118 - "scripts"
Cohesion: 0.22
Nodes (9): scripts, build, build:ssr, dev, format, format:check, lint, lint:check (+1 more)

### Community 119 - "Project Goal — Autonomous Build Driver"
Cohesion: 0.22
Nodes (8): Baked-in decisions — do NOT stop to ask about these, Build order (strict), Guardrails, North Star (definition of done), Operating loop — repeat until done, Project Goal — Autonomous Build Driver, Running me across resets, When you MAY stop and ask (only these)

### Community 120 - "User"
Cohesion: 0.09
Nodes (7): User, Illuminate\Database\Eloquent\Relations\HasOne, Illuminate\Foundation\Auth\User, Illuminate\Notifications\Notifiable, AuthenticationTest, EmailVerificationTest, fund()

### Community 121 - ".agents/skills/laravel-best-practices/SKILL.md"
Cohesion: 0.14
Nodes (11): Implement `ShouldQueue` on the Mailable Class, Mail Best Practices, Separate Content Tests from Sending Tests, Use `afterCommit()` on Mailables Inside Transactions, Use `assertQueued()` Not `assertSent()` for Queued Mailables, Use Markdown Mailables for Transactional Emails, Consistency First, Decision Rules (+3 more)

### Community 122 - "Blade & Views Best Practices"
Cohesion: 0.25
Nodes (7): Blade & Views Best Practices, Prefer Blade Components Over `@include`, Use `$attributes->merge()` in Component Templates, Use `@aware` for Deeply Nested Component Props, Use Blade Fragments for Partial Re-Renders (htmx/Turbo), Use `@pushOnce` for Per-Component Scripts, Use View Composers for Shared View Data

### Community 123 - "Error Handling Best Practices"
Cohesion: 0.25
Nodes (7): Add Context to Exception Classes, Enable `dontReportDuplicates()`, Error Handling Best Practices, Exception Reporting and Rendering, Force JSON Error Rendering for API Routes, Throttle High-Volume Exceptions, Use `ShouldntReport` for Exceptions That Should Never Log

### Community 124 - "Task Scheduling Best Practices"
Cohesion: 0.25
Nodes (7): Task Scheduling Best Practices, Use `environments()` to Restrict Tasks, Use `onOneServer()` on Multi-Server Deployments, Use `runInBackground()` for Concurrent Long Tasks, Use Schedule Groups for Shared Configuration, Use `takeUntilTimeout()` for Time-Bounded Processing, Use `withoutOverlapping()` on Variable-Duration Tasks

### Community 125 - "Testing Best Practices"
Cohesion: 0.25
Nodes (7): Call `Event::fake()` After Factory Setup, Testing Best Practices, Use `Exceptions::fake()` to Assert Exception Reporting, Use Factory States and Sequences, Use `LazilyRefreshDatabase` Over `RefreshDatabase`, Use Model Assertions Over Raw Database Assertions, Use `recycle()` to Share Relationship Instances Across Factories

### Community 126 - "EntitlementFactory"
Cohesion: 0.38
Nodes (4): EntitlementFactory, EntitlementType, static, EntitlementSource

### Community 127 - "Blade & Views Best Practices"
Cohesion: 0.25
Nodes (7): Blade & Views Best Practices, Prefer Blade Components Over `@include`, Use `$attributes->merge()` in Component Templates, Use `@aware` for Deeply Nested Component Props, Use Blade Fragments for Partial Re-Renders (htmx/Turbo), Use `@pushOnce` for Per-Component Scripts, Use View Composers for Shared View Data

### Community 128 - "Error Handling Best Practices"
Cohesion: 0.25
Nodes (7): Add Context to Exception Classes, Enable `dontReportDuplicates()`, Error Handling Best Practices, Exception Reporting and Rendering, Force JSON Error Rendering for API Routes, Throttle High-Volume Exceptions, Use `ShouldntReport` for Exceptions That Should Never Log

### Community 129 - "Task Scheduling Best Practices"
Cohesion: 0.25
Nodes (7): Task Scheduling Best Practices, Use `environments()` to Restrict Tasks, Use `onOneServer()` on Multi-Server Deployments, Use `runInBackground()` for Concurrent Long Tasks, Use Schedule Groups for Shared Configuration, Use `takeUntilTimeout()` for Time-Bounded Processing, Use `withoutOverlapping()` on Variable-Duration Tasks

### Community 130 - "Testing Best Practices"
Cohesion: 0.25
Nodes (7): Call `Event::fake()` After Factory Setup, Testing Best Practices, Use `Exceptions::fake()` to Assert Exception Reporting, Use Factory States and Sequences, Use `LazilyRefreshDatabase` Over `RefreshDatabase`, Use Model Assertions Over Raw Database Assertions, Use `recycle()` to Share Relationship Instances Across Factories

### Community 131 - "Setup Task 1: Project Bootstrap"
Cohesion: 0.25
Nodes (7): Before starting, Code Rules, Explicitly Out of Scope, Goal, Setup Task 1: Project Bootstrap, Tests (Pest, required), What to Build (in order)

### Community 132 - "CheckIn"
Cohesion: 0.18
Nodes (4): SettleCheckIn, CheckIn, CheckInStatus, Closure

### Community 133 - "Collection Best Practices"
Cohesion: 0.29
Nodes (6): Choose `cursor()` vs. `lazy()` Correctly, Collection Best Practices, Use `#[CollectedBy]` for Custom Collection Classes, Use Higher-Order Messages for Simple Operations, Use `lazyById()` When Updating Records While Iterating, Use `toQuery()` for Bulk Operations on Collections

### Community 134 - "HTTP Client Best Practices"
Cohesion: 0.29
Nodes (6): Always Set Explicit Timeouts, Fake HTTP Calls in Tests, Handle Errors Explicitly, HTTP Client Best Practices, Use Request Pooling for Concurrent Requests, Use Retry with Backoff for External APIs

### Community 135 - "utils.ts"
Cohesion: 0.25
Nodes (10): AppHeader(), Separator(), IsCurrentOrParentUrlFn, IsCurrentUrlFn, useCurrentUrl(), UseCurrentUrlReturn, WhenCurrentUrlFn, SettingsLayout() (+2 more)

### Community 136 - "Routing & Controllers Best Practices"
Cohesion: 0.29
Nodes (6): Keep Controllers Thin, Routing & Controllers Best Practices, Type-Hint Form Requests, Use Implicit Route Model Binding, Use Resource Controllers, Use Scoped Bindings for Nested Resources

### Community 137 - "Conventions & Style"
Cohesion: 0.29
Nodes (6): Conventions & Style, Follow Laravel Naming Conventions, No Inline JS/CSS in Blade, No Unnecessary Comments, Prefer Shorter Readable Syntax, Use Laravel String & Array Helpers

### Community 138 - "Validation & Forms Best Practices"
Cohesion: 0.29
Nodes (6): Always Use `validated()`, Array vs. String Notation for Rules, Use Form Request Classes, Use `Rule::when()` for Conditional Validation, Use the `after()` Method for Custom Validation, Validation & Forms Best Practices

### Community 139 - "Collection Best Practices"
Cohesion: 0.29
Nodes (6): Choose `cursor()` vs. `lazy()` Correctly, Collection Best Practices, Use `#[CollectedBy]` for Custom Collection Classes, Use Higher-Order Messages for Simple Operations, Use `lazyById()` When Updating Records While Iterating, Use `toQuery()` for Bulk Operations on Collections

### Community 140 - "HTTP Client Best Practices"
Cohesion: 0.29
Nodes (6): Always Set Explicit Timeouts, Fake HTTP Calls in Tests, Handle Errors Explicitly, HTTP Client Best Practices, Use Request Pooling for Concurrent Requests, Use Retry with Backoff for External APIs

### Community 141 - "Mail Best Practices"
Cohesion: 0.29
Nodes (6): Implement `ShouldQueue` on the Mailable Class, Mail Best Practices, Separate Content Tests from Sending Tests, Use `afterCommit()` on Mailables Inside Transactions, Use `assertQueued()` Not `assertSent()` for Queued Mailables, Use Markdown Mailables for Transactional Emails

### Community 142 - "Routing & Controllers Best Practices"
Cohesion: 0.29
Nodes (6): Keep Controllers Thin, Routing & Controllers Best Practices, Type-Hint Form Requests, Use Implicit Route Model Binding, Use Resource Controllers, Use Scoped Bindings for Nested Resources

### Community 143 - "Conventions & Style"
Cohesion: 0.29
Nodes (6): Conventions & Style, Follow Laravel Naming Conventions, No Inline JS/CSS in Blade, No Unnecessary Comments, Prefer Shorter Readable Syntax, Use Laravel String & Array Helpers

### Community 144 - "Validation & Forms Best Practices"
Cohesion: 0.29
Nodes (6): Always Use `validated()`, Array vs. String Notation for Rules, Use Form Request Classes, Use `Rule::when()` for Conditional Validation, Use the `after()` Method for Custom Validation, Validation & Forms Best Practices

### Community 145 - "config"
Cohesion: 0.29
Nodes (7): pestphp/pest-plugin, php-http/discovery, config, allow-plugins, optimize-autoloader, preferred-install, sort-packages

### Community 146 - "Configuration Best Practices"
Cohesion: 0.33
Nodes (5): Configuration Best Practices, `env()` Only in Config Files, Use `App::environment()` for Environment Checks, Use Constants and Language Files, Use Encrypted Env or External Secrets

### Community 147 - ".claude/skills/laravel-best-practices/SKILL.md"
Cohesion: 0.17
Nodes (10): Configuration Best Practices, `env()` Only in Config Files, Use `App::environment()` for Environment Checks, Use Constants and Language Files, Use Encrypted Env or External Secrets, Consistency First, Decision Rules, How to Apply (+2 more)

### Community 148 - "psr-4"
Cohesion: 0.40
Nodes (5): autoload, psr-4, App\\, Database\\Factories\\, Database\\Seeders\\

### Community 149 - "laravel"
Cohesion: 0.40
Nodes (5): extra, laravel, post-create-project, dont-discover, installer

### Community 150 - "package.json"
Cohesion: 0.50
Nodes (3): private, $schema, type

### Community 156 - "Illuminate\Database\Eloquent\Model"
Cohesion: 0.19
Nodes (3): CoinTransaction, CoinLedger, Illuminate\Database\Eloquent\Model

### Community 176 - "ChallengeFactory"
Cohesion: 0.23
Nodes (4): ChallengeFactory, PeriodType, static, ProofType

### Community 182 - "breadcrumbs.tsx"
Cohesion: 0.39
Nodes (7): Breadcrumb(), BreadcrumbEllipsis(), BreadcrumbItem(), BreadcrumbLink(), BreadcrumbList(), BreadcrumbPage(), BreadcrumbSeparator()

### Community 183 - "CoinTransactionFactory"
Cohesion: 0.36
Nodes (3): CoinTransactionFactory, CoinTransactionReason, static

### Community 190 - "new-ideas-TODO.md"
Cohesion: 0.50
Nodes (3): Ai approving, creators or challanges channels or groups, timed challanges , stepped challanges

### Community 203 - "alert.tsx"
Cohesion: 0.48
Nodes (4): Alert(), AlertDescription(), AlertTitle(), alertVariants

### Community 204 - "toggle-group.tsx"
Cohesion: 0.43
Nodes (5): ToggleGroup(), ToggleGroupContext, ToggleGroupItem(), Toggle(), toggleVariants

### Community 207 - "useIsMobile"
Cohesion: 0.53
Nodes (5): SidebarProvider(), getServerSnapshot(), isSmallerThanBreakpoint(), mediaQueryListener(), useIsMobile()

## Knowledge Gaps
- **696 isolated node(s):** `vendor/bin/sail`, `$schema`, `style`, `rsc`, `tsx` (+691 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **54 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `User` connect `User` to `PasswordValidationRules.php`, `EntitlementType.php`, `Illuminate\Foundation\Testing\RefreshDatabase`, `ConversationState.php`, `Localization`, `SubmitCheckIn Action`, `Illuminate\Database\Eloquent\Builder`, `Illuminate\Database\Eloquent\Model`, `CoinTransactionReason.php`, `DatabaseSeeder.php`, `SecurityTest`, `ChallengeFactory`, `CheckInFactory`, `UserFactory`, `Illuminate\Database\Eloquent\Relations\HasMany`, `ChallengeParticipantFactory`, `InviteFactory`, `CoinTransactionFactory`, `StarPaymentFactory`, `PasswordResetTest`, `User.php`, `ProfileUpdateTest`, `EntitlementFactory`?**
  _High betweenness centrality (0.100) - this node is a cross-community bridge._
- **Why does `FortifyServiceProvider` connect `PasswordValidationRules.php` to `SubmitCheckIn Action`?**
  _High betweenness centrality (0.093) - this node is a cross-community bridge._
- **Why does `Admin Panel` connect `SubmitCheckIn Action` to `PasswordValidationRules.php`?**
  _High betweenness centrality (0.092) - this node is a cross-community bridge._
- **Are the 36 inferred relationships involving `User` (e.g. with `.definition()` and `.definition()`) actually correct?**
  _`User` has 36 INFERRED edges - model-reasoned connections that need verification._
- **What connects `vendor/bin/sail`, `$schema`, `style` to the rest of the system?**
  _696 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `delete-user.tsx` be split into smaller, more focused modules?**
  _Cohesion score 0.10202655485674354 - nodes in this community are weakly interconnected._
- **Should `PasswordValidationRules.php` be split into smaller, more focused modules?**
  _Cohesion score 0.05201266395296246 - nodes in this community are weakly interconnected._