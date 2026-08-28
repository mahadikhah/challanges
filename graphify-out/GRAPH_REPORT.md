# Graph Report - challenges  (2026-08-28)

## Corpus Check
- 411 files · ~195,965 words
- Verdict: corpus is large enough that graph structure adds value.

## Summary
- 2716 nodes · 5169 edges · 231 communities (192 shown, 39 thin omitted)
- Extraction: 98% EXTRACTED · 2% INFERRED · 0% AMBIGUOUS · INFERRED: 115 edges (avg confidence: 0.79)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `4b019fd7`
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
- app-sidebar.tsx
- EntitlementType.php
- Illuminate\Foundation\Testing\RefreshDatabase
- Carbon\CarbonImmutable
- utils.ts
- compilerOptions
- ChallengePeriod
- components.json
- CreateChallengeWizard
- Localization
- AGENTS.md
- SubmitCheckIn Action
- Inertia React Development
- dependencies
- Inertia React Development
- use-appearance.tsx
- ChallengeFactory
- Invite
- Laravel Fortify Development
- Illuminate\Database\Eloquent\Builder
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
- ResolveTelegramUser
- Detection Checklist
- app.tsx
- globals
- @inertiajs/react
- @inertiajs/vite
- ChallengeParticipantFactory
- app-header.tsx
- UserFactory
- @radix-ui/react-checkbox
- @radix-ui/react-collapsible
- ProofType.php
- @radix-ui/react-dropdown-menu
- TelegramUpdate
- @radix-ui/react-select
- BotConversation
- BotCallback
- @radix-ui/react-tooltip
- react
- BotMessenger
- CheckIn
- tailwind-merge
- CheckInStatus.php
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
- EntitlementFactory
- Blade & Views Best Practices
- Error Handling Best Practices
- Task Scheduling Best Practices
- Testing Best Practices
- Setup Task 1: Project Bootstrap
- ChallengeDraft
- Collection Best Practices
- HTTP Client Best Practices
- User.php
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
- SettleCheckIn
- laravel-boost
- Entitlement
- StarPaymentFactory
- PasswordValidationRules.php
- ChallengeParticipant
- Controller
- LaravelHttpClient
- InvitesTest.php
- ProfileValidationRules.php
- Phase 9 Task 1: Timed/stepped challenge schema & design-time validation
- Illuminate\Database\Eloquent\Factories\Factory
- Pest.php
- FortifyServiceProvider
- CallbackRouter
- Illuminate\Database\Eloquent\Model
- 2.10 Observability
- ci:check
- Project Goal — Phase 13 (Observability)
- Phase 10 Task 1: `approval_mode` + criteria generation & screening
- @radix-ui/react-separator
- new-ideas-TODO.md
- Phase 8 Task 1: Creator chat registration & verification
- Project Goal — Phases 11–12 (Bale Multi-Platform, Documentation)
- ConversationRouter
- Project Goal — Phases 8–10 (Creator Chats, Timed Challenges, AI Approval)
- HandlesUpdate.php
- CreateChallengeWizardTest.php
- @radix-ui/react-avatar
- ConversationState.php
- Illuminate\Foundation\Http\FormRequest
- index.md
- setup
- class-variance-authority
- ReminderDispatch
- TelegramUpdateFactory
- CheckInFlowTest.php
- alert.tsx
- Main Prompt Addendum 3 — Bale Multi-Platform & Documentation
- BotConversationFactory
- CallbackQueryHandlerTest.php
- post-create-project-cmd
- SettingFactory
- ChatMemberStatus.php
- sonner
- tw-animate-css
- laravel-vite-plugin
- @radix-ui/react-toggle
- @radix-ui/react-toggle-group
- @radix-ui/react-slot

## God Nodes (most connected - your core abstractions)
1. `User` - 234 edges
2. `cn()` - 125 edges
3. `Challenge` - 112 edges
4. `CheckIn` - 60 edges
5. `TelegramUpdate` - 51 edges
6. `BotMessenger` - 51 edges
7. `ChallengePeriod` - 49 edges
8. `ChallengeParticipant` - 46 edges
9. `Localization` - 42 edges
10. `CreateChallengeWizard` - 42 edges

