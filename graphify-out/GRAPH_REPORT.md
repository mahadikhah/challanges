# Graph Report - challenges  (2026-08-28)

## Corpus Check
- 433 files · ~207,970 words
- Verdict: corpus is large enough that graph structure adds value.

## Summary
- 2824 nodes · 5490 edges · 246 communities (191 shown, 55 thin omitted)
- Extraction: 98% EXTRACTED · 2% INFERRED · 0% AMBIGUOUS · INFERRED: 127 edges (avg confidence: 0.79)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `7d53720f`
- Run `git rev-parse HEAD` and compare to check if the graph is stale.
- Run `graphify update .` after code changes (no API cost).

## Community Hubs (Navigation)
- delete-user.tsx
- composer.json
- ProfileController.php
- scripts
- JoinChallengeFlowTest.php
- Challenge
- devDependencies
- cn
- optionalDependencies
- sidebar.tsx
- EntitlementType.php
- CheckInStatus.php
- RollOverPeriod.php
- ChallengePeriod.php
- compilerOptions
- Illuminate\Database\Eloquent\Relations\HasMany
- components.json
- CreateChallengeWizard
- Localization
- AGENTS.md
- FortifyServiceProvider
- Inertia React Development
- dependencies
- Inertia React Development
- use-appearance.tsx
- ChallengeFactory
- Invite
- Laravel Fortify Development
- User.php
- Laravel Fortify Development
- CoinTransactionFactory
- Settings
- Tailwind CSS Development
- Tailwind CSS Development
- Phase 13 Task 1: Telescope — production-safe install
- placeholder-pattern.tsx
- use-clipboard.ts
- index.ts
- require-dev
- eslint.config.js
- icon.tsx
- Illuminate\Http\Request
- Detection Checklist
- app-sidebar.tsx
- globals
- @inertiajs/react
- @inertiajs/vite
- Illuminate\Foundation\Http\FormRequest
- app-header.tsx
- Controller
- @radix-ui/react-checkbox
- @radix-ui/react-collapsible
- ProofType.php
- @radix-ui/react-dropdown-menu
- ProcessTelegramUpdate.php
- @radix-ui/react-select
- BotConversation
- BotCallback
- @radix-ui/react-tooltip
- react
- BotMessenger
- CheckIn
- tailwind-merge
- Illuminate\Database\Eloquent\Builder
- BotCommand
- CheckInRejectedException
- @types/react
- @types/react-dom
- Phase 12 Task 2: Setup guides (shared cPanel & Ubuntu VPS)
- vite
- @vitejs/plugin-react
- Apple Touch Icon (Laravel logo)
- Phase 11 Task 1: Messenger platform abstraction (refactor, no new platform yet)
- Dependabot (github-actions)
- Main Prompt Addendum — Creator Chats, Timed Challenges, AI-Assisted Approval
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
- Mail Best Practices
- Blade & Views Best Practices
- Error Handling Best Practices
- Task Scheduling Best Practices
- Testing Best Practices
- InviteFactory
- Blade & Views Best Practices
- Error Handling Best Practices
- Task Scheduling Best Practices
- Testing Best Practices
- Setup Task 1: Project Bootstrap
- ChallengeDraft
- Collection Best Practices
- HTTP Client Best Practices
- FortifyServiceProvider.php
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
- .agents/skills/laravel-best-practices/SKILL.md
- .claude/skills/laravel-best-practices/SKILL.md
- psr-4
- laravel
- ChallengePeriod
- laravel-boost
- ProfileValidationRules.php
- StarPaymentFactory
- dropdown-menu.tsx
- CreateChallengeWizardTest.php
- Telegram\Bot\Api
- LaravelHttpClient
- Illuminate\Database\Eloquent\Relations\BelongsTo
- ChallengeParticipant
- Phase 9 Task 1: Timed/stepped challenge schema & design-time validation
- ConversationRouter
- Pest.php
- TelegramServiceProvider.php
- TelegramUpdate
- CoinLedger
- 2.10 Observability
- ci:check
- Project Goal — Phase 13 (Observability)
- Phase 10 Task 1: `approval_mode` + criteria generation & screening
- @radix-ui/react-separator
- new-ideas-TODO.md
- Phase 8 Task 1: Creator chat registration & verification
- Project Goal — Phases 11–12 (Bale Multi-Platform, Documentation)
- EntitlementFactory
- Project Goal — Phases 8–10 (Creator Chats, Timed Challenges, AI Approval)
- ChallengeResource
- ChallengeParticipantFactory
- @radix-ui/react-avatar
- Illuminate\Database\Eloquent\Factories\Factory
- UserFactory
- index.md
- setup
- class-variance-authority
- ReminderDispatch
- TelegramUpdateFactory
- Carbon\CarbonImmutable
- LocaleUpdateRequest
- Main Prompt Addendum 3 — Bale Multi-Platform & Documentation
- BotConversationFactory
- StartCommandTest.php
- post-create-project-cmd
- alert.tsx
- SettingFactory
- sonner
- tw-animate-css
- laravel-vite-plugin
- SetWebhookCommandTest.php
- package.json
- concurrently
- eslint-plugin-import
- DatabaseSeeder.php
- @radix-ui/react-slot
- eslint-plugin-react-hooks
- eslint-import-resolver-typescript
- eslint-plugin-react
- lucide-react
- @radix-ui/react-dialog
- @radix-ui/react-navigation-menu
- react-dom
- tailwindcss
- @tailwindcss/vite
- typescript
- prettier
- prettier-plugin-tailwindcss

