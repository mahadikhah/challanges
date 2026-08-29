# Phase 15 — Scoring (addendum-5 §2.12)

## Task 1 — Scoring schema (`feat(scoring)`, 2026-08-29)

The schema for quantity scoring, nothing more: no calculation (Task 2), no UI
(Task 3), no leaderboard change (Task 4).

**Enums.** `ScoringType` (`binary` default | `quantity`) and `ScoringStrategy`
(`proportional` alone). A fixed strategy enum rather than a creator-supplied
formula is the addendum's non-negotiable: an expression evaluated server-side
is a code-injection surface for exactly the reason arbitrary formulas always
are. `ScoringStrategy::score()` already lives on the enum so "one branch per
strategy" is one place a new case must answer — a case without a branch is an
unhandled match, not a silent zero.

**Columns.** `challenges` grows the scoring block after `flow_type`
(`scoring_type` default binary, `target_value`/`unit_label`/
`scoring_strategy`/`base_points` nullable, `quantity_partial_counts_as_done`
default false). `check_ins` grows `reported_value` + `score` — on the record
`SettleCheckIn` already produces, not a parallel table. `challenge_participants`
grows `total_score` default 0. All nullable/defaulted because every binary
challenge, before and after this phase, must pass through untouched.

**Validation** (`CreateChallenge::assertScoring`) mirrors `assertMediaCaps`'s
required/forbidden line: the whole block for a quantity challenge (positives
only on target/base points, non-blank unit label ≤64 chars), *none* of it for a
binary one — forbidden, not ignored, because a target on a binary challenge is
a half-configured block waiting to surprise whoever flips the type later. The
one exception: `scoring_strategy` defaults to `proportional` rather than being
required — exposing a choice of one is not a choice, but the column still
records which arithmetic scored a period.

**Factory.** `ChallengeFactory::quantity()` writes pushup-shaped defaults
(30 reps / 100 pts / partial-not-done) so Task 2/3/4 arithmetic stays legible;
the base `definition()` now states `scoring_type => binary` explicitly.

**Tests.** Seven in `CreateChallengeTest`'s new `quantity scoring` describe:
the block recorded with the defaulted strategy; the partial-counts opt-in; the
binary regression bar (every scoring column empty); datasets for missing-part
and non-positive refusals; scoring-config-on-binary refused; the factory state
pinned. Gate: **1495 passed**, PHPStan 0.

## Task 2 — Scoring calculation & wiring (`feat(scoring)`, 2026-08-29)

The quantity decision lands inside `SettleCheckIn`, not beside it. `approve()`
takes an optional `reportedValue`; a new `scored()` decides the status inside
the participant lock (binary → plain `Approved`, exactly as before; quantity →
`Approved` when the report reaches `target_value` or the creator opted into
partials; otherwise the ordinary freeze/miss engine, untouched) and `scoreFor()`
asks the strategy for the points. `settle()` stores `reported_value` on the row
whatever the outcome — a miss by a whisker is still a fact — and `advance()`
adds the score to `total_score` inside the same locked write as the streak. The
status transition remains the single idempotency token for both counters, so a
replayed settlement can double-credit neither. `SubmitCheckIn::tap()` and
`CompleteCheckInSession::handle()` grow the pass-through parameter the surfaces
(Task 3) will feed.

**Traps worth remembering:**
- Freezes absorb misses; they never convert a below-target report into a done
  one. A concurrency fixture mixing over- and under-target reports must either
  opt into `quantity_partial_counts_as_done` or stay over target — otherwise the
  under-target half settles `Frozen` with no score, which is correct behaviour,
  not a bug. (Cost one debugging round to notice my own comment was the lie.)
- `ChallengeParticipantFactory` still defaults `freezes_total => 1`; any test
  asserting a streak *reset* must set it to 0 explicitly.
- The concurrency suite mirrors `CoinLedgerConcurrencyTest`: DatabaseTruncation
  (never RefreshDatabase — its wrapping transaction hides writes from the forked
  connections), `DB::disconnect()` before the fork, workers with their own
  connections, exit codes as results, and an `afterEach` cleanup because
  DatabaseTruncation truncates *before*, not after.

**Tests.** `QuantityScoringTest` (RefreshDatabase): the strategy as a pure
function (7-case dataset incl. uncapped-above-target and fractional rounding;
zero target refused), clears-the-bar (full score + total, uncapped bonus,
replay-scores-once), falls-short (freeze absorbed with no score, streak reset,
partial opt-in at half score), and the binary regression. The binary
regression *bar* is the rest of the Domain suite passing unchanged — 107/107
across StreakEngine/SubmitCheckIn/ReviewCheckIn/CheckInSessionFlow.
`QuantityScoringConcurrencyTest` proves against the real lock manager that
eight parallel settlements lose no score (exact total, streak 8) and a race on
one row scores exactly once. Gate: **1512 passed, 4 skipped**, PHPStan 0.

## Task 3 — Scoring UI: creator config & participant input (`feat(scoring)`, 2026-08-29)

