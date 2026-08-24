# Graph Report - .  (2026-08-24)

## Corpus Check
- Corpus is ~29,556 words - fits in a single context window. You may not need a graph.

## Summary
- 788 nodes · 1387 edges · 92 communities (51 shown, 41 thin omitted)
- Extraction: 96% EXTRACTED · 4% INFERRED · 0% AMBIGUOUS · INFERRED: 53 edges (avg confidence: 0.78)
- Token cost: 0 input · 0 output

## Community Hubs (Navigation)
- Form & Page Components
- Composer Autoload & Config
- Auth Actions & Form Requests
- Composer Scripts (CI/Dev)
- App Bootstrap & Theming
- App Shell & Layout Components
- NPM Dev Dependencies (Lint/Build)
- Navigation Menu Component
- NPM Optional Platform Binaries
- Sidebar Navigation Components
- Sidebar UI Primitives
- Auth Feature Tests
- Header & Avatar Components
- Dropdown Menu Component
- TypeScript Config
- User Model & Email Verification
- shadcn/ui Config
- Logo & Card Components
- HTTP Middleware & Bootstrap
- Service Providers (Fortify)
- Bot & Mini App Surfaces (spec)
- Reminders, Payments & Idempotency (spec)
- NPM Runtime Dependencies
- Coin Economy & Ledger (spec)
- Shared TS Types
- Authentication Tests
- User Factory
- Proof Types & Check-In (spec)
- Alert Components
- Profile Settings Tests
- Security Settings Tests
- User Model & Auth Config
- Challenge Domain Models (spec)
- Mobile Breakpoint Hook
- Database Seeder
- Dashboard Page & Placeholder
- Clipboard Hook
- Verification Notification Tests
- App Service Provider
- eslint.config.js
- icon.tsx
- package.json
- package.json
- package.json
- package.json
- package.json
- package.json
- package.json
- package.json
- package.json
- package.json
- package.json
- package.json
- package.json
- package.json
- package.json
- package.json
- package.json
- package.json
- package.json
- package.json
- package.json
- package.json
- package.json
- package.json
- package.json
- package.json
- package.json
- package.json
- package.json
- apple-touch-icon.png
- dependabot.yml

## God Nodes (most connected - your core abstractions)
1. `cn()` - 123 edges
2. `User` - 40 edges
3. `TestCase` - 25 edges
4. `Button()` - 15 edges
5. `compilerOptions` - 15 edges
6. `scripts` - 13 edges
7. `require-dev` - 10 edges
8. `InputError()` - 10 edges
9. `Label()` - 10 edges
10. `useAppearance()` - 10 edges

## Surprising Connections (you probably didn't know these)
- `Admin Panel` --references--> `FortifyServiceProvider`  [INFERRED]
  prompts/main.md → app/Providers/FortifyServiceProvider.php
- `User model Telegram extension` --references--> `User`  [EXTRACTED]
  CLAUDE.md → app/Models/User.php
- `CI Tests Workflow` --references--> `ci:check`  [EXTRACTED]
  .github/workflows/tests.yml → composer.json
- `Admin Panel` --conceptually_related_to--> `AppSidebar()`  [INFERRED]
  prompts/main.md → resources/js/components/app-sidebar.tsx
- `SubmitCheckIn Action` --shares_data_with--> `CheckIn (model)`  [INFERRED]
  prompts/main.md → CLAUDE.md

## Import Cycles
- None detected.

## Hyperedges (group relationships)
- **Four surfaces, one Laravel app** — prompts_main_bot_surface, prompts_main_miniapp_surface, prompts_main_admin_surface, prompts_main_website_surface [EXTRACTED 1.00]
- **Idempotency keys across the system** — claude_md_telegram_update_model, claude_md_star_payment_model, claude_md_checkin_model, claude_md_reminder_dispatch_model [EXTRACTED 1.00]
- **Three proof types** — prompts_main_proof_button, prompts_main_proof_text_autogen, prompts_main_proof_image_approval [EXTRACTED 1.00]

## Communities (92 total, 41 thin omitted)

### Community 0 - "Form & Page Components"
Cohesion: 0.09
Nodes (29): DeleteUser(), Heading(), InputError(), PasswordInput(), Props, TextLink(), Badge(), badgeVariants (+21 more)

### Community 1 - "Composer Autoload & Config"
Cohesion: 0.04
Nodes (48): pestphp/pest-plugin, php-http/discovery, autoload, autoload-dev, psr-4, psr-4, config, allow-plugins (+40 more)

