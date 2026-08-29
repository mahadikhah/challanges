## Phase 15 Task 1: Scoring schema

### Goal
Add the schema for quantity-based scoring alongside the existing binary done/not-done model, with a fixed,
safe set of scoring strategies — never a creator-supplied formula.

### Before starting
- Query graphify: `graphify query "SettleCheckIn"`, `graphify query "ChallengeParticipant"`,
  `graphify query "CompleteCheckInSession"`.
- Read `prompts/main-addendum-5.md` §2.12 in full, especially why `scoring_strategy` is a fixed enum and not
  a free-form expression.

### What to Build
1. Add `scoring_type` to `challenges` (backed enum: `binary` default, `quantity`).
2. For `quantity` challenges: `target_value` (numeric), `unit_label` (localized string), `scoring_strategy`
   (backed enum — start with `proportional` only; design the enum so more strategies can be added later
   without a schema change), `base_points` (numeric), `quantity_partial_counts_as_done` (boolean, default
   `false`).
3. Add `reported_value` (nullable numeric) and `score` (nullable numeric) to whatever record
   `SettleCheckIn` already produces per settled period — locate and extend it, don't create a parallel table.
4. Add `total_score` to `ChallengeParticipant` (numeric, default 0).
5. Validation: `target_value` and `base_points` must be positive; `scoring_strategy` required when
   `scoring_type = quantity`, forbidden otherwise (mirror the `voice_max_seconds` required/forbidden pattern
   from Phase 9 Task 1).

### Tests (Pest, required)
- `binary` challenges are entirely unaffected — no new column is required or populated for them.
- `quantity` challenges require `target_value`, `unit_label`, `scoring_strategy`, `base_points`; rejected
  without them.
- Positive-number validation on `target_value`/`base_points`.
- Factories for both `scoring_type` values, including a `quantity` state with realistic pushup/plank-style
  values for use in Task 2/3/4 tests.

### Explicitly Out of Scope
- No scoring calculation logic — Task 2.
- No UI — Task 3.
- No leaderboard changes — Task 4.

### Code Rules
- Follow `CLAUDE.md`. Backed enums, no magic strings. Don't touch `SettleCheckIn`/`CompleteCheckInSession`
  logic in this task — schema only.

Work only on this task.

## Phase 15 Task 2: Scoring calculation & wiring

### Goal
Compute a score from a reported value using the challenge's configured strategy, and wire it into
`SettleCheckIn`/`CompleteCheckInSession` so `total_score` updates atomically alongside the existing streak
logic — without changing behavior for `binary` challenges at all.

### Before starting
- Query graphify: `graphify query "SettleCheckIn"`, `graphify query "CompleteCheckInSession"`,
  `graphify query "lockForUpdate"` (the row-locking pattern used elsewhere, e.g. `CoinLedger`).
- Read `prompts/main-addendum-5.md` §2.12, specifically the default answer to "does falling short still count
  as done."

### What to Build
1. `CalculateQuantityScore($strategy, $targetValue, $basePoints, $reportedValue)`: a pure function, one
   branch per `scoring_strategy` value. `proportional`:
   `score = round($reportedValue / $targetValue * $basePoints)`. No `eval`, no user-suppliable formula
   string, ever.