## God Nodes (most connected - your core abstractions)
1. `User` - 254 edges
2. `cn()` - 125 edges
3. `Challenge` - 113 edges
4. `CheckIn` - 62 edges
5. `BotMessenger` - 60 edges
6. `TelegramUpdate` - 58 edges
7. `ChallengePeriod` - 53 edges
8. `Settings` - 52 edges
9. `ChallengeParticipant` - 47 edges
10. `Localization` - 45 edges

## Surprising Connections (you probably didn't know these)
- `sendsPhoto()` --calls--> `TelegramUpdate`  [INFERRED]
  tests/Feature/Bot/CheckInFlowTest.php → app/Models/TelegramUpdate.php
- `taps()` --calls--> `TelegramUpdate`  [INFERRED]
  tests/Feature/Bot/CheckInFlowTest.php → app/Models/TelegramUpdate.php
- `typesIn()` --calls--> `TelegramUpdate`  [INFERRED]
  tests/Feature/Bot/CheckInFlowTest.php → app/Models/TelegramUpdate.php
- `arrivesViaJoinLink()` --calls--> `TelegramUpdate`  [INFERRED]
  tests/Feature/Bot/JoinChallengeFlowTest.php → app/Models/TelegramUpdate.php
- `User model Telegram extension` --references--> `User`  [EXTRACTED]
  CLAUDE.md → app/Models/User.php

## Import Cycles
- None detected.

## Hyperedges (group relationships)
- **Four surfaces, one Laravel app** — prompts_main_bot_surface, prompts_main_miniapp_surface, prompts_main_admin_surface, prompts_main_website_surface [EXTRACTED 1.00]
- **Idempotency keys across the system** — claude_md_telegram_update_model, claude_md_star_payment_model, claude_md_checkin_model, claude_md_reminder_dispatch_model [EXTRACTED 1.00]
- **Three proof types** — prompts_main_proof_button, prompts_main_proof_text_autogen, prompts_main_proof_image_approval [EXTRACTED 1.00]

## Communities (246 total, 55 thin omitted)

### Community 0 - "delete-user.tsx"
Cohesion: 0.10
Nodes (26): DeleteUser(), Heading(), InputError(), PasswordInput(), Props, TextLink(), Button(), buttonVariants (+18 more)

### Community 1 - "composer.json"
Cohesion: 0.14
Nodes (13): autoload-dev, psr-4, description, keywords, license, minimum-stability, name, prefer-stable (+5 more)

### Community 2 - "ProfileController.php"
Cohesion: 0.18
Nodes (7): ProfileController, SecurityController, ProfileDeleteRequest, TwoFactorAuthenticationRequest, Illuminate\Http\RedirectResponse, Inertia\Response, Laravel\Fortify\InteractsWithTwoFactorState

### Community 3 - "scripts"
Cohesion: 0.11
Nodes (19): scripts, lint, lint:check, post-autoload-dump, post-update-cmd, pre-package-uninstall, test, types:check (+11 more)

### Community 4 - "JoinChallengeFlowTest.php"
Cohesion: 0.15
Nodes (5): arrivesViaJoinLink(), joinableChallenge(), theJoiner(), aMiniAppChallengeInView(), aMiniAppUser()

### Community 5 - "Challenge"
Cohesion: 0.10
Nodes (14): ChallengeNotJoinableException, self, Challenge, self, JoinRejection, aRunningChallenge(), owingParticipant(), aParticipantIn() (+6 more)

