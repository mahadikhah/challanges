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