## Surprising Connections (you probably didn't know these)
- `Admin Panel` --references--> `FortifyServiceProvider`  [INFERRED]
  prompts/main.md → app/Providers/FortifyServiceProvider.php
- `sendsPhoto()` --calls--> `TelegramUpdate`  [INFERRED]
  tests/Feature/Bot/CheckInFlowTest.php → app/Models/TelegramUpdate.php
- `taps()` --calls--> `TelegramUpdate`  [INFERRED]
  tests/Feature/Bot/CheckInFlowTest.php → app/Models/TelegramUpdate.php
- `typesIn()` --calls--> `TelegramUpdate`  [INFERRED]
  tests/Feature/Bot/CheckInFlowTest.php → app/Models/TelegramUpdate.php
- `arrivesViaJoinLink()` --calls--> `TelegramUpdate`  [INFERRED]
  tests/Feature/Bot/JoinChallengeFlowTest.php → app/Models/TelegramUpdate.php

## Import Cycles
- None detected.

## Hyperedges (group relationships)
- **Four surfaces, one Laravel app** — prompts_main_bot_surface, prompts_main_miniapp_surface, prompts_main_admin_surface, prompts_main_website_surface [EXTRACTED 1.00]
- **Idempotency keys across the system** — claude_md_telegram_update_model, claude_md_star_payment_model, claude_md_checkin_model, claude_md_reminder_dispatch_model [EXTRACTED 1.00]
- **Three proof types** — prompts_main_proof_button, prompts_main_proof_text_autogen, prompts_main_proof_image_approval [EXTRACTED 1.00]

## Communities (231 total, 39 thin omitted)

### Community 0 - "delete-user.tsx"
Cohesion: 0.10
Nodes (26): DeleteUser(), Heading(), InputError(), PasswordInput(), Props, TextLink(), Button(), buttonVariants (+18 more)

### Community 1 - "composer.json"
Cohesion: 0.14
Nodes (13): autoload-dev, psr-4, description, keywords, license, minimum-stability, name, prefer-stable (+5 more)

### Community 2 - "ProfileController.php"
Cohesion: 0.16
Nodes (6): LocaleController, ProfileController, SecurityController, PasswordUpdateRequest, Illuminate\Http\RedirectResponse, Inertia\Response

### Community 3 - "scripts"
Cohesion: 0.11
Nodes (19): scripts, lint, lint:check, post-autoload-dump, post-update-cmd, pre-package-uninstall, test, types:check (+11 more)

### Community 4 - "JoinChallengeFlowTest.php"
Cohesion: 0.20
Nodes (5): arrivesViaJoinLink(), givenJoinSlots(), joinableChallenge(), tapsJoin(), theJoiner()

### Community 5 - "Challenge"
Cohesion: 0.09
Nodes (14): MaterialiseChallengePeriods, CarbonImmutable, PeriodType, AnnounceChallenge, Challenge, self, Illuminate\Database\Eloquent\Collection, Illuminate\Foundation\Queue\Queueable (+6 more)

### Community 6 - "devDependencies"
Cohesion: 0.07
Nodes (29): babel-plugin-react-compiler, eslint-config-prettier, eslint-import-resolver-typescript, @eslint/js, eslint-plugin-import, eslint-plugin-react, eslint-plugin-react-hooks, @laravel/vite-plugin-wayfinder (+21 more)

### Community 7 - "cn"
Cohesion: 0.08
Nodes (39): NavigationMenu(), NavigationMenuContent(), NavigationMenuIndicator(), NavigationMenuItem(), NavigationMenuLink(), NavigationMenuList(), NavigationMenuTrigger(), navigationMenuTriggerStyle (+31 more)