### Community 6 - "devDependencies"
Cohesion: 0.12
Nodes (17): babel-plugin-react-compiler, eslint-config-prettier, @eslint/js, @laravel/vite-plugin-wayfinder, devDependencies, babel-plugin-react-compiler, eslint, eslint-config-prettier (+9 more)

### Community 7 - "cn"
Cohesion: 0.09
Nodes (31): Badge(), badgeVariants, Card(), CardContent(), CardDescription(), CardFooter(), CardHeader(), CardTitle() (+23 more)

### Community 8 - "optionalDependencies"
Cohesion: 0.13
Nodes (15): @laravel/multiplex, lightningcss-linux-x64-gnu, lightningcss-win32-x64-msvc, optionalDependencies, @laravel/multiplex, lightningcss-linux-x64-gnu, lightningcss-win32-x64-msvc, @rollup/rollup-linux-x64-gnu (+7 more)

### Community 9 - "sidebar.tsx"
Cohesion: 0.11
Nodes (25): NavUser(), SheetDescription(), Sidebar(), SidebarContext, SidebarGroupAction(), SidebarInput(), SidebarMenuAction(), SidebarMenuBadge() (+17 more)

### Community 10 - "EntitlementType.php"
Cohesion: 0.07
Nodes (21): JoinChallenge, ConsumeEntitlement, GrantFreeBaseline, EntitlementType, PurchaseEntitlement, freeAllowanceSetting(), CoinTransactionReason, SettingKey (+13 more)

### Community 11 - "CheckInStatus.php"
Cohesion: 0.11
Nodes (7): awaitingVerdict(), Closure, verdictRefusal(), enrol(), Closure, provenBy(), refusalFor()

### Community 12 - "RollOverPeriod.php"
Cohesion: 0.11
Nodes (9): RollOverPeriod, CarbonImmutable, ReminderKind, ScheduleChallengeReminders, RollOverDuePeriodsCommand, SendRemindersCommand, WebhookInfoCommand, Illuminate\Console\Command (+1 more)

### Community 13 - "ChallengePeriod.php"
Cohesion: 0.15
Nodes (6): MaterialiseChallengePeriods, CarbonImmutable, PeriodType, MintJoinToken, Illuminate\Database\Eloquent\Collection, challengeOn()

### Community 14 - "compilerOptions"
Cohesion: 0.10
Nodes (19): resources/js/**/*.d.ts, resources/js/**/*.ts, resources/js/**/*.tsx, compilerOptions, allowJs, baseUrl, esModuleInterop, forceConsistentCasingInFileNames (+11 more)

### Community 16 - "components.json"
Cohesion: 0.11
Nodes (17): aliases, components, hooks, lib, ui, utils, iconLibrary, rsc (+9 more)

### Community 17 - "CreateChallengeWizard"
Cohesion: 0.22
Nodes (3): ChallengeVisibility, CreateChallengeWizard, ConversationState

### Community 19 - "AGENTS.md"
Cohesion: 0.06
Nodes (33): APIs & Eloquent Resources, Application Structure & Architecture, Artisan, Conventions, Deployment, Do Things the Laravel Way, Documentation Files, Foundational Context (+25 more)

### Community 20 - "FortifyServiceProvider"
Cohesion: 0.06
Nodes (39): FortifyServiceProvider, BotConversation (model), Challenge (model), ChallengeParticipant (model), ChallengePeriod (model), CheckIn (model), CoinTransaction (ledger), Entitlement (model) (+31 more)

### Community 21 - "Inertia React Development"
Cohesion: 0.07
Nodes (27): Basic Link Component, Basic Usage, Client-Side Navigation, Common Pitfalls, Deferred Props, Documentation, Form Component (Recommended), Form Component Reset Props (+19 more)

### Community 22 - "dependencies"
Cohesion: 0.22
Nodes (9): clsx, dependencies, clsx, @radix-ui/react-label, @radix-ui/react-toggle, @radix-ui/react-toggle-group, @radix-ui/react-label, @radix-ui/react-toggle (+1 more)

### Community 23 - "Inertia React Development"
Cohesion: 0.07
Nodes (27): Basic Link Component, Basic Usage, Client-Side Navigation, Common Pitfalls, Deferred Props, Documentation, Form Component (Recommended), Form Component Reset Props (+19 more)