### Community 2 - "Auth Actions & Form Requests"
Cohesion: 0.08
Nodes (18): CreateNewUser, ResetUserPassword, emailRules(), nameRules(), profileRules(), Controller, ProfileController, SecurityController (+10 more)

### Community 3 - "Composer Scripts (CI/Dev)"
Cohesion: 0.05
Nodes (40): scripts, ci:check, dev, lint, lint:check, post-autoload-dump, post-create-project-cmd, post-root-package-install (+32 more)

### Community 4 - "App Bootstrap & Theming"
Cohesion: 0.12
Nodes (22): i18n + RTL Requirement, AppearanceToggleTab(), Toaster(), Appearance, applyTheme(), getStoredAppearance(), handleSystemThemeChange(), initializeTheme() (+14 more)

### Community 5 - "App Shell & Layout Components"
Cohesion: 0.13
Nodes (18): AppContent(), Props, AppShell(), Props, AppSidebarHeader(), Breadcrumbs(), Breadcrumb(), BreadcrumbEllipsis() (+10 more)

### Community 6 - "NPM Dev Dependencies (Lint/Build)"
Cohesion: 0.07
Nodes (29): babel-plugin-react-compiler, eslint-config-prettier, eslint-import-resolver-typescript, @eslint/js, eslint-plugin-import, eslint-plugin-react, eslint-plugin-react-hooks, @laravel/vite-plugin-wayfinder (+21 more)

### Community 7 - "Navigation Menu Component"
Cohesion: 0.13
Nodes (22): NavigationMenu(), NavigationMenuContent(), NavigationMenuIndicator(), NavigationMenuItem(), NavigationMenuLink(), NavigationMenuList(), NavigationMenuTrigger(), navigationMenuTriggerStyle (+14 more)

### Community 8 - "NPM Optional Platform Binaries"
Cohesion: 0.07
Nodes (27): @laravel/multiplex, lightningcss-linux-x64-gnu, lightningcss-win32-x64-msvc, optionalDependencies, @laravel/multiplex, lightningcss-linux-x64-gnu, lightningcss-win32-x64-msvc, @rollup/rollup-linux-x64-gnu (+19 more)

### Community 9 - "Sidebar Navigation Components"
Cohesion: 0.13
Nodes (23): AppHeader(), footerNavItems, mainNavItems, NavFooter(), NavMain(), SidebarContent(), SidebarFooter(), SidebarGroup() (+15 more)

### Community 10 - "Sidebar UI Primitives"
Cohesion: 0.10
Nodes (23): NavUser(), Separator(), SheetDescription(), Sidebar(), SidebarContext, SidebarGroupAction(), SidebarInput(), SidebarInset() (+15 more)

### Community 11 - "Auth Feature Tests"
Cohesion: 0.13
Nodes (8): Illuminate\Foundation\Testing\RefreshDatabase, Illuminate\Foundation\Testing\TestCase, PasswordConfirmationTest, RegistrationTest, DashboardTest, ExampleTest, TestCase, ExampleTest

### Community 12 - "Header & Avatar Components"
Cohesion: 0.14
Nodes (17): mainNavItems, Props, rightNavItems, Avatar(), AvatarFallback(), AvatarImage(), Sheet(), SheetContent() (+9 more)

### Community 13 - "Dropdown Menu Component"
Cohesion: 0.13
Nodes (16): DropdownMenu(), DropdownMenuCheckboxItem(), DropdownMenuContent(), DropdownMenuGroup(), DropdownMenuItem(), DropdownMenuLabel(), DropdownMenuRadioItem(), DropdownMenuSeparator() (+8 more)

### Community 14 - "TypeScript Config"
Cohesion: 0.10
Nodes (19): resources/js/**/*.d.ts, resources/js/**/*.ts, resources/js/**/*.tsx, compilerOptions, allowJs, baseUrl, esModuleInterop, forceConsistentCasingInFileNames (+11 more)

### Community 15 - "User Model & Email Verification"
Cohesion: 0.16
Nodes (4): User, Illuminate\Foundation\Auth\User, EmailVerificationTest, PasswordResetTest

### Community 16 - "shadcn/ui Config"
Cohesion: 0.11
Nodes (17): aliases, components, hooks, lib, ui, utils, iconLibrary, rsc (+9 more)

### Community 17 - "Logo & Card Components"
Cohesion: 0.19
Nodes (9): AppLogo(), AppLogoIcon(), Card(), CardContent(), CardDescription(), CardFooter(), CardHeader(), CardTitle() (+1 more)