### Community 8 - "optionalDependencies"
Cohesion: 0.13
Nodes (15): @laravel/multiplex, lightningcss-linux-x64-gnu, lightningcss-win32-x64-msvc, optionalDependencies, @laravel/multiplex, lightningcss-linux-x64-gnu, lightningcss-win32-x64-msvc, @rollup/rollup-linux-x64-gnu (+7 more)

### Community 9 - "app-sidebar.tsx"
Cohesion: 0.19
Nodes (17): footerNavItems, mainNavItems, NavFooter(), NavMain(), NavUser(), Sidebar(), SidebarGroup(), SidebarMenu() (+9 more)

### Community 10 - "EntitlementType.php"
Cohesion: 0.10
Nodes (12): CreateChallenge, MintJoinToken, ConsumeEntitlement, GrantFreeBaseline, EntitlementType, freeAllowanceSetting(), CoinTransactionReason, SettingKey (+4 more)

### Community 11 - "Illuminate\Foundation\Testing\RefreshDatabase"
Cohesion: 0.07
Nodes (11): Illuminate\Foundation\Testing\RefreshDatabase, Illuminate\Foundation\Testing\TestCase, AuthenticationTest, PasswordConfirmationTest, PasswordResetTest, RegistrationTest, VerificationNotificationTest, DashboardTest (+3 more)

### Community 12 - "Carbon\CarbonImmutable"
Cohesion: 0.15
Nodes (8): CarbonImmutable, ReminderKind, ScheduleChallengeReminders, SendRemindersCommand, SetWebhookCommand, WebhookInfoCommand, Carbon\CarbonImmutable, Illuminate\Console\Command

### Community 13 - "utils.ts"
Cohesion: 0.14
Nodes (17): AppHeader(), Badge(), badgeVariants, Separator(), ToggleGroup(), ToggleGroupContext, ToggleGroupItem(), Toggle() (+9 more)

### Community 14 - "compilerOptions"
Cohesion: 0.10
Nodes (19): resources/js/**/*.d.ts, resources/js/**/*.ts, resources/js/**/*.tsx, compilerOptions, allowJs, baseUrl, esModuleInterop, forceConsistentCasingInFileNames (+11 more)

### Community 15 - "ChallengePeriod"
Cohesion: 0.13
Nodes (9): RollOverPeriod, RollOverDuePeriodsCommand, PeriodNotEndedException, ChallengePeriod, Carbon\CarbonInterface, Illuminate\Support\Collection, endedPeriod(), obligation() (+1 more)

### Community 16 - "components.json"
Cohesion: 0.11
Nodes (17): aliases, components, hooks, lib, ui, utils, iconLibrary, rsc (+9 more)

### Community 17 - "CreateChallengeWizard"
Cohesion: 0.19
Nodes (4): ChallengeVisibility, CreateChallengeWizard, CarbonImmutable, ConversationState

### Community 18 - "Localization"
Cohesion: 0.06
Nodes (19): Exceptions, Never name an exception property $code or $message, VerifyChannelMembership, ChannelGateException, self, HandleAppearance, HandleInertiaRequests, SetLocale (+11 more)

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
Cohesion: 0.10
Nodes (21): clsx, concurrently, lucide-react, dependencies, clsx, concurrently, lucide-react, @radix-ui/react-dialog (+13 more)

### Community 23 - "Inertia React Development"
Cohesion: 0.07
Nodes (27): Basic Link Component, Basic Usage, Client-Side Navigation, Common Pitfalls, Deferred Props, Documentation, Form Component (Recommended), Form Component Reset Props (+19 more)

### Community 24 - "use-appearance.tsx"
Cohesion: 0.08
Nodes (37): i18n + RTL Requirement, AppearanceToggleTab(), LanguageSwitcher(), Toaster(), Appearance, applyTheme(), getStoredAppearance(), handleSystemThemeChange() (+29 more)

### Community 25 - "ChallengeFactory"
Cohesion: 0.22
Nodes (4): ChallengeFactory, PeriodType, ProofType, static

### Community 26 - "Invite"
Cohesion: 0.11
Nodes (6): ClaimInvite, InviteNotClaimableException, InviteRejection, self, Invite, StartCommand

