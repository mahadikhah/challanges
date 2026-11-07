# Main Prompt — Telegram Challenges Platform

The master reference for this project. `CLAUDE.md` at the repo root holds the *stable operating contract* that
Claude Code loads automatically every session; this document holds the **product spec, the reasoning behind the
architecture, the build roadmap, and the template for writing individual task prompts**.

Read order for a new task: `CLAUDE.md` (always loaded) → the relevant roadmap phase below → write the task
prompt using the template at the end.

---

## 1. What we are building

A Telegram-native accountability platform. People create challenges ("meditate daily for 30 days"), invite
friends, check in every period, and keep streaks alive. Growth is driven by invites: your free allowance is
small, and you widen it by bringing new people to the bot — or by paying with Telegram Stars.

Four surfaces, one Laravel application, one repository:

1. **The bot** — the primary product. Challenge details, reminders every period, check-ins, invites, payments.
2. **The Mini App** — a "gameish" dashboard: challenge details, your status, freezes used vs remaining, total
   periods, progress.
3. **The admin panel** — Inertia + React + TS. Tune the economy, moderate challenges, review image proofs.
4. **The public website** — marketing landing first; a fuller mirror later.

**Build order is bot first.** Everything else assumes a working bot.

---

## 2. Product specification

### 2.1 Access gate

Every user must join the announcement channel before using the bot. The bot is an admin of that channel and
auto-posts public challenges there. Verify membership with `getChatMember` on `/start`, block with a "join"
button until confirmed, then re-verify on privileged actions (creating, joining, spending).

### 2.2 The economy — one currency

The original description had invites unlocking challenge slots *and* coins buying slots. That is two parallel
entitlement systems to keep consistent, so we **normalise to a single currency**:

```
Telegram Stars ──┐
Invites ─────────┼──► COINS ──► create-slots / join-slots / freezes
Completions ─────┤
Admin grants ────┘
```

- Free baseline: **1 challenge created + 1 challenge joined**.
- Every rate is admin-configurable: coins per credited invite, coin price of a create-slot, of a join-slot, of
  a freeze, and the Stars→coins packages. **Nothing hardcoded.**
- This still delivers the original intent ("N invites lets you make M challenges") — just through one auditable
  ledger instead of two.
- An invite credits coins **only when the invited user is brand-new to the bot** — first-ever `/start`, no
  prior user row. This is the anti-abuse rule that matters most; get it right and test it hard.
- **No buy-in / prize-pool mechanics.** Telegram Stars are for digital goods and services; pooled wagering
  where winners take the pot risks the bot being restricted. Completion rewards are flat and platform-funded.

### 2.3 Challenges

| Field | Values / notes |
|---|---|
| `period_type` | `daily`, `weekly`, `monthly`, `seasonal`, `yearly`, `custom` (N days) |
| Timeline | Creator sets start date + total periods + timezone. **One shared fixed timeline for everyone.** |
| Late joiners | Catch up on the shared clock, but are **not** penalised for periods before they joined. |
| `visibility` | `public` (auto-posted to the channel) or `invite_only` |
| `proof_type` | `button`, `text_autogen`, `image_approval` — creator's choice |
| `proof_is_public` | Creator toggle, **default private** |
| Freeze | Skips one missed period penalty-free |
| Miss, no freeze | **Streak resets to 0; participant stays in the challenge** |

**The proof mechanic** is the product's cleverest idea and deserves care:

- `button` — one tap, auto-approved. Lowest friction, weakest accountability.
- `text_autogen` — the system generates a unique, meaningful phrase for the period and the participant must
  type it. Auto-approved on normalised exact match, so no manual review, yet screenshots and pre-written
  submissions don't work. **Generate the phrase per participant per period, not per period** — otherwise
  participants in a group chat paste it to each other and the mechanic evaporates.
- `image_approval` — photo upload, creator approves or rejects. Highest accountability, needs human review, so
  it's the only mode where a public proof feed is actually interesting.

Rationale for the miss rule: a participant may have spent a friend's invite to get that slot. Kicking them out
over one missed period burns the invite for nothing and discourages retrying. A stricter "N resets →
auto-remove" rule can be added later as a counter check — it needs no schema change.

### 2.4 Reminders

Sent every period, in the challenge's timezone, in the user's locale. Daily/weekly/monthly/seasonal/yearly/
custom are **the same mechanism with a different interval**, not six systems: materialise `ChallengePeriod`
rows, then a scheduled job finds due periods and dispatches queued reminder jobs.