### Community 24 - "use-appearance.tsx"
Cohesion: 0.07
Nodes (38): i18n + RTL Requirement, AppearanceToggleTab(), LanguageSwitcher(), Toaster(), Appearance, applyTheme(), getStoredAppearance(), handleSystemThemeChange() (+30 more)

### Community 25 - "ChallengeFactory"
Cohesion: 0.22
Nodes (4): ChallengeFactory, PeriodType, ProofType, static

### Community 26 - "Invite"
Cohesion: 0.08
Nodes (7): ClaimInvite, IssueInviteCode, InviteNotClaimableException, InviteRejection, self, Invite, returning()

### Community 27 - "Laravel Fortify Development"
Cohesion: 0.12
Nodes (16): Available Features, Best Practices, Custom Authentication Logic, Documentation, Email Verification Setup, Key Endpoints, Laravel Fortify Development, Passkeys Setup (+8 more)

### Community 28 - "User.php"
Cohesion: 0.09
Nodes (12): isCredit(), isDebit(), sign(), Setting, Illuminate\Contracts\Translation\HasLocalePreference, Illuminate\Database\Eloquent\Factories\HasFactory, Illuminate\Database\Eloquent\Relations\MorphTo, Illuminate\Notifications\Notifiable (+4 more)

### Community 29 - "Laravel Fortify Development"
Cohesion: 0.12
Nodes (16): Available Features, Best Practices, Custom Authentication Logic, Documentation, Email Verification Setup, Key Endpoints, Laravel Fortify Development, Passkeys Setup (+8 more)

### Community 30 - "CoinTransactionFactory"
Cohesion: 0.36
Nodes (3): CoinTransactionFactory, CoinTransactionReason, static

### Community 31 - "Settings"
Cohesion: 0.05
Nodes (21): AuthenticateMiniAppUser, fromTelegram(), self, SettingType, type(), ChannelGateException, self, InvalidInitDataException (+13 more)

### Community 32 - "Tailwind CSS Development"
Cohesion: 0.14
Nodes (13): Basic Usage, Common Patterns, Common Pitfalls, CSS-First Configuration, Dark Mode, Documentation, Flexbox Layout, Grid Layout (+5 more)

### Community 33 - "Tailwind CSS Development"
Cohesion: 0.14
Nodes (13): Basic Usage, Common Patterns, Common Pitfalls, CSS-First Configuration, Dark Mode, Documentation, Flexbox Layout, Grid Layout (+5 more)

### Community 34 - "Phase 13 Task 1: Telescope — production-safe install"
Cohesion: 0.06
Nodes (35): Before starting, Before starting, Before starting, Before starting, Before starting, Code Rules, Code Rules, Code Rules (+27 more)

### Community 36 - "use-clipboard.ts"
Cohesion: 0.40
Nodes (3): CopiedValue, CopyFn, UseClipboardReturn

### Community 37 - "index.ts"
Cohesion: 0.13
Nodes (19): AppContent(), Props, AppShell(), Props, AppSidebarHeader(), Breadcrumbs(), Breadcrumb(), BreadcrumbEllipsis() (+11 more)

### Community 38 - "require-dev"
Cohesion: 0.15
Nodes (13): require-dev, fakerphp/faker, larastan/larastan, laravel/boost, laravel/pail, laravel/pao, laravel/pint, laravel/sail (+5 more)

### Community 45 - "Illuminate\Http\Request"
Cohesion: 0.16
Nodes (10): Exceptions, Never name an exception property $code or $message, HandleAppearance, HandleInertiaRequests, SetLocale, Closure, Illuminate\Foundation\Configuration\Middleware, Illuminate\Http\Request (+2 more)

### Community 46 - "Detection Checklist"
Cohesion: 0.17
Nodes (11): A. Validation & HTTP input, B. Controllers & routing, C. Authorization, D. Eloquent & models, Detection Checklist, E. Architecture & organization, F. Frontend & views, G. Database & migrations (+3 more)

### Community 47 - "app-sidebar.tsx"
Cohesion: 0.08
Nodes (26): AppLogo(), AppLogoIcon(), footerNavItems, mainNavItems, NavFooter(), NavMain(), SidebarContent(), SidebarFooter() (+18 more)

### Community 51 - "Illuminate\Foundation\Http\FormRequest"
Cohesion: 0.16
Nodes (4): AuthenticateRequest, PasswordUpdateRequest, WebhookRequest, Illuminate\Foundation\Http\FormRequest