### Community 27 - "Laravel Fortify Development"
Cohesion: 0.12
Nodes (16): Available Features, Best Practices, Custom Authentication Logic, Documentation, Email Verification Setup, Key Endpoints, Laravel Fortify Development, Passkeys Setup (+8 more)

### Community 28 - "Illuminate\Database\Eloquent\Builder"
Cohesion: 0.06
Nodes (3): StarPayment, Illuminate\Database\Eloquent\Builder, Illuminate\Database\Eloquent\Relations\BelongsTo

### Community 29 - "Laravel Fortify Development"
Cohesion: 0.12
Nodes (16): Available Features, Best Practices, Custom Authentication Logic, Documentation, Email Verification Setup, Key Endpoints, Laravel Fortify Development, Passkeys Setup (+8 more)

### Community 30 - "CoinTransactionFactory"
Cohesion: 0.36
Nodes (3): CoinTransactionFactory, CoinTransactionReason, static

### Community 31 - "Settings"
Cohesion: 0.11
Nodes (9): SettingType, type(), Setting, SettingKey, SettingType, Settings, Illuminate\Database\Eloquent\Factories\HasFactory, Illuminate\Http\Client\Request (+1 more)

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
Nodes (18): AppContent(), Props, AppShell(), Props, AppSidebarHeader(), Breadcrumbs(), Breadcrumb(), BreadcrumbEllipsis() (+10 more)

### Community 38 - "require-dev"
Cohesion: 0.15
Nodes (13): require-dev, fakerphp/faker, larastan/larastan, laravel/boost, laravel/pail, laravel/pao, laravel/pint, laravel/sail (+5 more)

### Community 46 - "Detection Checklist"
Cohesion: 0.17
Nodes (11): A. Validation & HTTP input, B. Controllers & routing, C. Authorization, D. Eloquent & models, Detection Checklist, E. Architecture & organization, F. Frontend & views, G. Database & migrations (+3 more)

### Community 47 - "app.tsx"
Cohesion: 0.11
Nodes (15): AppLogo(), AppLogoIcon(), Card(), CardContent(), CardDescription(), CardFooter(), CardHeader(), CardTitle() (+7 more)

### Community 52 - "app-header.tsx"
Cohesion: 0.07
Nodes (34): mainNavItems, Props, rightNavItems, Avatar(), AvatarFallback(), AvatarImage(), DropdownMenu(), DropdownMenuCheckboxItem() (+26 more)

### Community 56 - "ProofType.php"
Cohesion: 0.09
Nodes (11): label(), options(), translationKey(), isAutoApproved(), requiresReview(), announceable(), channelPostButtons(), channelPosts() (+3 more)

### Community 58 - "TelegramUpdate"
Cohesion: 0.16
Nodes (6): IngestTelegramUpdate, ProcessTelegramUpdate, TelegramUpdate, asksForLanguages(), tapsLanguageButton(), handle()

### Community 60 - "BotConversation"
Cohesion: 0.19
Nodes (4): BotConversation, ConversationState, CheckInFlow, ConversationState

### Community 61 - "BotCallback"
Cohesion: 0.12
Nodes (8): BotCallback, self, CheckInCallback, LanguageCallback, WizardCallback, handle(), FailingCallbackHandler, RecordingCallbackHandler

### Community 64 - "BotMessenger"
Cohesion: 0.11
Nodes (7): BotMessenger, JoinCallback, ReviewCheckInCallback, ChannelGatePrompt, LanguageCommand, JoinChallengeFlow, Telegram\Bot\Objects\Message

### Community 65 - "CheckIn"
Cohesion: 0.12
Nodes (9): IssueCheckInPhrase, OpenCheckIn, CarbonImmutable, ProofType, SubmitCheckIn, self, PhraseUnavailableException, CheckIn (+1 more)

### Community 68 - "BotCommand"
Cohesion: 0.16
Nodes (6): BotCommand, self, CancelCommand, CheckInCommand, CreateCommand, handle()

