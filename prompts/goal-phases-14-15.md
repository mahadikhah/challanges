# Project Goal — Phases 14–15 (Video/Voice Proof + Admin-Gated AI, Scoring)

Continuation of the earlier goal files. Same repo, same conventions, same `CLAUDE.md`. Drives Phase 14 and
Phase 15 only.

## Before the first run
Check `prompts/progress.md` or `prompts/progress-phase-*.md`. If Phases 1–13 are not all ✅, finish those first. Phase 14 Task 2 specifically
**modifies** Phase 10's behavior (tightens AI-approval to admin-gated), and Phase 15 Task 4 **modifies**
Phase 8's leaderboard logic — both need the originals in place and green before they can be safely changed.

## North Star (definition of done)
1. Phase 14: `voice_approval`/`video_approval` proof types and `video` step input exist with enforced
   duration/size caps and scheduled media pruning; AI-approval availability is admin-gated globally and per
   media type while creator-authored criteria remains unchanged; voice AI review works (native or
   transcribe-then-evaluate); video AI review works only where the environment genuinely supports it, and
   says so plainly where it doesn't; capture and manual-review UI covers both media types in both flow types.
2. Phase 15: `scoring_type = quantity` challenges compute and accumulate score via a fixed, safe strategy
   enum; falling short of target is a miss by default (streak-affecting), with an explicit opt-in for partial
   credit that keeps the streak; leaderboards rank quantity challenges by score, binary challenges unchanged.
3. Every regression bar stated in the task files is actually met: Phase 10's existing AI-approval tests,
   Phase 9's cross-routing test, Domain Task 6's binary settlement tests, and Phase 8's leaderboard tests all
   still pass unchanged.
4. `sail composer ci:check` passes wholesale.
5. `prompts/progress.md` records: which voice/video AI path was used (native vs transcribe/frame-sampling),
   whether video AI-approval is actually available in the target environment, and confirmation that the
   admin-gating tightening in Phase 14 Task 2 was applied as intended.

## Operating loop
Same as the earlier goal files — orient from `progress.md` and `git log`, query graphify before writing new
code, spec from the task file, implement per `CLAUDE.md`, test with Pest, refresh graphify, commit per green
task, record in `progress.md`, repeat.

## Build order (strict)
Phase 14: Task 1 (schema) → Task 2 (admin-gating retrofit, full Phase 10 regression) → Task 3 (voice) →
Task 4 (video) → Task 5 (capture/review UI). Phase 15: Task 1 (schema) → Task 2 (calculation/wiring, full
Domain Task 6 regression) → Task 3 (UI) → Task 4 (leaderboard, full Phase 8 regression). Phase 14 and Phase
15 don't depend on each other and can run in either order, but don't interleave their tasks — finish one
phase's task sequence before starting the other's.

## Baked-in decisions — do NOT stop to ask about these
- **Admin-only AI availability, creator-owned criteria content** — the split in
  `prompts/main-addendum-5.md` §2.11 is final; don't relitigate which half is admin-controlled.
- **`scoring_strategy` is a fixed enum, never a creator-supplied formula** — not up for relaxation for
  convenience.
- **Falling short of `target_value` is a miss by default** (`quantity_partial_counts_as_done = false`).
- **Video AI-approval requires a genuine capability check** — if neither a native-video provider nor
  `ffmpeg` is available, the toggle stays off. Don't build a fake frame-sampling path against an environment
  that doesn't actually support it.
- **Every regression bar named above is non-negotiable** — a task modifying existing code is not done until
  the code it modified still passes its original tests.

## When you MAY stop and ask (only these)
- A new money/authorization/security question genuinely not covered by `CLAUDE.md` or the addenda.
- The `ai-provider-kit-headless` skill exposes neither native audio/video input nor a usable transcription
  path for a given deployment — note the gap in `progress.md`, leave the corresponding admin toggle
  unavailable, and continue with the rest of the phase.
- Everything else: choose the reasonable default, write it down, keep going.

## Guardrails
- No Redis, no Filament, never hit a real Telegram/Bale/AI-provider/Bale-Pay endpoint in tests.
- Every new string (bot, admin panel, Mini App) goes through i18n; RTL applies wherever touched.
- If context runs low mid-task, commit + update `progress.md` before stopping — resume by pasting this file
  again.