### Community 52 - "app-header.tsx"
Cohesion: 0.12
Nodes (21): AppHeader(), mainNavItems, Props, rightNavItems, Avatar(), AvatarFallback(), AvatarImage(), NavigationMenuTrigger() (+13 more)

### Community 53 - "Controller"
Cohesion: 0.16
Nodes (6): Controller, ChallengeController, MeController, WebhookController, Illuminate\Http\JsonResponse, Illuminate\Http\Resources\Json\AnonymousResourceCollection

### Community 56 - "ProofType.php"
Cohesion: 0.10
Nodes (9): label(), options(), translationKey(), isAutoApproved(), requiresReview(), HasTranslatedLabel, announceable(), channelPostButtons() (+1 more)

### Community 58 - "ProcessTelegramUpdate.php"
Cohesion: 0.12
Nodes (8): IngestTelegramUpdate, AnnounceChallenge, ProcessTelegramUpdate, ChannelBroadcaster, Illuminate\Contracts\Queue\ShouldQueue, Illuminate\Foundation\Queue\Queueable, handle(), Throwable

### Community 60 - "BotConversation"
Cohesion: 0.19
Nodes (4): BotConversation, ConversationState, CheckInFlow, ConversationState

### Community 61 - "BotCallback"
Cohesion: 0.08
Nodes (11): BotCallback, self, CheckInCallback, JoinCallback, LanguageCallback, WizardCallback, handle(), FailingCallbackHandler (+3 more)

### Community 64 - "BotMessenger"
Cohesion: 0.12
Nodes (11): ReviewCheckIn, VerifyChannelMembership, BotMessenger, ReviewCheckInCallback, ShopCallback, ChannelGatePrompt, CancelCommand, ShopCommand (+3 more)

### Community 65 - "CheckIn"
Cohesion: 0.13
Nodes (7): IssueCheckInPhrase, OpenCheckIn, SettleCheckIn, self, PhraseUnavailableException, CheckIn, CheckInStatus

### Community 67 - "Illuminate\Database\Eloquent\Builder"
Cohesion: 0.08
Nodes (3): StarsInvoice, StarPayment, Illuminate\Database\Eloquent\Builder

### Community 68 - "BotCommand"
Cohesion: 0.14
Nodes (5): BotCommand, self, CheckInCommand, CreateCommand, handle()

### Community 69 - "CheckInRejectedException"
Cohesion: 0.17
Nodes (6): CarbonImmutable, ProofType, SubmitCheckIn, CheckInRejectedException, CheckInRejection, self

### Community 72 - "Phase 12 Task 2: Setup guides (shared cPanel & Ubuntu VPS)"
Cohesion: 0.09
Nodes (22): Before starting, Before starting, Before starting, Both guides, Code Rules, Code Rules, Code Rules, Explicitly Out of Scope (+14 more)

### Community 83 - "Phase 11 Task 1: Messenger platform abstraction (refactor, no new platform yet)"
Cohesion: 0.09
Nodes (21): Before starting, Before starting, Before starting, Code Rules, Code Rules, Code Rules, Explicitly Out of Scope, Explicitly Out of Scope (+13 more)

### Community 90 - "Main Prompt Addendum — Creator Chats, Timed Challenges, AI-Assisted Approval"
Cohesion: 0.12
Nodes (16): 2.6 Creator-owned chats (channels/groups), 2.7 Timed & stepped challenges, 2.8 AI-assisted proof approval, 3.5–3.7 Architecture notes, 5. Roadmap — Phases 8–10, 7. New open product questions, Actions, Criteria text: the part that needs a real security boundary (+8 more)

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
Cohesion: 0.17
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
Cohesion: 0.06
Nodes (33): Backlog — captured, not scheduled, Bot Core Task 1 — webhook intake (done) — commit `807989c`, Bot Core Task 2 — outbound transport, webhook commands, update router (done) — commit `14fbdfb`, Bot Core Task 3 — channel gate + `/start` with invite attribution (done), Bot Core Task 4 — create-challenge wizard (done), Bot Core Task 5 — the join flow (done), Bot Core Task 6 — check-in for all three proof types (done), Bot Core Task 7 — reminders + locale selection (done) (+25 more)

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
Cohesion: 0.04
Nodes (18): User, Illuminate\Database\Eloquent\Relations\HasOne, Illuminate\Foundation\Auth\User, Illuminate\Foundation\Testing\RefreshDatabase, Illuminate\Foundation\Testing\TestCase, AuthenticationTest, EmailVerificationTest, PasswordConfirmationTest (+10 more)