### Community 69 - "CheckInRejectedException"
Cohesion: 0.19
Nodes (8): CheckInRejectedException, CheckInRejection, self, awaitingVerdict(), Closure, verdictRefusal(), Closure, refusalFor()

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
Nodes (30): Backlog — captured, not scheduled, Bot Core Task 1 — webhook intake (done) — commit `807989c`, Bot Core Task 2 — outbound transport, webhook commands, update router (done) — commit `14fbdfb`, Bot Core Task 3 — channel gate + `/start` with invite attribution (done), Bot Core Task 4 — create-challenge wizard (done), Bot Core Task 5 — the join flow (done), Bot Core Task 6 — check-in for all three proof types (done), Build Progress — Telegram Challenges Platform (+22 more)

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
Cohesion: 0.15
Nodes (12): private, $schema, scripts, build, build:ssr, dev, format, format:check (+4 more)

### Community 119 - "Project Goal — Autonomous Build Driver"
Cohesion: 0.22
Nodes (8): Baked-in decisions — do NOT stop to ask about these, Build order (strict), Guardrails, North Star (definition of done), Operating loop — repeat until done, Project Goal — Autonomous Build Driver, Running me across resets, When you MAY stop and ask (only these)

### Community 120 - "User"
Cohesion: 0.07
Nodes (8): User, Illuminate\Database\Eloquent\Relations\HasMany, Illuminate\Database\Eloquent\Relations\HasOne, Illuminate\Foundation\Auth\User, EmailVerificationTest, arrivalFrom(), ProfileUpdateTest, SecurityTest

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

### Community 132 - "ChallengeDraft"
Cohesion: 0.21
Nodes (5): ChallengeDraft, CarbonImmutable, PeriodType, ProofType, self

### Community 133 - "Collection Best Practices"
Cohesion: 0.29
Nodes (6): Choose `cursor()` vs. `lazy()` Correctly, Collection Best Practices, Use `#[CollectedBy]` for Custom Collection Classes, Use Higher-Order Messages for Simple Operations, Use `lazyById()` When Updating Records While Iterating, Use `toQuery()` for Bulk Operations on Collections

### Community 134 - "HTTP Client Best Practices"
Cohesion: 0.29
Nodes (6): Always Set Explicit Timeouts, Fake HTTP Calls in Tests, Handle Errors Explicitly, HTTP Client Best Practices, Use Request Pooling for Concurrent Requests, Use Retry with Backoff for External APIs

### Community 135 - "User.php"
Cohesion: 0.16
Nodes (6): DatabaseSeeder, Illuminate\Contracts\Translation\HasLocalePreference, Illuminate\Database\Console\Seeds\WithoutModelEvents, Illuminate\Database\Seeder, Illuminate\Notifications\Notifiable, arrivesAtBot()

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

### Community 150 - "SettleCheckIn"
Cohesion: 0.25
Nodes (3): ReviewCheckIn, SettleCheckIn, CheckInStatus

### Community 155 - "Entitlement"
Cohesion: 0.16
Nodes (5): PurchaseEntitlement, Entitlement, EntitlementType, grantCreateSlots(), withCreateSlots()

### Community 157 - "PasswordValidationRules.php"
Cohesion: 0.27
Nodes (3): ResetUserPassword, ProfileDeleteRequest, Laravel\Fortify\Contracts\ResetsUserPasswords

### Community 158 - "ChallengeParticipant"
Cohesion: 0.10
Nodes (11): JoinChallenge, ChallengeNotJoinableException, self, InsufficientCoinsException, ChallengeParticipant, ReminderKind, JoinRejection, RuntimeException (+3 more)

### Community 159 - "Controller"
Cohesion: 0.23
Nodes (4): Controller, WebhookController, WebhookRequest, Illuminate\Http\JsonResponse

### Community 166 - "LaravelHttpClient"
Cohesion: 0.23
Nodes (6): LaravelHttpClient, static, GuzzleHttp\Promise\PromiseInterface, Illuminate\Http\Client\PendingRequest, Psr\Http\Message\ResponseInterface, Telegram\Bot\HttpClients\HttpClientInterface

