# Project Goal — Phase 19 (Mini App: Coins, Creating, Checking In, Joining, Reviewing)

Continuation of the earlier goal files. Same repo, same conventions, same `CLAUDE.md`. Drives Phase 19 only.

## Before the first run
Phases 1–18 are ✅ (confirm in `prompts/progress.md`; Phase 18 "The economy closes its loop" is the last
entry). Phase 19 is a **defect-fix phase**: five issues from real use of the deployed Mini App, raw at
`prompts/issues.md:23-27`. Every one is reproduced by reading the code — the task file names the file and line
for each. Several tasks **modify** already-tested code (Phase 17's Mini App surface, Phase 18's slot economy,
Phase 14's media pipeline) — those originals must be green before you touch them. Run the suite once, clean,
before Task 1.

## North Star (definition of done)
1. **The Mini App header shows a real number.** `AuthController` hands the SPA a user with `coins`, read from
   the ledger, and one resource is the only place that shape is written down.
2. **A creator can see their own challenge whether or not they joined it** — in the list, on the detail screen,
   with their stats when they have them and an honest not-joined state when they do not. A stranger's view does
   not widen by one field.
3. **A user can join from the Mini App**, and a user with no slot is told the price and their balance rather
   than "no".
4. **The bot offers the join the second the challenge exists.** Creating still does not enrol; the confirmation
   now says so and carries the button that does it.
5. **Check-in works in the Mini App for `button` and `text_autogen`** — including the per-participant phrase,
   shown only to the participant it belongs to — and sends media-proof users to the bot rather than telling
   them to go there.
6. **A challenge can be created in the Mini App**, with parity to the bot wizard: the simple flow, timed
   sessions with a step designer, and AI approval criteria through the same injection gate and the same
   fallback to manual.
7. **A creator can review proofs in the Mini App** — a queue across everything they own and a pending section
   on each challenge, with the media actually rendered, approving and rejecting through `ReviewCheckIn`.
8. **The verdict retires the buttons.** Whichever surface decides, the notification's inline keyboard goes away,
   best-effort and logged, without failing the decision.
9. Every regression bar in a task file is met, and `sail composer ci:check` passes wholesale.

## Operating loop
As the earlier goal files: orient from `progress.md` + `git log`, query graphify before writing code, implement
per `CLAUDE.md`, test with Pest, commit per green task, record, repeat.

## Build order (strict)
- **1 → 2 → 3 → 4** — the coins resource, then visibility of owned challenges, then joining, then the bot's
  join offer. 3 needs 2's not-joined detail screen to put its button on; 4 is the same decision reached from
  the other surface and is easiest to verify once 3's action path is known good.
- **5 independent of 6–7.** Check-in and creation touch different endpoints and different screens. Do 5 first —
  it is one endpoint and it unblocks the most real use.
- **6 → 7** — the API create path, then the timed-session and AI layers on top of it. 7 extends 6's request
  object; do not start 7 with 6 uncommitted.
- **8 → 9** — review, then the button retirement. 9 adds a caller inside 8's endpoints and must see them in
  place. 8 must be complete *without* 9 (the task says so explicitly), so a stalled 9 never blocks it.
- If context runs short, stop after a green task and update `progress.md`. Tasks 1–5 are the user-visible
  defects; 6–9 are the larger additions. Do not begin 6 with 1–5 unfinished.

## Baked-in decisions — do NOT stop to ask about these
- **Creating still does not enrol the creator.** `CLAUDE.md` and `JoinChallenge`'s docblock both settle it, and
  the creator pays a join-slot like anybody else. The fix is a visible, cheap join (Tasks 3 and 4), never an
  auto-join, and never a silently created `ChallengeParticipant`.
- **Coins are read through `CoinLedger::balanceFor()`, never a `users` column**, and one resource serves both
  `/auth` and `/me`. The hand-built copy that drifted is the bug; a one-line patch to `AuthController` would
  leave the next field to drift the same way.
- **A stranger keeps the uniform 404.** Ownership widens what the *owner* sees and nothing else. Every endpoint
  re-resolves the actor from the bearer token; a route id is a lookup key, never an authorization.
- **Media check-in stays in the bot** — the issue says so in as many words. The Mini App links out through
  `openTelegramLink` with a URL it got from the API, and the SPA never uploads a file. No `Telegram.WebApp`
  upload path, no multipart endpoint.
- **Every surface calls the same Action.** `CreateChallenge`, `JoinChallenge`, `SubmitCheckIn`, `ReviewCheckIn`,
  `ScreenApprovalCriteria`, `ValidateChallengeStepDesign`, `IssueCheckInPhrase`. The API adds transport,
  resources and Form Requests — never a rule.
- **`expected_phrase` is per participant per period.** It may appear only on the token owner's own unsettled
  row, never in a listing, never for another participant. Pin it with a test that fails on a leak.
- **The verdict retires the buttons by editing the keyboard, not by deleting the message.** The creator keeps
  the proof they were sent. Best-effort and logged; no retry queue; no second source of truth beyond
  `check_ins.review_message_id`.
- **No JS test framework** (settled in Phase 17). The SPA work is covered from PHP — endpoint tests, lang-key
  parity, the options payload — with manual acceptance in a real Telegram client recorded in `progress.md`.
- **Every new string in both `lang/en/` and `lang/fa/`**, parity pinned by test. RTL wherever a surface is
  touched.
- **Nothing is hardcoded.** Slot prices, freeze defaults, media ceilings and AI flags come from `Settings`; a
  refusal quotes the configured price, so a test can change the setting and see the quote change.
- **`ReviewCheckIn` and `NotifyCheckInVerdict` are method-injected on the Mini App controllers**, for the same
  reason the admin controller does it: reading and rendering must not require a bot token.

## When you MAY stop and ask (only these)
- **Bale cannot clear an inline keyboard.** Check its API before implementing Task 9's method there. If it has
  no equivalent, implement the closest honest behaviour, document it in `progress.md`, and do **not** let it
  change Telegram's behaviour.
- A new money/authorization/security question genuinely not covered by `CLAUDE.md` or the addenda — in
  particular anything that would move coins outside `CoinLedger`.
- An endpoint in Task 8 that cannot authorize without trusting the client. There is no such design; if you
  think you have found one, stop and ask rather than shipping it.
- Everything else: choose the reasonable default, write it down in `progress.md`, keep going.

## Guardrails
- No Redis, no Filament; never hit a real Telegram/Bale/AI-provider endpoint in tests — `Http::fake()`
  everywhere. Install a catch-all fake before asserting an edit was *not* sent; `Http::assertNothingSent()` is
  vacuous with no fake installed.
- **Never store or log a raw bot token**, and never log `initData` raw.
- Telegram rate limits: ~1 msg/sec per chat. No task here adds a send to a fan-out. Task 9's edit is a second
  call to the same chat and rides on a human tap, not a broadcast.
- Money moves only through `CoinLedger`, inside a transaction, with an `idempotency_key`. Slots are spent by the
  Action that owns them; a controller never touches a balance.
- Context running low mid-task: commit + update `progress.md` before stopping; resume by pasting this file.