**Value ordering is per proof type, and the ordering is the design.** Button and
the media proofs ask the number *first*: a media AI verdict settles the moment
the evidence is uploaded, so the number must already be on the row when the
verdict lands. Text asks the phrase first — the phrase proves presence, the
number is judged — and stashes the phrase in the conversation payload while the
value question is open, so a wrong-phrase retry keeps the answered number and
is one question, not two. A timed session asks the value **once, at the final
step only**: the final step's evidence is stashed in a short-lived
`AwaitingCheckInValue` conversation (payload flag `session: '1'`) while the
session row keeps its own state; `ConversationRouter` dispatches by that flag
to `SessionStepFlow::receiveValue` vs `CheckInFlow::receiveValue`. The stashed
step replays through the ordinary `AdvanceCheckInStep`, re-checking every gate.
A `TooEarly` on the session value keeps the conversation open (the answer is
good, the wait is not); other session refusals abandon it.

**Below-target verdicts had to be un-wedged, and that was the task's real
find.** Task 2's `approved()` guards treated "settled but not Approved" as the
rollover race and threw `AlreadySettled` — so a no-opt-in below-target report
surfaced as *"That period is already settled"* on the simple flow, and worse,
`CompleteCheckInSession` threw *inside its own transaction*, rolling back the
session's completion and leaving the session wedged open forever. Fixed by
distinguishing the two arrivals: a settlement that took writes the value this
call settled on, a rollover's row does not. `SubmitCheckIn::approved()`,
`ReviewCheckIn::approve()` and `CompleteCheckInSession` all carry that check;
the below-target outcome is then worded by `CheckInConfirmation` —
`bot.checkin.below_target_frozen` / `_missed` (en+fa) — rather than confirmed
as a check-in. Second find: the `ValueMissing` guard had to move out of the
shared `guardAwaitingVerdict()` into `approve()` only — in the shared guard it
blocked *rejection* too, and a rejection is precisely the creator's way out of
a valueless row (it reopens resubmission).

**SettleCheckIn's stored-value fallback** (Task 2) is what the review paths
score: `ReviewCheckIn::approve()` passes no value, and the row's own
`reported_value` — written by `SubmitCheckIn::submissionUpdate()` *before* any
AI verdict runs — is the evidence. `NotifyCheckInVerdict` shares
`CheckInConfirmation` with the flows via a `$base` parameter
(`bot.checkin.review_approved_scored`), so a verdict and a tap can share words
without string surgery.

**Guards.** `SubmitCheckIn::assertQuantityValue` refuses `ValueRequired` on all
five submission entry points (the last line behind the bot's question and the
Mini App's wire validation); `ApplyAiVerdict` routes a quantity row without a
value to the manual queue rather than auto-settling it as below-target.

**Mini App.** `ChallengeResource` gained the `scoring` block (null on binary —
that null *is* the SPA's signal to hide the input), `me.total_score`, and
`reported_value`/`score` on the check-in shape, numbers normalised at the wire
(decimal casts hand back strings). The one-tap screen collects the value only
when `scoring !== null` && button-proof.

**Traps worth remembering:**
- The wizard's `nextAfter` had to route `AwaitingTotalPeriods →
  AwaitingScoringType` *unconditionally*: branching on
  `$draft->scoringType()->isQuantity()` would skip the scoring questions
  forever, because the draft defaults Binary before the question is answered.
- Decimal columns: `$checkIn->score`/`total_score` assert as `'150.00'`, but
  JSON `assertJsonPath` compares numerically after the resource's casts (30,
  not 30.0).
- `soleBotMessage()` asserts *one message total*; multi-step walks use
  `lastBotReply()`. Re-ask replies append the prompt after the error line, so
  error assertions are `toContain`, not `toBe`.
- `latestBotMessage(N)` counts changed +1 in TimedSessionBotTest's two
  full-wizard walks (the scoring question), and `designerTypesTheSteps()` now
  answers `binary` after the period count.

**Tests.** Wizard `scoring branch` describe (7): question order + keyboard
dataset, binary never asked, quantity walk + answers recorded, created row
carries the design with defaulted strategy, incomplete quantity draft refused,
unreadable target/base-points re-asked; the end-to-end walk is now thirteen
answers. `CheckInFlowTest` `quantity scoring` (6): tap value-first + exact
`confirmed_scored` content, below-target partial exact content, unreadable
number re-ask, phrase-then-value settle, wrong-phrase retry keeps the value,
photo value-then-proof with verdict scoring the stored number (exact
`review_approved_scored`). `TimedSessionBotTest` `a quantity timed session`
(3): value asked at the final step only (intermediate asserted silent),
unreadable answer keeps the question and the row pending, below-target no-opt-in
settles frozen with exact `below_target_frozen` content. Domain: `SubmitCheckIn`
quantity describe (6, `ValueRequired` on every entry point), `ReviewCheckIn`
(3, ValueMissing + reject-still-works, stored-value scoring, approval under the
bar settles), `QuantityScoring` stored-value fallback (2). Mini App (4):
value_required on the wire, negative refused, settle + full `scoring`/`score`/
`total_score` response shape, binary carries `scoring: null`. `ChallengesSurfaceTest`'s
exact-shape assertion gained `scoring`/`total_score`. Gate: **1550 passed,
4 skipped**, PHPStan 0, lint/format/types clean.