### Community 121 - "Mail Best Practices"
Cohesion: 0.29
Nodes (6): Implement `ShouldQueue` on the Mailable Class, Mail Best Practices, Separate Content Tests from Sending Tests, Use `afterCommit()` on Mailables Inside Transactions, Use `assertQueued()` Not `assertSent()` for Queued Mailables, Use Markdown Mailables for Transactional Emails

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

### Community 132 - "ChallengeDraft"
Cohesion: 0.21
Nodes (5): ChallengeDraft, CarbonImmutable, PeriodType, ProofType, self

### Community 133 - "Collection Best Practices"
Cohesion: 0.29
Nodes (6): Choose `cursor()` vs. `lazy()` Correctly, Collection Best Practices, Use `#[CollectedBy]` for Custom Collection Classes, Use Higher-Order Messages for Simple Operations, Use `lazyById()` When Updating Records While Iterating, Use `toQuery()` for Bulk Operations on Collections

### Community 134 - "HTTP Client Best Practices"
Cohesion: 0.29
Nodes (6): Always Set Explicit Timeouts, Fake HTTP Calls in Tests, Handle Errors Explicitly, HTTP Client Best Practices, Use Request Pooling for Concurrent Requests, Use Retry with Backoff for External APIs

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

### Community 146 - ".agents/skills/laravel-best-practices/SKILL.md"
Cohesion: 0.17
Nodes (10): Configuration Best Practices, `env()` Only in Config Files, Use `App::environment()` for Environment Checks, Use Constants and Language Files, Use Encrypted Env or External Secrets, Consistency First, Decision Rules, How to Apply (+2 more)

### Community 147 - ".claude/skills/laravel-best-practices/SKILL.md"
Cohesion: 0.17
Nodes (10): Configuration Best Practices, `env()` Only in Config Files, Use `App::environment()` for Environment Checks, Use Constants and Language Files, Use Encrypted Env or External Secrets, Consistency First, Decision Rules, How to Apply (+2 more)

### Community 148 - "psr-4"
Cohesion: 0.40
Nodes (5): autoload, psr-4, App\\, Database\\Factories\\, Database\\Seeders\\

### Community 149 - "laravel"
Cohesion: 0.33
Nodes (6): extra, laravel, post-create-project, dont-discover, installer, irazasyed/telegram-bot-sdk

### Community 150 - "ChallengePeriod"
Cohesion: 0.12
Nodes (10): InsufficientCoinsException, PeriodNotEndedException, ChallengePeriod, Carbon\CarbonInterface, RuntimeException, livePeriod(), phraseChallenge(), endedPeriod() (+2 more)

### Community 155 - "ProfileValidationRules.php"
Cohesion: 0.27
Nodes (6): CreateNewUser, emailRules(), nameRules(), profileRules(), ProfileUpdateRequest, Laravel\Fortify\Contracts\CreatesNewUsers

### Community 157 - "dropdown-menu.tsx"
Cohesion: 0.12
Nodes (17): DropdownMenu(), DropdownMenuCheckboxItem(), DropdownMenuContent(), DropdownMenuGroup(), DropdownMenuItem(), DropdownMenuLabel(), DropdownMenuRadioItem(), DropdownMenuSeparator() (+9 more)

### Community 158 - "CreateChallengeWizardTest.php"
Cohesion: 0.14
Nodes (15): CreateChallenge, expectsCallback(), expectsPhoto(), expectsText(), isCheckInStep(), isCreateChallengeStep(), static, flowAnswers() (+7 more)

### Community 159 - "Telegram\Bot\Api"
Cohesion: 0.12
Nodes (8): CreateStarsInvoice, RefundStarsPayment, SetWebhookCommand, TelegramFileDownloader, Telegram\Bot\Api, botApi(), Api, DependentUpdateHandler

### Community 166 - "LaravelHttpClient"
Cohesion: 0.23
Nodes (6): LaravelHttpClient, static, GuzzleHttp\Promise\PromiseInterface, Illuminate\Http\Client\PendingRequest, Psr\Http\Message\ResponseInterface, Telegram\Bot\HttpClients\HttpClientInterface

### Community 173 - "ChallengeParticipant"
Cohesion: 0.16
Nodes (5): ChallengeParticipant, CheckInFactory, static, ReminderKind, reader()

### Community 174 - "Phase 9 Task 1: Timed/stepped challenge schema & design-time validation"
Cohesion: 0.09
Nodes (21): Before starting, Before starting, Before starting, Code Rules, Code Rules, Code Rules, Explicitly Out of Scope, Explicitly Out of Scope (+13 more)

