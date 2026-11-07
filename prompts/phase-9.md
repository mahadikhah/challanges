## Phase 9 Task 1: Timed/stepped challenge schema & design-time validation

### Goal
Add the schema for step-based, timed check-in sessions and enforce, at challenge design time, that a
session's minimum possible duration can't exceed the challenge's period length.

### Before starting
- Query graphify: `graphify query "period duration calculation"`, `graphify query "proof_type"`,
  `graphify query "image approval submission storage"`.
- Read `prompts/main-addendum-2.md` §2.7 in full before writing any migration.
- Locate Domain Task 4's period-duration calculation and Domain Task 6's proof-storage shape; reuse both. If
  either doesn't exist under the name assumed here, use whatever the codebase actually calls it and note the
  mapping in `prompts/progress.md`.

### What to Build
1. Add `flow_type` to `challenges` (backed enum: `simple`, `timed_session`), default `simple`. Existing
   challenges are unaffected.
2. Migration + model `ChallengeStep`: `challenge_id`, `order` (unsigned int, unique per challenge),
   `input_type` (backed enum: `button`, `image`, `voice`), `min_wait_seconds` (unsigned int),
   `voice_max_seconds` (nullable unsigned int, required when `input_type = voice`, forbidden otherwise —
   validate both directions), localized `label`.
3. Migration + model `CheckInSession`: `challenge_participant_id`, `challenge_period_id`, `status` (backed
   enum: `in_progress`, `completed`, `expired`, `abandoned`), `started_at`, `completed_at`,
   `current_step_order` (nullable). Partial unique index (or app-level `lockForUpdate` check) so only one
   `in_progress` row exists per (`challenge_participant_id`, `challenge_period_id`) at a time.
4. Migration + model `CheckInStepSubmission`: `checkin_session_id`, `challenge_step_id`, `submitted_at`, and
   whatever media-reference column shape the existing `image_approval` submissions use (mirror it exactly;
   do not invent a second storage convention).
5. A `ValidateChallengeStepDesign` rule/Action: given a challenge's `period_type`/custom length and a
   proposed step list, compute `sum(min_wait_seconds)` and reject if it exceeds the period's duration in
   seconds — using the *existing* period-duration calculation, not a reimplementation. Wire it into the
   challenge-creation Form Request when `flow_type = timed_session`.
6. Factories for all three new models, including a "valid design" factory state (steps summing to well under
   a daily period) and an "invalid design" state (steps summing over) for testing the validator.

### Tests (Pest, required)
- Step list with `sum(min_wait_seconds)` under the period length passes validation; over it fails, with a
  clear error naming the excess.
- `voice_max_seconds` required for `voice` steps, rejected as present for `button`/`image` steps.
- Only one `in_progress` `CheckInSession` can exist per (participant, period) — assert a second concurrent
  attempt fails or returns the existing row, not a duplicate (decide which in Task 2, but the schema-level
  constraint must hold here already).