### Community 170 - "InvitesTest.php"
Cohesion: 0.15
Nodes (4): IssueInviteCode, InviteFactory, static, returning()

### Community 173 - "ProfileValidationRules.php"
Cohesion: 0.24
Nodes (6): CreateNewUser, emailRules(), nameRules(), profileRules(), ProfileUpdateRequest, Laravel\Fortify\Contracts\CreatesNewUsers

### Community 174 - "Phase 9 Task 1: Timed/stepped challenge schema & design-time validation"
Cohesion: 0.09
Nodes (21): Before starting, Before starting, Before starting, Code Rules, Code Rules, Code Rules, Explicitly Out of Scope, Explicitly Out of Scope (+13 more)

### Community 175 - "Illuminate\Database\Eloquent\Factories\Factory"
Cohesion: 0.31
Nodes (3): ChallengePeriodFactory, static, Illuminate\Database\Eloquent\Factories\Factory

### Community 176 - "Pest.php"
Cohesion: 0.36
Nodes (7): botKeyboard(), botMessages(), keyboardOn(), lastBotKeyboard(), lastBotReply(), latestBotMessage(), soleBotMessage()

### Community 177 - "FortifyServiceProvider"
Cohesion: 0.17
Nodes (4): AppServiceProvider, FortifyServiceProvider, TelegramServiceProvider, Illuminate\Support\ServiceProvider

### Community 178 - "CallbackRouter"
Cohesion: 0.21
Nodes (4): CallbackRouter, CommandRouter, UpdateRouter, Illuminate\Contracts\Container\Container

### Community 179 - "Illuminate\Database\Eloquent\Model"
Cohesion: 0.12
Nodes (10): isCredit(), isDebit(), sign(), CoinTransaction, CoinLedger, Illuminate\Database\Eloquent\Model, Illuminate\Database\Eloquent\Relations\MorphTo, inParallel() (+2 more)

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

### Community 203 - "Project Goal — Phases 8–10 (Creator Chats, Timed Challenges, AI Approval)"
Cohesion: 0.22
Nodes (8): Baked-in decisions — do NOT stop to ask about these, Before the first run, Build order (strict), Guardrails, North Star (definition of done), Operating loop, Project Goal — Phases 8–10 (Creator Chats, Timed Challenges, AI Approval), When you MAY stop and ask (only these)

### Community 204 - "HandlesUpdate.php"
Cohesion: 0.24
Nodes (5): handle(), DependentUpdateHandler, ExplodingUpdateHandler, routerWith(), SpyUpdateHandler

### Community 205 - "CreateChallengeWizardTest.php"
Cohesion: 0.24
Nodes (9): flowAnswers(), lastOfferedValues(), liveFlow(), wizardChooses(), wizardLimits(), wizardSendsPhoto(), wizardTaps(), wizardTapsData() (+1 more)

### Community 207 - "ConversationState.php"
Cohesion: 0.27
Nodes (8): expectsCallback(), expectsPhoto(), expectsText(), isCheckInStep(), isCreateChallengeStep(), static, flowSittingAt(), lapsedFlowAt()

### Community 208 - "Illuminate\Foundation\Http\FormRequest"
Cohesion: 0.31
Nodes (4): LocaleUpdateRequest, TwoFactorAuthenticationRequest, Illuminate\Foundation\Http\FormRequest, Laravel\Fortify\InteractsWithTwoFactorState

### Community 211 - "setup"
Cohesion: 0.25
Nodes (8): post-root-package-install, setup, composer install, npm install, npm run build, @php artisan key:generate, @php artisan migrate --force, @php -r \"file_exists('.env') || copy('.env.example', '.env');\

### Community 215 - "ReminderDispatch"
Cohesion: 0.07
Nodes (14): DispatchDueReminders, ReminderKind, SendReminder, ReminderDispatch, static, ReminderDispatchFactory, Illuminate\Bus\Queueable, Illuminate\Contracts\Queue\ShouldQueue (+6 more)