### Community 176 - "Pest.php"
Cohesion: 0.31
Nodes (8): lastOfferedValues(), botKeyboard(), botMessages(), keyboardOn(), lastBotKeyboard(), lastBotReply(), latestBotMessage(), soleBotMessage()

### Community 177 - "TelegramServiceProvider.php"
Cohesion: 0.12
Nodes (7): AppServiceProvider, TelegramServiceProvider, CallbackRouter, CommandRouter, UpdateRouter, Illuminate\Contracts\Container\Container, Illuminate\Support\ServiceProvider

### Community 178 - "TelegramUpdate"
Cohesion: 0.06
Nodes (18): ResolveTelegramUser, TelegramUpdate, CallbackQueryHandler, MessageHandler, PreCheckoutQueryHandler, handle(), wizardSendsPhoto(), wizardTypes() (+10 more)

### Community 179 - "CoinLedger"
Cohesion: 0.17
Nodes (4): CompleteStarsPayment, CoinTransaction, CoinLedger, Illuminate\Database\Eloquent\Model

### Community 180 - "2.10 Observability"
Cohesion: 0.20
Nodes (9): 2.10 Observability, 3.9 Architecture note, 5. Roadmap — Phase 13, 7. New open product questions, Access control, Main Prompt Addendum 4 — Observability, Redaction, Telescope in production, specifically (+1 more)

### Community 181 - "ci:check"
Cohesion: 0.22
Nodes (9): ci:check, dev, CI Tests Workflow, Composer\\Config::disableProcessTimeout, npm run format:check, npm run lint:check, npm run types:check, @php artisan dev (+1 more)

### Community 182 - "Project Goal — Phase 13 (Observability)"
Cohesion: 0.22
Nodes (8): Baked-in decisions — do NOT stop to ask about these, Before the first run, Build order (strict), Guardrails, North Star (definition of done), Operating loop, Project Goal — Phase 13 (Observability), When you MAY stop and ask (only these)

### Community 183 - "Phase 10 Task 1: `approval_mode` + criteria generation & screening"
Cohesion: 0.13
Nodes (14): Before starting, Before starting, Code Rules, Code Rules, Explicitly Out of Scope, Explicitly Out of Scope, Goal, Goal (+6 more)

### Community 190 - "new-ideas-TODO.md"
Cohesion: 0.50
Nodes (3): Ai approving, creators or challanges channels or groups, timed challanges , stepped challanges

### Community 193 - "Phase 8 Task 1: Creator chat registration & verification"
Cohesion: 0.13
Nodes (14): Before starting, Before starting, Code Rules, Code Rules, Explicitly Out of Scope, Explicitly Out of Scope, Goal, Goal (+6 more)

### Community 194 - "Project Goal — Phases 11–12 (Bale Multi-Platform, Documentation)"
Cohesion: 0.22
Nodes (8): Baked-in decisions — do NOT stop to ask about these, Before the first run, Build order (strict), Guardrails, North Star (definition of done), Operating loop, Project Goal — Phases 11–12 (Bale Multi-Platform, Documentation), When you MAY stop and ask (only these)

### Community 195 - "EntitlementFactory"
Cohesion: 0.38
Nodes (4): EntitlementFactory, EntitlementType, static, EntitlementSource

### Community 203 - "Project Goal — Phases 8–10 (Creator Chats, Timed Challenges, AI Approval)"
Cohesion: 0.22
Nodes (8): Baked-in decisions — do NOT stop to ask about these, Before the first run, Build order (strict), Guardrails, North Star (definition of done), Operating loop, Project Goal — Phases 8–10 (Creator Chats, Timed Challenges, AI Approval), When you MAY stop and ask (only these)

### Community 204 - "ChallengeResource"
Cohesion: 0.31
Nodes (3): ChallengeResource, BackedEnum, Illuminate\Http\Resources\Json\JsonResource

### Community 207 - "Illuminate\Database\Eloquent\Factories\Factory"
Cohesion: 0.31
Nodes (3): ChallengePeriodFactory, static, Illuminate\Database\Eloquent\Factories\Factory

### Community 211 - "setup"
Cohesion: 0.25
Nodes (8): post-root-package-install, setup, composer install, npm install, npm run build, @php artisan key:generate, @php artisan migrate --force, @php -r \"file_exists('.env') || copy('.env.example', '.env');\