- Datasets covering all six `period_type` values against the duration validator (reuse Domain Task 4's
  duration calc in the test, don't hardcode seconds-per-period in two places).

### Explicitly Out of Scope
- No session lifecycle Actions (`StartCheckInSession` etc.) — Task 2.
- No bot or Mini App UI — Task 2/3.
- No AI approval integration for `image`/`voice` steps yet — Phase 10, and voice is explicitly deferred there.

### Code Rules
- Follow `CLAUDE.md`. Backed enums, no magic strings, UTC storage.
- Don't touch `SettleCheckIn`/`RollOverPeriod` in this task — schema and validation only.

Work only on this task.


## Phase 9 Task 2: Session lifecycle Actions

### Goal
Implement start/advance/complete for timed sessions, feeding a successful completion into the existing
`SettleCheckIn` Action unchanged — a timed session is a new way to arrive at a settlement, not a new
settlement engine.

### Before starting
- Query graphify: `graphify query "SettleCheckIn"`, `graphify query "RollOverPeriod"`,
  `graphify query "idempotency key"`.
- Read `prompts/main-addendum-2.md` §2.7 and §3.6.
- Confirm `SettleCheckIn`'s exact signature/inputs before wiring `CompleteCheckInSession` into it.

### What to Build
1. `StartCheckInSession($participant, $period)`: validates `flow_type = timed_session`, no existing
   `in_progress` session for this (participant, period), period currently open. Creates the session
   (`started_at = now()`, `current_step_order` = first step). Locked/idempotent: a duplicate call (double-
   tapped "Start") returns the existing session rather than creating a second one.
2. `AdvanceCheckInStep($session, $step, $submission = null)`: rejects if `$step->order` isn't the session's
   `current_step_order` (defends against replayed/out-of-order callback data). Computes elapsed time since
   the previous step's `submitted_at` (or `started_at` for step 1) and rejects with the exact remaining
   seconds if `min_wait_seconds` hasn't passed. For `voice` steps, rejects if the submitted duration exceeds
   `voice_max_seconds` (read from Telegram's `voice.duration`, not client-reported) with a "resend shorter"
   message. On success: stores `CheckInStepSubmission`, advances `current_step_order`, or — if this was the
   last step — calls `CompleteCheckInSession`.
3. `CompleteCheckInSession($session)`: marks `completed_at`/`status = completed`, then calls the existing
   `SettleCheckIn` Action with whatever inputs it expects (adapt the session's data to that signature; do not
   change `SettleCheckIn` itself).
4. A scheduled sweep (`ExpireStaleCheckInSessions`), piggybacking the existing period-rollover scheduled
   command, marking `in_progress` sessions whose period has ended as `expired`. This is bookkeeping — confirm
   in a test that `RollOverPeriod`'s existing miss detection already ignores non-`completed` sessions with or
   without the sweep having run, and note that confirmation in `progress.md`.

### Tests (Pest, required)
- Full 3-step session: start → too-early advance rejected with correct remaining seconds → wait → advance
  succeeds → ... → last step completes the session and calls through to a settled check-in (assert the same
  streak/coin effects as a manual `SettleCheckIn` call would produce).
- Out-of-order step submission (submitting step 3's data while `current_step_order` is 1) rejected.
- Voice step over `voice_max_seconds` rejected without creating a submission row.
- Double "Start" is idempotent — one session, not two (concurrent/forked-writer test like Domain Task 3's
  coin-concurrency test).
- Session that never completes before its period ends: swept to `expired`, and the participant's period is
  correctly recorded as a miss by the existing engine (freeze consumed if available, streak reset otherwise —
  same assertions Domain Task 6 already has, just via this new path).

### Explicitly Out of Scope
- No AI approval of `image`/`voice` step submissions — manual review only for now (Phase 10 wires this in
  later without changing these Actions' public shape).
- No bot/Mini App UI (Task 3 / Phase 5).

### Code Rules
- Follow `CLAUDE.md`. `SettleCheckIn` is not modified — only called. If it can't accept a timed-session
  origin cleanly, add an adapter, don't fork the settlement logic.
- Every rejection path returns a reason the caller (bot handler) can turn into a specific user-facing
  message — no generic "invalid" errors.

Work only on this task.


## Phase 9 Task 3: Bot wizard + step UI

### Goal
Let a creator design a step list through a short linear bot wizard, and let a participant run a session
(start/advance/end, including image/voice steps) entirely through the bot.

### Before starting
- Query graphify: `graphify query "BotConversation"`, `graphify query "create-challenge wizard"`.
- Read `prompts/main-addendum-2.md` §2.7 "Scope decision" — bot-only, linear, no drag-and-drop.

### What to Build
1. Extend the existing challenge-creation wizard: when the creator picks `flow_type = timed_session`, loop
   "add a step" (input type → min wait → voice limit if applicable → label) until they choose "done," then
   run `ValidateChallengeStepDesign` (Task 1) and show the total minimum duration against the period length
   before confirming.
2. Participant-facing session UI: a "Start" inline button on the challenge's check-in prompt. Once started,
   show the current step's instructions and, once its `min_wait_seconds` has elapsed, the appropriate input
   (an inline "Next" button for `button` steps; a prompt to send a photo/voice message for `image`/`voice`
   steps). Before the wait elapses, show remaining time rather than nothing — including gracefully answering
   an early tap/message with "N seconds left," not a silent failure.
3. Route incoming photo/voice messages during an active session to `AdvanceCheckInStep`, not the ordinary
   `image_approval` check-in handler — the two flows must not collide when a challenge could conceivably have
   either.
4. On session completion, send a confirmation reusing the existing settled-check-in confirmation copy (don't
   fork a second success message).

### Tests (Pest, required)
- End-to-end via `Http::fake()`: creator designs a 2-step challenge through the wizard, a participant runs a
  full session via simulated bot updates, assert the final Telegram messages sent match expectations at each
  gate (early tap, correct wait, completion).
- A photo sent while an `image_approval` (non-timed) check-in is pending is routed to the existing handler,
  not `AdvanceCheckInStep` — and vice versa for a timed-session challenge — confirming the two flows don't
  cross-route.

### Explicitly Out of Scope
- No Mini App session UI — that's Phase 5 work; the API surface Task 2 built is sufficient for the bot alone
  right now.
- No editing an existing step design after a challenge has active participants — creation-time only.

### Code Rules
- Follow `CLAUDE.md`. Reuse `BotConversation` state machine conventions; every string through i18n.

Work only on this task.


