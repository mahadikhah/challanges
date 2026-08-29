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