2. Extend `SettleCheckIn` (and `CompleteCheckInSession`'s call into it) to accept an optional
   `reportedValue`. When `challenge.scoring_type = quantity`:
   - `reportedValue >= target_value` → settle as done (unchanged existing path) and compute/store `score`.
   - `reportedValue < target_value` and `quantity_partial_counts_as_done = false` (default) → **not**
     settled as done; falls through to the existing miss/freeze engine unchanged. No score is recorded for a
     period that wasn't settled.
   - `reportedValue < target_value` and `quantity_partial_counts_as_done = true` → settled as done (streak
     continues) with the proportionally lower score stored.
3. On settlement, increment `ChallengeParticipant.total_score` by the computed score, inside the same locked
   transaction the streak update already uses — one lock, one commit, not two separate updates that could
   race.
4. `binary` challenges: confirm `SettleCheckIn` takes the identical code path it did before this task when no
   `reportedValue` is passed — this is the regression bar for this task.

### Tests (Pest, required)
- `CalculateQuantityScore` unit tests: exact target, above target (bonus), below target (reduced) — dataset-
  driven across a few target/base_points/reported_value combinations.
- Full settlement test: a quantity challenge with `quantity_partial_counts_as_done = false` and a below-
  target report is **not** settled as done and consumes a freeze/resets streak exactly as an ordinary miss
  would.
- Same setup with `quantity_partial_counts_as_done = true`: settled as done, streak continues, lower score
  recorded.
- `total_score` increments correctly and atomically under a concurrent-settlement test (mirror the coin-
  concurrency test's forked-writer approach).
- A `binary` challenge's settlement test suite (existing, from Domain Task 6) passes completely unchanged —
  explicit regression check, not assumed.

### Explicitly Out of Scope
- No new `scoring_strategy` values beyond `proportional` — the enum is designed to extend later, but only
  one is implemented now.
- No UI, no leaderboard changes.

### Code Rules
- Follow `CLAUDE.md`. `CalculateQuantityScore` is a pure function — no I/O, trivially unit-testable, kept
  separate from the Action that calls it.

Work only on this task.

## Phase 15 Task 2: Scoring calculation & wiring

### Goal
Compute a score from a reported value using the challenge's configured strategy, and wire it into
`SettleCheckIn`/`CompleteCheckInSession` so `total_score` updates atomically alongside the existing streak
logic — without changing behavior for `binary` challenges at all.

### Before starting
- Query graphify: `graphify query "SettleCheckIn"`, `graphify query "CompleteCheckInSession"`,
  `graphify query "lockForUpdate"` (the row-locking pattern used elsewhere, e.g. `CoinLedger`).
- Read `prompts/main-addendum-5.md` §2.12, specifically the default answer to "does falling short still count
  as done."

### What to Build
1. `CalculateQuantityScore($strategy, $targetValue, $basePoints, $reportedValue)`: a pure function, one
   branch per `scoring_strategy` value. `proportional`:
   `score = round($reportedValue / $targetValue * $basePoints)`. No `eval`, no user-suppliable formula
   string, ever.
2. Extend `SettleCheckIn` (and `CompleteCheckInSession`'s call into it) to accept an optional
   `reportedValue`. When `challenge.scoring_type = quantity`:
   - `reportedValue >= target_value` → settle as done (unchanged existing path) and compute/store `score`.
   - `reportedValue < target_value` and `quantity_partial_counts_as_done = false` (default) → **not**
     settled as done; falls through to the existing miss/freeze engine unchanged. No score is recorded for a
     period that wasn't settled.
   - `reportedValue < target_value` and `quantity_partial_counts_as_done = true` → settled as done (streak
     continues) with the proportionally lower score stored.
3. On settlement, increment `ChallengeParticipant.total_score` by the computed score, inside the same locked
   transaction the streak update already uses — one lock, one commit, not two separate updates that could
   race.
4. `binary` challenges: confirm `SettleCheckIn` takes the identical code path it did before this task when no
   `reportedValue` is passed — this is the regression bar for this task.

### Tests (Pest, required)
- `CalculateQuantityScore` unit tests: exact target, above target (bonus), below target (reduced) — dataset-
  driven across a few target/base_points/reported_value combinations.
- Full settlement test: a quantity challenge with `quantity_partial_counts_as_done = false` and a below-
  target report is **not** settled as done and consumes a freeze/resets streak exactly as an ordinary miss
  would.
- Same setup with `quantity_partial_counts_as_done = true`: settled as done, streak continues, lower score
  recorded.
- `total_score` increments correctly and atomically under a concurrent-settlement test (mirror the coin-
  concurrency test's forked-writer approach).
- A `binary` challenge's settlement test suite (existing, from Domain Task 6) passes completely unchanged —
  explicit regression check, not assumed.

### Explicitly Out of Scope
- No new `scoring_strategy` values beyond `proportional` — the enum is designed to extend later, but only
  one is implemented now.
- No UI, no leaderboard changes.

### Code Rules
- Follow `CLAUDE.md`. `CalculateQuantityScore` is a pure function — no I/O, trivially unit-testable, kept
  separate from the Action that calls it.

Work only on this task.

## Phase 15 Task 3: Scoring UI — creator config & participant input

### Goal
Let a creator configure quantity scoring when designing a challenge, and let a participant report their
value at check-in (simple flow) or session end (timed flow), through the bot and Mini App.

### Before starting
- Query graphify: `graphify query "create-challenge wizard"`, `graphify query "CompleteCheckInSession"`,
  `graphify query "Mini App check-in"`.
- Read `prompts/main-addendum-5.md` §2.12.

### What to Build
1. Extend the creation wizard: when the creator picks `scoring_type = quantity`, prompt for `target_value`,
   `unit_label`, `base_points`, and `quantity_partial_counts_as_done` (default off, explain in one line what
   turning it on does). `scoring_strategy` defaults to `proportional` without asking, since it's the only
   one implemented — don't expose a choice of one.
2. Simple-flow check-in: after the existing proof step (button/text/image/voice/video) for a `quantity`
   challenge, prompt for the numeric value ("How many {unit_label}?") and pass it through to `SettleCheckIn`
   via the extended signature from Task 2.
3. Timed-session flow: prompt for the value once, at the "end" step, passed into `CompleteCheckInSession`.
4. Participant-facing confirmation message shows the computed score, not just "done" (e.g. "Nice — 45
   pushups, that's 150 points").
5. Mini App: if the existing check-in submission surface (Phase 5) exists, extend it with the same numeric
   input; confirm its current shape before adding a parallel field.

### Tests (Pest, required)
- Wizard: a `quantity` challenge cannot be created without `target_value`/`unit_label`/`base_points`; a
  `binary` challenge is created without ever being asked for them.
- Simple-flow check-in for a `quantity` challenge correctly captures the reported value and shows the scored
  confirmation message.
- Timed-session end step correctly captures the value once and only once (not re-prompted per intermediate
  step).
- `Http::fake()` the bot API throughout; assert the exact confirmation message content for at least one
  above-target and one below-target case.

### Explicitly Out of Scope
- No leaderboard display of scores — Task 4.
- No exposing `scoring_strategy` as a creator choice (only one exists).

### Code Rules
- Follow `CLAUDE.md`. Reuse the existing wizard/`BotConversation` state machine; every new string through
  i18n.

Work only on this task.

## Phase 15 Task 4: Leaderboard integration (modifies Phase 8)

### Goal
Rank `scoring_type = quantity` challenges by `total_score` in creator-chat leaderboards instead of streak,
without changing `binary` challenge leaderboards at all.

### Before starting
- Query graphify: `graphify query "PostDailyLeaderboard"`, `graphify query "leaderboard"` (Phase 8 Task 2's
  actual implementation).
- Read `prompts/main-addendum-5.md` §2.12 "Leaderboard integration" and Phase 8's original leaderboard spec
  (`prompts/main-addendum-2.md` §2.6) together — this task modifies existing, tested code.

### What to Build
1. In the existing daily/on-demand leaderboard query (Phase 8 Task 2): branch on `challenge.scoring_type` —
   `binary` sorts by current streak descending (existing, unchanged behavior); `quantity` sorts by
   `total_score` descending.
2. Leaderboard post copy: `quantity` challenges show score (and unit context, e.g. "1,240 pts") instead of
   streak count; `binary` challenges are formatted exactly as before.
3. Check-in announcements (also Phase 8 Task 2): for `quantity` challenges, include the period's score in the
   announcement text alongside the existing participant-name/period-number content.

### Tests (Pest, required)
- **Regression:** existing Phase 8 `binary`-challenge leaderboard and announcement tests pass unchanged.
- A `quantity` challenge's daily leaderboard ranks participants by `total_score`, not streak — construct a
  fixture where the two orderings would differ and assert the score ordering wins.
- Check-in announcement for a `quantity` challenge includes the period's score; for a `binary` challenge it
  does not (unchanged format).

### Explicitly Out of Scope
- No changes to the idempotency, rate-limiting, or privacy-gating (`share_proof_media`, `proof_is_public`)
  mechanics from Phase 8 — this task only changes ranking and display content.
- No Mini App leaderboard/score display — note as a follow-up if wanted, not built here.

### Code Rules
- Follow `CLAUDE.md`. Smallest change that adds the branch correctly — don't restructure Phase 8's posting
  jobs beyond what's needed for the ranking/content change.

Work only on this task.
