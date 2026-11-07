# Phase 12 — Documentation

## Task 1 — README.md + README.fa.md

Commit `2f31551`. `sail composer ci:check` green before committing (1554 pass / 4 skip, pint, phpstan
lvl 7, eslint, prettier, tsc — README-only change, so the gate was purely a state confirmation).

### What shipped

- `README.md` + `README.fa.md` (full faithful translation; commands, paths, env names, code kept in
  English per spec).

### How the facts were gathered (spec: "pull real versions… don't hardcode from memory")

- **Tech-stack table** read from `composer.json` / `package.json` at write time — laravel/framework
  ^13.17, irazasyed/telegram-bot-sdk ^3.16, laravel/ai ^0.11.0, fortify ^1.37.2, sanctum ^4.0,
  inertia-laravel ^3.0, wayfinder ^0.1.14, pest ^4.7, larastan ^3.9, pint ^1.27, sail ^1.53; react
  ^19.2.0, @inertiajs/react ^3.0.0, tailwindcss ^4.0.0, vite ^8.0.0, typescript ^5.7.2.
- **Env-var reference** generated from the live `.env.example` (TELEGRAM_*/BALE_*/MINIAPP_URL plus
  standard Laravel keys), each with a purpose line — including *why* session/queue/cache are
  `database`.
- **Known-limitations section** assembled from the "open product questions" sections of **all five**
  addenda (2 §7, 3 §7, 4 §7, 5 §7) plus the recorded Bale capability verification in
  `progress-phase-11.md`: cross-platform account linking deferred; Bale has no webhook-secret
  mechanism (URL path secret + server-side re-verification is the whole posture); Bale Pay has no
  refund endpoint (watch-only audit view); AI approval image-only first pass with voice deferred,
  video possibly unavailable on shared hosting, all media-type AI defaults off (admin opt-in);
  uncalibrated AI confidence threshold; `quantity_partial_counts_as_done` default false pending real
  usage data; single global leaderboard-post hour + 5-min/chat on-demand rate limit;
  Setting-backed observability thresholds with placeholder defaults; no external dead-man's-switch
  yet.
- **LICENSE note** states no license is chosen and one must be added before the repo goes public —
  does not pick one, per spec.
- **No screenshots** — `<!-- screenshot: ... -->` placeholders only, per spec.

### Scope decisions worth recording

- Repo-structure block reflects the tree as it exists (`app/Actions` subdirs incl. `Ai`/`MiniApp`,
  `routes/bale.php` alongside `telegram.php`, `resources/js/miniapp` as its own Vite entry) — not the
  CLAUDE.md "living list" projection.
- The Bale rows in the architecture/auth table encode the verified capability facts (no header
  secret) rather than a generic "URL secret" line, so a deployer reading only the README still learns
  the security posture difference.
- Docs links (`docs/user-flows.md`, `docs/setup-*.md`) are forward links — `docs/` ships with Tasks
  2–3, which the README spec anticipated ("quick links" required even though the files don't exist
  yet at Task 1 time).
- Farsi sibling: prose translated, all code/env/paths English; the docs-table Farsi rows link the
  `.md` originals since `.fa.md` siblings don't exist yet either.

**Next:** Phase 12 Task 2 per `prompts/phase-12.md` — `docs/setup-cpanel.md` + `docs/setup-vps.md`
(+ `.fa.md` siblings), re-reading main.md §3.4 and the webhook/CSRF setup facts first.

## Task 2 — Setup guides (cPanel + VPS)

Commit `8275d23`. `sail composer ci:check` green after (state confirmation only, docs-only change).

### What shipped

- `docs/setup-cpanel.md` + `.fa.md` sibling — shared-hosting deployment.
- `docs/setup-vps.md` + `.fa.md` sibling — Ubuntu 24.04 VPS deployment (Nginx, PHP-FPM, Supervisor).
- All commands copy-pasteable; every placeholder (`yourdomain.com`, `USER`, php binary paths)
  explicitly called out as replace-me.

### Facts verified against the codebase before writing (per "Before starting")

- **main.md §3.4 re-read** — MySQL/no-Redis constraint and the explicit
  `queue:work --stop-when-empty --max-time=55` cron pattern both quoted almost verbatim into the
  cPanel guide's §6, with the *why* (one minute of work per minute ceiling; staggering is a design
  decision, not a deployment mistake).
