# Project Goal — Phases 11–12 (Bale Multi-Platform, Documentation)

Continuation of `prompts/goal.md` and `prompts/goal-phases-8-10.md`. Same repo, same conventions, same
`CLAUDE.md`. Drives Phase 11 (Bale) and Phase 12 (documentation) only.

## Before the first run
Check `prompts/progress.md`. **If Phases 1–10 are not all ✅, stop and finish them first** with the earlier
goal files. Phase 11 Task 1 refactors code from Phases 3, 8, and 9 — it needs to exist and be stable first.
Phase 12 documents the *complete* system — starting it early would mean documenting features that don't
exist yet, or missing ones added later.

## North Star (definition of done)
1. Phase 11: the messenger platform abstraction is in place, Bale is a fully working second platform through
   the same Actions Telegram uses, and Bale Pay credits the same `CoinLedger` Stars does.
2. Phase 12: `README.md`, `docs/setup-cpanel.md`, `docs/setup-vps.md`, `docs/user-flows.md` all exist and
   are accurate to the actual built system, each with a `.fa.md` sibling.
3. `sail composer ci:check` passes wholesale, including the **entire pre-existing test suite** — Phase 11
   Task 1 in particular is only done when nothing from Phases 3, 8, or 9 regressed.
4. `prompts/progress.md` updated with Phases 11–12, including recorded answers to the Bale-parity questions
   in Task 11.2's "before starting" checklist and any documentation gaps deliberately left as known
   limitations.

## Operating loop
Same as the earlier goal files — orient from `progress.md` and `git log`, query graphify before writing new
code, spec from the task file, implement per `CLAUDE.md`, test with Pest, refresh graphify, commit per green
task, record in `progress.md`, repeat.

## Build order (strict)
Phase 11 Task 1 (refactor, full regression pass) → Task 2 (Bale bot) → Task 3 (Bale Pay) → Phase 12 Task 1
(README) → Task 2 (setup guides) → Task 3 (flow doc). Do not start Task 2 of Phase 11 until Task 1's full
regression requirement is actually green — this is the one place in the whole roadmap where skipping ahead
risks silently breaking already-shipped behavior.

## Baked-in decisions — do NOT stop to ask about these
- **Separate accounts per platform, no auto-linking** between a Bale and a Telegram identity for the same
  person. This is deferred, documented as a known limitation in Phase 12, not solved here.
- **Bale feature parity is verified, not assumed** — Task 11.2 has an explicit checklist to confirm before
  writing code; where Bale lacks something Telegram has, degrade gracefully and record the decision.
- **`bale-payments` skill is authoritative for Task 11.3** — don't guess at Bale Pay's request/verify
  contract if the skill documents it.
- **No license is chosen in the README** — state that one should be added, don't pick one.
- **No screenshots** — use marked placeholders, don't fabricate image references.

## When you MAY stop and ask (only these)
- A new money/authorization/security question genuinely not covered by `CLAUDE.md` or the addenda. The
  idempotency, locking, and platform-selection patterns already established are not up for re-litigation —
  apply them to Bale, don't re-ask whether they're needed.
- Bale's Bot API turns out to lack something with no reasonable degraded equivalent (e.g. no webhook
  mechanism at all, which would be fatal to the whole phase) — this is the one case worth actually stopping
  for rather than working around, since it would change the phase's feasibility, not just its shape.
- A required secret (Bale bot token, Bale Pay credentials) genuinely blocks progress and can't be stubbed —
  same as before, this should essentially never block since all provider calls are mockable in tests.

## Guardrails
- Same hard nos as before: no Redis, no Filament, never hit real Telegram, Bale, or Bale Pay in tests.
- Phase 11 Task 1's regression requirement is not negotiable — if it's not fully green, the phase is not
  progressing, full stop.
- Every new Bale-facing string goes through i18n; every Phase 12 document ships with its Farsi sibling in the
  same commit, not as a follow-up.
- If context runs low mid-task, commit + update `progress.md` before stopping — resume by pasting this file
  again.
