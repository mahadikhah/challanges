## Phase 12 Task 1: README.md

### Goal
Write a complete, accurate `README.md` documenting what the project actually is and does — not a generic
Laravel-starter-kit README. A Farsi sibling `README.fa.md` ships alongside it.

### Before starting
- This task only starts once `prompts/progress.md` shows Phases 1–11 all ✅. If it doesn't, stop and finish
  those first.
- Re-read `prompts/main.md`, `prompts/main-addendum-2.md`, and `prompts/main-addendum-3.md` in full — the
  README's feature list must reflect what was actually built, including any assumptions or scope decisions
  recorded in `prompts/progress.md` along the way (e.g. degraded Bale capabilities, deferred voice AI
  approval).

### What to Build
1. `README.md` covering, at minimum:
   - What the project is (one paragraph, plain language): a Telegram/Bale accountability platform for
     challenges with streaks, freezes, and an invite-driven economy.
   - Feature list, grouped: challenges & periods, proof types incl. timed/stepped sessions, invites & coin
     economy, Telegram Stars + Bale Pay, creator-owned announcement chats, AI-assisted proof approval,
     multi-language + RTL, admin panel.
   - Architecture overview: one Laravel app, four surfaces (bot core, Mini App SPA, Inertia admin, Inertia
     website), MySQL-only/no-Redis constraint and what that implies for the queue.
   - Tech stack table (Laravel version actually installed, Inertia version, React version, key packages —
     pull real versions from `composer.json`/`package.json`, don't hardcode from memory).
   - Repo structure overview (what lives where — Actions, the Mini App's separate Vite entry, route files).
   - Quick links to `docs/setup-cpanel.md`, `docs/setup-vps.md`, `docs/user-flows.md`, and their `.fa.md`
     siblings.
   - Environment variable reference — generate this from the actual `.env.example`, don't hand-write a
     possibly-stale list.
   - Running tests (`sail composer ci:check`) and what it covers.
   - Known limitations / deferred items, pulled honestly from the "open questions" sections across the three
     main-addendum files (cross-platform account linking, voice AI-approval, etc.) — don't omit these to make
     the project look more finished than it is.
   - A `LICENSE` note: state that no license is currently chosen and one should be added before the repo is
     made public, rather than picking one.
2. `README.fa.md` — a full, faithful Farsi translation of the same content. Keep all commands, file paths,
   environment variable names, and code blocks in their original (English/code) form; translate prose only.

### Tests
- None (documentation task) — but do run a final `sail composer ci:check` to confirm the repo is actually in
  the state the README describes before finishing.

### Explicitly Out of Scope
- No screenshots/GIFs (no running instance to capture them from in this environment) — leave clearly marked
  placeholders (`<!-- screenshot: mini app dashboard -->`) instead of fabricating image links.
- No contributing guide beyond a short pointer section — not a full `CONTRIBUTING.md`.

### Code Rules
- Follow `CLAUDE.md`'s documentation conventions if any exist; otherwise, standard GitHub-flavored Markdown,
  no external assets.

Work only on this task.

## Phase 12 Task 2: Setup guides (shared cPanel & Ubuntu VPS)

### Goal
Two deployment guides reflecting how this specific project actually needs to be deployed — not generic
Laravel deployment advice. Each ships with a Farsi sibling.

### Before starting
- Only start once Phase 12 Task 1 is done (README exists, so these can link back to it).
- Re-read `prompts/main.md` §3.4 (infrastructure constraints — MySQL, no Redis, cron-based queue worker) and
  the webhook setup from Setup Task 1 (CSRF exclusions, secret validation) before writing either guide —
  both are things a deployer must configure correctly or the bot silently won't work.

### What to Build

**`docs/setup-cpanel.md`** — shared hosting:
1. Prerequisites: PHP 8.3+, MySQL 8.4 (or closest available), Composer access (via SSH or the host's
   installer), cron job access, HTTPS on the domain (mandatory — Telegram and Bale both refuse non-HTTPS
   webhook URLs).
2. Getting the code onto the server (git via SSH if available; upload otherwise) and setting the document
   root to `public/`.
3. `.env` configuration — every variable from `.env.example`, called out by purpose, not just listed.
   `QUEUE_CONNECTION=database`, `CACHE_STORE=database`, `SESSION_DRIVER=database` — explain *why* (no Redis
   on shared hosting) rather than just stating it.
4. `composer install --no-dev --optimize-autoloader`; running migrations.
5. Building frontend assets: shared hosts often lack Node — document building locally and uploading
   `public/build`, as the primary path, with "build on the server via SSH if Node is available" as an
   alternative.
6. Cron entries, exact lines: the Laravel scheduler (`* * * * * php artisan schedule:run`) and the queue
   worker (`* * * * * php artisan queue:work --stop-when-empty --max-time=55`) — explain that this bounds
   throughput and is the reason reminder/announcement fan-out is staggered in code, not a deployment mistake.
7. Registering both webhooks (Telegram `setWebhook`, Bale's equivalent) — the exact artisan command or
   `tinker` snippet the codebase actually provides for this, with the webhook secret configuration.
8. File permissions (`storage/`, `bootstrap/cache/`) and common cPanel gotchas: disabled PHP functions,
   execution time limits interacting with `--max-time=55`, `.htaccess` requirements for `public/`.
9. A troubleshooting section for the failure modes specific to this constrained environment.

**`docs/setup-vps.md`** — Ubuntu VPS:
1. Prerequisites: Ubuntu 24.04, PHP 8.3, MySQL 8.4, Nginx, Composer, Node (for asset builds), Supervisor.
2. Server basics: creating a deploy user, `ufw` firewall rules, installing the stack.
3. Cloning the repo, `.env` config (same variables as the cPanel guide, cross-reference rather than repeat
   verbatim), `composer install --no-dev --optimize-autoloader`, `npm ci && npm run build`, migrations.
4. Example Nginx server block for this app (public root, PHP-FPM socket).
5. Supervisor config for a **real long-running** `queue:work` process — contrast explicitly with the cPanel
   guide's cron-based worker, since a VPS removes that throughput ceiling.
6. Scheduler via cron or systemd timer.
7. SSL via Let's Encrypt/certbot.
8. Registering both webhooks, same as the cPanel guide.
9. A short note on future headroom this environment unlocks that shared hosting can't (Reverb/websockets,
   Redis, Horizon) — informational, not instructions to set them up now.

### Both guides
- Every command block is copy-pasteable as written — no placeholder syntax left unexplained (`yourdomain.com`
  etc. clearly marked as things to replace).
- `docs/setup-cpanel.fa.md` and `docs/setup-vps.fa.md` — full Farsi translations; commands, paths, and env
  var names stay in their original form.

### Tests
- None (documentation task).

### Explicitly Out of Scope
- No Docker/Sail-based production deployment guide — Sail is documented elsewhere as the local dev setup;
  these two guides are for real hosting.
- No CI/CD pipeline setup (GitHub Actions deploy-on-push, etc.) — deployment mechanics only.

### Code Rules
- Follow `CLAUDE.md`'s documentation conventions if any exist; otherwise standard Markdown, no external
  assets.

Work only on this task.

## Phase 12 Task 3: Application flow document (users, creators, admins)

### Goal
One document walking through how the platform actually works from three perspectives, reflecting the real
built behavior — including the timed-session and AI-approval mechanics, which are easy to under-explain
because they're the least conventional parts of the product.

### Before starting
- Only start once Phase 12 Tasks 1–2 are done.
- Re-read `prompts/main-addendum-2.md` §2.7 (timed sessions) and §2.8 (AI approval) — these are the two
  flows most likely to confuse a first-time reader if under-explained; give them real worked examples, not
  just a schema description.

### What to Build

**`docs/user-flows.md`**, three sections:

1. **Participants** — discovering a challenge (invite link, or the public channel listing), joining (free
   slot or spending coins/an invite credit), the channel-gate requirement, checking in each period for each
   `proof_type` including a walked-through example of a timed/stepped session (start → wait → step → wait →
   end, with what the bot shows at each point), what a freeze does and when it's consumed automatically vs.
   spent, what the Mini App status screen shows (freezes used/remaining, streak, periods total), and buying
   coins with Stars or Bale Pay when out of free slots.
2. **Creators** — designing a challenge (period type, proof type or step design, visibility, approval mode),
   the AI-approval setup specifically (how the suggested criteria works, what happens if their edited
   criteria gets flagged), linking a channel/group and what each toggle (`post_checkin_announcements`,
   `post_daily_leaderboard`, `share_proof_media`) actually does, and reviewing manual-queue proofs.
3. **Admins** — the admin panel surface: tuning `Setting`-backed economy values (coin prices, invite
   thresholds, AI confidence threshold), moderating challenges/users, the AI-approval audit log and how to
   override a decision (and what that override does via `ReverseCheckIn`), payment/invite audit views.

Include a small Mermaid diagram per section (GitHub renders Mermaid natively in Markdown) — a simple sequence
or flow diagram, not an exhaustive state machine.

- `docs/user-flows.fa.md` — full Farsi translation; keep any diagram node labels bilingual or provide a
  parallel Farsi-labeled diagram if that reads more clearly than translating labels inline.

### Tests
- None (documentation task).

### Explicitly Out of Scope
- No API reference (endpoints, request/response shapes) — this is a conceptual flow document, not technical
  API docs.
- No developer-facing content (that's the README + `CLAUDE.md`) — this document is for people using or
  administering the platform, not building it.

### Code Rules
- Follow `CLAUDE.md`'s documentation conventions if any exist; otherwise standard Markdown. Verify every
  Mermaid block actually renders (check syntax) before finishing.

Work only on this task.