- **Webhook registration, the real surface:**
  - Telegram: `php artisan telegram:set-webhook` (reads both `TELEGRAM_WEBHOOK_SECRET` and
    `TELEGRAM_WEBHOOK_HEADER_SECRET`, refuses http:// URLs, `--drop-pending-updates` option) and
    `php artisan telegram:webhook-info`. The command's own docblock documents the
    register-by-hand-with-one-secret silent-404 footgun — surfaced in the guide's §7.
  - Bale: **no artisan command exists** — recorded in progress-phase-11.md as deliberate ("webhook
    lifecycle is Telegram-only by nature"). The guide ships a copy-pasteable `tinker --execute`
    snippet building `Telegram\Bot\Api` with `baseBotUrl: 'https://tapi.bale.ai/bot'` +
    `LaravelHttpClient` (the exact pattern `BaleMessengerPlatform::api()` uses, including the
    trailing-`/bot` trap the class docblock warns about) and calls `setWebhook(['url' => ...])`
    with the `bale.webhook` named route. Facts from the Phase 11 capability log: no secret_token
    param, HTTPS port 443/88 only, path secret is the whole authenticity mechanism.
- **CSRF/webhook facts** live in `routes/telegram.php` / `routes/bale.php` comments (both routes
  stateless, CSRF-excluded, 404-not-403 on wrong secret) — reflected in the guides' security notes.
- **Scheduler contents** pulled from `routes/console.php`: roll-over + reminders + leaderboards
  every minute; `sanctum:prune-expired`, `payments:sweep-abandoned`, `challenges:prune-proof-media`
  daily — enumerated in cPanel §6 so a deployer knows what the cron line drives.

### Scope decisions

- cPanel guide's §3 explains the env vars *by purpose* (the spec's requirement) and cross-links
  README's full table rather than duplicating all of it; VPS guide cross-links cPanel §3 for the
  bot vars (spec explicitly allows cross-referencing instead of repeating).
- VPS Supervisor `numprocs=2` with a stated rationale (send stagger is 1/sec/chat; more workers only
  add database-queue contention) — a number with a reason, not a default parroted from Laravel docs.
- VPS §9 (headroom: Redis/Horizon, Reverb, more workers, daemons) is informational only and says
  the codebase today deliberately targets database drivers — per the task's "not instructions to
  set them up now".
- Proof-upload size (`client_max_body_size 20m`) matched to the Bale `getFile` 20 MB cap documented
  in the Phase 11 log; Telegram file downloads sit under the same order.
- Troubleshooting table maps symptoms to the *specific* failure modes of this design (two-secret
  webhook, cron-driven queue, Vite manifest upload, Bale provider-token gate).

**Next:** Phase 12 Task 3 per `prompts/phase-12.md` — `docs/user-flows.md` + `.fa.md` sibling
(participant/creator/admin flows, worked timed-session + AI-approval examples, one Mermaid diagram
per section), re-reading main-addendum-2.md §2.7–§2.8 first.

## Task 3 — Application flow document

Commit `bcdf024`. `sail composer ci:check` green after (state confirmation only).

### What shipped

- `docs/user-flows.md` + `user-flows.fa.md` sibling — participant/creator/admin walkthroughs, one
  Mermaid diagram per section (participant join sequence, timed-session flowchart, AI-vs-manual
  review sequence, admin-panel flowchart). Farsi diagrams keep Farsi node labels inline (the spec's
  "if that reads more clearly" branch) — they read clearly; no parallel diagrams needed.

### Facts verified against code before writing

- **Addendum-2 §2.7/§2.8 re-read in full** (per "Before starting") — the timed-session walkthrough
  and AI-approval section follow its examples closely: 3-step stretch→workout→cooldown, refuse-early-
  with-seconds-remaining, authoritative voice duration, sum-of-waits ≤ period length, platform-
  authored prompt + criteria-as-data + locked schema, ~500-char screened criteria, confidence
  threshold → manual queue.
- **Wizard order** pulled from `CreateChallengeWizard`'s actual state-advance map (L393–453): title →
  description → period type (→ custom days) → **timezone → start date → total periods** → scoring
  (target/unit/base/partial when quantity) → proof type → approval mode (image only) → criteria
  (AI only) → visibility → flow type → step loop (timed only) → confirmation. First draft followed
  CLAUDE.md's older four-step sketch; corrected. **Freezes are NOT asked** — the wizard reads
  `SettingKey::DefaultChallengeFreezes` (L708).
- **Bot command surface** from `TelegramServiceProvider::BOT_COMMANDS`: `start, create, checkin,
  chatlink, shop, language, cancel` + in-chat `/leaderboard` (LinkedChatHandler, admin-gated,
  RateLimiter cooldown).
- **Mini App screens**: challenge-list, challenge-detail (matches what the doc claims the status
  screen shows). **Admin pages**: Challenges(+Show), Users(+Show), Invites, Payments, Reviews,
  Settings.
- **Mermaid render check**: node labels containing `>` (the `>=` waits) quoted — bare `>` inside
  `[]` breaks flowchart parsing; sequence-diagram `alt`/`else` with `=` labels and Persian
  participant aliases are fine as-is.

### Scope decisions

- Timed-session example is the addendum's own 5/10/5-minute design, concretely walked with what the
  bot shows at each point — per the spec's "real worked examples, not schema descriptions".
- The "I've joined" re-verify button appears in the Mermaid join sequence (it shipped with the
  callback work in Bot Core Task 4, after the early deferral noted in progress.md L1500).
- No API reference, no developer content (out of scope per spec); payments paragraph states the
  both-rails picture including Bale-no-refund once, without re-litigating the limitations list.

**Phase 12 complete.** **Next:** Phase 13 per `prompts/phase-13.md` (observability hardening), then
the release close-out the user requested after 13: push to origin, merge to master (local + origin),
tag an alpha release.