### Community 217 - "CheckInFlowTest.php"
Cohesion: 0.25
Nodes (6): aParticipantIn(), currentPeriod(), sendsPhoto(), taps(), theCreatorOf(), typesIn()

### Community 218 - "alert.tsx"
Cohesion: 0.48
Nodes (4): Alert(), AlertDescription(), AlertTitle(), alertVariants

### Community 219 - "Main Prompt Addendum 3 — Bale Multi-Platform & Documentation"
Cohesion: 0.33
Nodes (5): 2.9 Bale — second messenger platform, 3.8 Architecture — the messenger abstraction, 5. Roadmap — Phases 11–12, 7. New open product questions, Main Prompt Addendum 3 — Bale Multi-Platform & Documentation

### Community 220 - "BotConversationFactory"
Cohesion: 0.36
Nodes (3): BotConversationFactory, ConversationState, static

### Community 222 - "post-create-project-cmd"
Cohesion: 0.50
Nodes (4): post-create-project-cmd, @php artisan key:generate --ansi, @php artisan migrate --graceful --ansi, @php -r \"file_exists('database/database.sqlite') || touch('database/database.sqlite');\

### Community 223 - "SettingFactory"
Cohesion: 0.40
Nodes (3): SettingKey, static, SettingFactory

## Knowledge Gaps
- **862 isolated node(s):** `vendor/bin/sail`, `$schema`, `style`, `rsc`, `tsx` (+857 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **39 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `User` connect `User` to `JoinChallengeFlowTest.php`, `Challenge`, `User.php`, `EntitlementType.php`, `Illuminate\Foundation\Testing\RefreshDatabase`, `ChallengePeriod`, `CreateChallengeWizard`, `Localization`, `SubmitCheckIn Action`, `SettleCheckIn`, `ChallengeFactory`, `Invite`, `Entitlement`, `Illuminate\Database\Eloquent\Builder`, `PasswordValidationRules.php`, `ChallengeParticipant`, `Settings`, `CoinTransactionFactory`, `StarPaymentFactory`, `InvitesTest.php`, `ProfileValidationRules.php`, `ResolveTelegramUser`, `Illuminate\Database\Eloquent\Factories\Factory`, `CallbackRouter`, `Illuminate\Database\Eloquent\Model`, `ChallengeParticipantFactory`, `UserFactory`, `ProofType.php`, `BotConversation`, `BotCallback`, `BotMessenger`, `CheckIn`, `ConversationRouter`, `BotCommand`, `CheckInStatus.php`, `CreateChallengeWizardTest.php`, `ReminderDispatch`, `CheckInFlowTest.php`, `BotConversationFactory`, `CallbackQueryHandlerTest.php`, `EntitlementFactory`?**
  _High betweenness centrality (0.140) - this node is a cross-community bridge._
- **Why does `FortifyServiceProvider` connect `FortifyServiceProvider` to `SubmitCheckIn Action`?**
  _High betweenness centrality (0.119) - this node is a cross-community bridge._
- **Why does `Admin Panel` connect `SubmitCheckIn Action` to `FortifyServiceProvider`?**
  _High betweenness centrality (0.117) - this node is a cross-community bridge._
- **Are the 39 inferred relationships involving `User` (e.g. with `.definition()` and `.definition()`) actually correct?**
  _`User` has 39 INFERRED edges - model-reasoned connections that need verification._
- **Are the 10 inferred relationships involving `Challenge` (e.g. with `.handle()` and `.activateStartedChallenges()`) actually correct?**
  _`Challenge` has 10 INFERRED edges - model-reasoned connections that need verification._
- **Are the 7 inferred relationships involving `TelegramUpdate` (e.g. with `sendsPhoto()` and `taps()`) actually correct?**
  _`TelegramUpdate` has 7 INFERRED edges - model-reasoned connections that need verification._
- **What connects `vendor/bin/sail`, `$schema`, `style` to the rest of the system?**
  _862 weakly-connected nodes found - possible documentation gaps or missing edges._