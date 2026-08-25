# Project Goal — Phases 8–10 (Creator Chats, Timed Challenges, AI Approval)

Continuation of `prompts/goal.md`. Same repo, same conventions, same `CLAUDE.md`. This drives only the three
new phases added by `prompts/main-addendum-2.md`; it does not touch Phases 1–7.

## Before the first run
Check `prompts/progress.md`. **If Phases 1–7 are not all ✅, stop and finish them with the original
`prompts/goal.md` first** — Phase 9 in particular calls the Phase 2 `SettleCheckIn`/`RollOverPeriod` Actions
directly and Phase 8 posts on top of Phase 3's bot core; building on an incomplete foundation will produce
work that has to be redone.

## North Star (definition of done)
1. Phases 8, 9, and 10 as specified in `prompts/main-addendum-2.md` §5 are implemented, task by task, per
   `task-08-01`/`08-02`, `task-09-01`/`09-02`/`09-03`, `task-10-01`/`10-02`.
2. `sail composer ci:check` passes wholesale, same as before.
3. The security-sensitive assertions in Task 10's test list — injection-attempt rejection, structured-output
   only, no model output ever used as anything but a display string — are all present and passing. These are
   not optional coverage; treat a missing one as a failed task, not a shortcut.
4. `prompts/progress.md` is updated with Phases 8–10 in the same format as 1–7, including any assumptions.

## Operating loop
Same as `prompts/goal.md` §"Operating loop" — orient from `progress.md` and `git log`, query graphify before
writing new code, spec from the task file, implement per `CLAUDE.md`, test with Pest, refresh graphify, commit
per green task, record in `progress.md`, repeat.

## Build order (strict)
Phase 8 → Phase 9 → Phase 10. Within Phase 9, Task 1 (schema) before Task 2 (actions) before Task 3 (UI).
Phase 10 depends on Phase 9's `image_approval`-shaped submission storage existing but not on Phase 9's
timed-session Actions — it can in principle run in parallel with Phase 9 if you want to split work, but
sequential is simpler and is the default.

## Baked-in decisions — do NOT stop to ask about these
- **Dual verification for creator chats** — both bot-is-admin and creator-is-admin, always, no exceptions.
- **`share_proof_media` cannot be true on a `proof_is_public = false` challenge** — enforce server-side.
- **Timed sessions settle through the existing `SettleCheckIn`, never a parallel engine.**
- **AI approval mode defaults to `manual`; a challenge only becomes `ai` after criteria exists and has passed
  screening.** The moderation system prompt is always platform-authored; creator text is always passed as
  delimited data. This is not up for relaxation for convenience during implementation.
- **Voice-step AI approval is out of scope for this pass** — manual review only for voice, even on `ai`-mode
  challenges, until a future task adds it.
- **Low-confidence or failed AI calls always fall back to the existing manual queue** — never guess, never
  silently auto-approve on error.

## When you MAY stop and ask (only these)
- A new money/authorization/security question genuinely not covered by `CLAUDE.md` or
  `prompts/main-addendum-2.md`. The injection-defense structure, the dual-admin check, and the
  reversal/idempotency requirements are already decided — implement them, don't re-ask.
- The `ai-provider-kit-headless` skill/package doesn't expose something a task assumes (e.g. no structured-
  output mode). If so, note the gap in `progress.md`, implement the narrowest safe workaround (e.g. a strict
  JSON-schema validation pass on a text response) and continue — don't block waiting for the package to
  change.
- A required secret (AI provider key/account) genuinely blocks progress and can't be stubbed — same as
  `goal.md`, this should essentially never happen since provider calls are mockable in tests.

## Guardrails
- Same hard nos as `goal.md`: no Redis, no Filament, never hit real Telegram or a real AI provider in tests.
- New chat/session/AI-approval UI still supports dark/light and RTL, and every string goes through i18n.
- If context runs low mid-task, commit + update `progress.md` before stopping, same as before — resume by
  pasting this file again.