### Community 18 - "HTTP Middleware & Bootstrap"
Cohesion: 0.24
Nodes (7): HandleAppearance, HandleInertiaRequests, Closure, Illuminate\Foundation\Configuration\Middleware, Illuminate\Http\Request, Inertia\Middleware, Symfony\Component\HttpFoundation\Response

### Community 20 - "Bot & Mini App Surfaces (spec)"
Cohesion: 0.25
Nodes (8): BotConversation (model), Channel Access Gate, Telegram Bot (primary surface), Telegram Challenges Platform, initData Validation, Mini App = SPA + API, not Inertia, Mini App (gameish dashboard), Public Website

### Community 21 - "Reminders, Payments & Idempotency (spec)"
Cohesion: 0.22
Nodes (9): ReminderDispatch (model), StarPayment (model), TelegramUpdate (model), laravel.test Sail service, Idempotency Requirement, No-Redis / MySQL 8.4 Constraint, Period Materialisation, Per-Period Reminders (+1 more)

### Community 22 - "NPM Runtime Dependencies"
Cohesion: 0.22
Nodes (9): concurrently, dependencies, concurrently, @radix-ui/react-label, @radix-ui/react-toggle, @radix-ui/react-toggle-group, @radix-ui/react-label, @radix-ui/react-toggle (+1 more)

### Community 23 - "Coin Economy & Ledger (spec)"
Cohesion: 0.29
Nodes (8): CoinTransaction (ledger), Entitlement (model), Invite (model), Setting (model), User model Telegram extension, Single-Currency Coin Economy, CoinLedger Service, Brand-New-User Invite Crediting

### Community 24 - "Shared TS Types"
Cohesion: 0.32
Nodes (6): Auth, User, InertiaConfig, @inertiajs/core, InputHTMLAttributes, react

### Community 26 - "User Factory"
Cohesion: 0.43
Nodes (3): UserFactory, Illuminate\Database\Eloquent\Factories\Factory, static

### Community 27 - "Proof Types & Check-In (spec)"
Cohesion: 0.33
Nodes (7): Admin Panel, Button Proof Type, Image-Approval Proof Type, Text-Autogen Proof Type, Shared-Core Action Rule, SubmitCheckIn Action, AppSidebar()

### Community 28 - "Alert Components"
Cohesion: 0.48
Nodes (4): Alert(), AlertDescription(), AlertTitle(), alertVariants

### Community 32 - "Challenge Domain Models (spec)"
Cohesion: 0.40
Nodes (6): Challenge (model), ChallengeParticipant (model), ChallengePeriod (model), CheckIn (model), Freeze Mechanic, Miss / Streak-Reset Rule

### Community 33 - "Mobile Breakpoint Hook"
Cohesion: 0.53
Nodes (5): SidebarProvider(), getServerSnapshot(), isSmallerThanBreakpoint(), mediaQueryListener(), useIsMobile()

### Community 34 - "Database Seeder"
Cohesion: 0.60
Nodes (3): DatabaseSeeder, Illuminate\Database\Console\Seeds\WithoutModelEvents, Illuminate\Database\Seeder

### Community 36 - "Clipboard Hook"
Cohesion: 0.40
Nodes (3): CopiedValue, CopyFn, UseClipboardReturn

## Knowledge Gaps
- **209 isolated node(s):** `$schema`, `style`, `rsc`, `tsx`, `config` (+204 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **41 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `Admin Panel` connect `Proof Types & Check-In (spec)` to `Service Providers (Fortify)`, `Bot & Mini App Surfaces (spec)`?**
  _High betweenness centrality (0.190) - this node is a cross-community bridge._
- **Why does `AppSidebar()` connect `Proof Types & Check-In (spec)` to `Sidebar Navigation Components`, `App Shell & Layout Components`?**
  _High betweenness centrality (0.184) - this node is a cross-community bridge._
- **Why does `FortifyServiceProvider` connect `Service Providers (Fortify)` to `Proof Types & Check-In (spec)`?**
  _High betweenness centrality (0.157) - this node is a cross-community bridge._
- **Are the 30 inferred relationships involving `User` (e.g. with `.run()` and `.test_users_are_rate_limited()`) actually correct?**
  _`User` has 30 INFERRED edges - model-reasoned connections that need verification._
- **What connects `$schema`, `style`, `rsc` to the rest of the system?**
  _209 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `Form & Page Components` be split into smaller, more focused modules?**
  _Cohesion score 0.09322033898305085 - nodes in this community are weakly interconnected._
- **Should `Composer Autoload & Config` be split into smaller, more focused modules?**
  _Cohesion score 0.04081632653061224 - nodes in this community are weakly interconnected._