### 2.5 Localisation

Multi-language from day one — Farsi and English minimum, locale per user, **RTL required**. Note the scaffold
has *no* i18n layer and *no* RTL handling at all, so both are genuine build work.

---

## 3. Architecture decisions

### 3.1 Why Laravel

Settled in the earlier discussion: the user knows Laravel best, and already has production-tested analogues of
this exact puzzle — a bot invoice/payment flow, OTP auth, multi-gateway payment handling. The project is not a
stateless site: it has a coins ledger, referral crediting, payment reconciliation and per-period scheduling. It
needs real transactions, a real queue and real cron. Laravel supplies all of that in the stack the user is
fastest in, and a bot webhook is just a route + controller.

### 3.2 Why the Mini App is an API + separate React SPA, not Inertia

This was the open question, and it resolves to a hard technical constraint rather than a preference.

Telegram's documentation states that to validate a Mini App user you "send the data from the
`Telegram.WebApp.initData` field to the bot's backend" — the identity is **pushed up by client JS after the
page loads**. It is also mirrored into the launch URL's hash fragment, and fragments are never transmitted in
an HTTP request.

Therefore Laravel **cannot know who the Telegram user is on the first page load** — which is exactly what
Inertia needs in order to render props server-side. An Inertia Mini App would have to render an empty shell →
run JS → POST initData → refetch. That *is* an SPA + API flow, just with an extra round trip and a flash of
empty state. Add unreliable cookie persistence in third-party in-app webviews, and the choice is clear.

Secondary but real: the Mini App is a gameish mobile UI on mobile networks; the admin panel is a desktop
dashboard with tables and a sidebar. Neither should ship the other's JavaScript.

**So:** separate React SPA for the Mini App, Inertia for the admin panel and website — the same repo and the
same Laravel app, with **separate Vite entries**, sharing `resources/js/lib`, `resources/js/types` and pure UI
primitives. Not a separate project, and no CORS.

One useful asymmetry: `?startapp=<code>` deep-link parameters arrive as the **`tgWebAppStartParam` GET
parameter**, which *is* server-visible. Invite attribution can use that even though initData cannot.

### 3.3 The shared-core rule

The bot, the Mini App and the admin panel all perform check-ins. The domain logic therefore lives in **Action
classes** that none of the three own, and each surface is a thin adapter over them. One `SubmitCheckIn`, three
callers. This is the single most important structural rule in the project.

### 3.4 Infrastructure constraints

**MySQL 8.4, and no Redis anywhere** — the host doesn't provide it. Queue, cache and session all use the
`database` driver. This rules out Horizon, Redis locks, and Reverb-as-default, and it caps reminder throughput,
so reminder fan-out must stagger sends rather than rely on a fast broker.

Unresolved and worth deciding deliberately rather than discovering late: **how the queue worker runs.** With no
Redis and no Supervisor, the shared-hosting pattern is cron every minute running
`artisan queue:work --stop-when-empty --max-time=55`. That works but bounds throughput. The earlier discussion
recommended a cheap VPS partly for this reason. Confirm against the real host before designing the reminder
fan-out around it.

---

## 4. Setup gap list — do this before feature work

The repo is already a scaffolded `laravel/react-starter-kit` (Laravel 13.17, Inertia v3, React 19, Tailwind 4,
Fortify, Wayfinder, shadcn/ui, Pint + Larastan + PHPUnit, Sail). It is **not yet a git repo**.

1. `git init` — there is no repository yet.
2. Add a **MySQL 8.4** service to `compose.yaml` (currently only `laravel.test`, no database service at all);
   switch `.env` to `DB_CONNECTION=mysql`. Keep `QUEUE_CONNECTION=database`, `CACHE_STORE=database`,
   `SESSION_DRIVER=database`.
3. `composer require irazasyed/telegram-bot-sdk:^3.16` — **v3.16.0 is the first release that allows
   `illuminate/support: 9 - 13`**, so it is Laravel 13 compatible. Do not accept a lower resolved version.