### Community 215 - "ReminderDispatch"
Cohesion: 0.10
Nodes (11): DispatchDueReminders, ReminderKind, SendReminder, ReminderDispatch, static, ReminderDispatchFactory, Illuminate\Bus\Queueable, Illuminate\Foundation\Bus\Dispatchable (+3 more)

### Community 217 - "Carbon\CarbonImmutable"
Cohesion: 0.36
Nodes (3): MiniAppSession, CarbonImmutable, Carbon\CarbonImmutable

### Community 219 - "Main Prompt Addendum 3 — Bale Multi-Platform & Documentation"
Cohesion: 0.33
Nodes (5): 2.9 Bale — second messenger platform, 3.8 Architecture — the messenger abstraction, 5. Roadmap — Phases 11–12, 7. New open product questions, Main Prompt Addendum 3 — Bale Multi-Platform & Documentation

### Community 220 - "BotConversationFactory"
Cohesion: 0.36
Nodes (3): BotConversationFactory, ConversationState, static

### Community 222 - "post-create-project-cmd"
Cohesion: 0.50
Nodes (4): post-create-project-cmd, @php artisan key:generate --ansi, @php artisan migrate --graceful --ansi, @php -r \"file_exists('database/database.sqlite') || touch('database/database.sqlite');\

### Community 223 - "alert.tsx"
Cohesion: 0.48
Nodes (4): Alert(), AlertDescription(), AlertTitle(), alertVariants

### Community 224 - "SettingFactory"
Cohesion: 0.40
Nodes (3): SettingKey, static, SettingFactory

### Community 229 - "package.json"
Cohesion: 0.50
Nodes (3): private, $schema, type

### Community 232 - "DatabaseSeeder.php"
Cohesion: 0.60
Nodes (3): DatabaseSeeder, Illuminate\Database\Console\Seeds\WithoutModelEvents, Illuminate\Database\Seeder

## Knowledge Gaps
- **864 isolated node(s):** `vendor/bin/sail`, `$schema`, `style`, `rsc`, `tsx` (+859 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **55 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `User` connect `User` to `JoinChallengeFlowTest.php`, `Challenge`, `FortifyServiceProvider.php`, `EntitlementType.php`, `CheckInStatus.php`, `Illuminate\Database\Eloquent\Relations\HasMany`, `CreateChallengeWizard`, `Localization`, `FortifyServiceProvider`, `ChallengeFactory`, `Invite`, `ProfileValidationRules.php`, `User.php`, `StarPaymentFactory`, `CreateChallengeWizardTest.php`, `Telegram\Bot\Api`, `Settings`, `CoinTransactionFactory`, `Illuminate\Database\Eloquent\Relations\BelongsTo`, `ChallengeParticipant`, `ConversationRouter`, `TelegramServiceProvider.php`, `TelegramUpdate`, `CoinLedger`, `ProofType.php`, `BotConversation`, `BotCallback`, `BotMessenger`, `Illuminate\Database\Eloquent\Builder`, `BotCommand`, `CheckInRejectedException`, `EntitlementFactory`, `ChallengeParticipantFactory`, `Illuminate\Database\Eloquent\Factories\Factory`, `UserFactory`, `ReminderDispatch`, `Carbon\CarbonImmutable`, `BotConversationFactory`, `DatabaseSeeder.php`, `InviteFactory`?**
  _High betweenness centrality (0.125) - this node is a cross-community bridge._
- **Why does `FortifyServiceProvider` connect `FortifyServiceProvider` to `TelegramServiceProvider.php`, `FortifyServiceProvider.php`?**
  _High betweenness centrality (0.114) - this node is a cross-community bridge._
- **Are the 39 inferred relationships involving `User` (e.g. with `.definition()` and `.definition()`) actually correct?**
  _`User` has 39 INFERRED edges - model-reasoned connections that need verification._
- **Are the 11 inferred relationships involving `Challenge` (e.g. with `.handle()` and `.activateStartedChallenges()`) actually correct?**
  _`Challenge` has 11 INFERRED edges - model-reasoned connections that need verification._
- **What connects `vendor/bin/sail`, `$schema`, `style` to the rest of the system?**
  _864 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `delete-user.tsx` be split into smaller, more focused modules?**
  _Cohesion score 0.103424178895877 - nodes in this community are weakly interconnected._
- **Should `composer.json` be split into smaller, more focused modules?**
  _Cohesion score 0.14285714285714285 - nodes in this community are weakly interconnected._