4. `composer require --dev pestphp/pest pestphp/pest-plugin-laravel`; create `tests/Pest.php`. Pest is **not**
   currently installed (the `pest-plugin` entry in `composer.json`'s `allow-plugins` is a leftover).
5. `composer require laravel/sanctum` for Mini App bearer tokens — not currently installed.
6. Add `routes/api.php`, `routes/admin.php`, `routes/telegram.php` and register them in `bootstrap/app.php`
   (which currently registers only `web` + `console`).
7. Add the Mini App Vite entry (`resources/js/miniapp/main.tsx`) to `vite.config.ts` inputs.
8. Exclude the bot webhook and `/api/*` from **`PreventRequestForgery`** (Laravel 13's renamed CSRF middleware).
9. Build the i18n layer and RTL plumbing — both net-new.
10. Add the `Setting` model + admin-tunable config early; everything else reads from it.

---

## 5. Build roadmap

Each phase is a task area. Numbering restarts per area, e.g. "Bot Core Task 3".

### Phase 1 — Setup
The gap list above. No product behaviour yet. Ends with `sail composer ci:check` green.

### Phase 2 — Domain core (no Telegram coupling at all)
Migrations, models, backed enums, and the pure Actions:
- `CoinLedger` service — locked, transactional, idempotency-keyed.
- Entitlement grant/consume.
- Period materialisation for all six `period_type` values.
- Streak / freeze / miss engine.
- Invite code generation and the brand-new-user crediting rule.
- Per-participant-per-period phrase generation.

This phase should be **fully unit-tested and callable without a bot**. It is the foundation everything else
adapts to; if it's right, the surfaces are thin.

### Phase 3 — Bot core
Webhook + `update_id` idempotency + immediate 200 + queued processing. Channel gate. `/start` with invite
attribution. Create-challenge wizard via `BotConversation`. Join flow. Check-in for all three proof types.
Reminders via scheduler + staggered queued jobs. Locale selection.

### Phase 4 — Stars payments
`createInvoiceLink` with currency `XTR` and empty `provider_token` → `pre_checkout_query` →
`successful_payment` → credit coins keyed on `telegram_payment_charge_id`. Refund path via `refundStarPayment`.

### Phase 5 — Mini App
`POST /api/v1/miniapp/auth` (initData → Sanctum token), then the `/api/v1/miniapp/*` surface, then the gameish
React SPA: challenge details, user status, freezes used/remaining, total periods, progress. Wire Telegram
`themeParams`, `BackButton` and `MainButton`.

### Phase 6 — Admin panel
Inertia CRUD: settings/economy tuning, challenge moderation, image-proof review queue, user and coin
adjustments, invite/payment audit views.

### Phase 7 — Website
Marketing landing first. Fuller mirror later.

---

## 6. Verification strategy

- `sail composer ci:check` green — Pint, PHPStan, Pest, ESLint, Prettier, `tsc --noEmit`.
- **Bot, against a test bot token:** `setWebhook` with a secret, then exercise `/start` (channel gate + invite
  credit), the create wizard, and each proof type. **Replay the same `update_id` twice and assert exactly one
  effect** — the single most important integration check in the project.
- **Reminders:** freeze the clock with `travel()`, run the scheduler across a period boundary twice, assert one
  send per participant per period.
- **Coins:** concurrent-spend test asserting the balance can never go negative.
- **Stars:** replay a `successful_payment` with a duplicate `telegram_payment_charge_id`; assert one credit.
- **Mini App:** launch from the test bot on a real device; plus a test posting a tampered `initData` hash and
  asserting rejection, and one with a stale `auth_date`.

---

## 7. Open product questions

Not blocking, but they'll need answers eventually:

1. **Completion reward size** — leaning toward a flat coin reward. Confirm the amount and whether it scales
   with challenge length.
2. **Proof visibility default** — currently creator-toggleable, default private. Only meaningful for
   `image_approval`.
3. **Repeat failures** — should "N streak resets" eventually remove a participant? Deliberately deferred; it's
   a counter check, not a schema change.
4. **Hosting** — shared hosting vs cheap VPS. Drives the queue-worker pattern (see §3.4).

---

## 8. Task prompt template

Copy this per task. Keep tasks small enough that one produces a reviewable diff.

```markdown
## <Area> Task <N>: <short title>

### Goal
<One or two sentences. The outcome, not the steps.>

### Before starting
- Query graphify for existing patterns: `graphify query "<relevant concept>"`
- <Anything to read or confirm first.>

### What to Build
1. <Specific, ordered items. Name files and classes where known.>
2. ...

### Tests (Pest, required)
- <Behaviours that must be covered, especially idempotency, money and authorization.>
- Use datasets for the six period types where relevant. `Http::fake()` all Bot API calls.

### Explicitly Out of Scope
- <What NOT to touch, so the diff stays reviewable.>

### Code Rules
- Follow CLAUDE.md. Thin controllers → Actions; Form Requests for validation; backed enums, no magic strings.
- <Task-specific constraints.>

Work only on this task.
```
