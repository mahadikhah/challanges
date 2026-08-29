# Phase 14 — Video/Voice Proof (progress ledger)

Task-by-task record, appended as each task goes green. Format follows the earlier
per-phase ledgers: what was built, where the judgment calls were, what was left for later.

---

## Task 1 — Video/voice proof schema & retention (`feat(proofs)`, 2026-08-29)

**Status: complete. `sail composer ci:check` green (1434 passed, 4591 assertions).**

### What was built

- `ProofType` gains `voice_approval` and `video_approval`; `StepInputType` gains `video`.
  Both fold into the existing switches through one new predicate each: `ProofType::
  isMediaApproval()` now covers all three media kinds (so review/reversal/public-proof/
  file-expectation rules apply unchanged), and `ProofType::expectsDuration()` names the
  pair that makes a challenge-level cap mandatory.
- `challenges.proof_media_max_seconds` / `proof_media_max_size_kb` (nullable ints).
  **Required** when the proof type `expectsDuration()` **or** the flow is a timed
  session with a video step; validated ≥ 1 and ≤ the admin ceiling; caps sent for a
  non-recording challenge degrade to null (same treatment as a stray `custom_period_days`).
- Three new Settings under a `proofs` group: `proof_media_max_seconds` (default 300),
  `proof_media_max_size_kb` (default 20 480 KB), `proof_media_retention_days` (default 90).
  Admin UI + en/fa labels arrive free via the SettingKey registry.
- Submission gates, all before storage: `SubmitCheckIn::uploadVoice()/uploadVideo()`
  (new entry points mirroring `uploadPhoto`, deliberately without the AI-verdict call)
  throw `CheckInRejection::MediaTooLong` / `MediaTooLarge`; `AdvanceCheckInStep` throws
  `SessionRejection::VideoTooLong` / `MediaTooLarge` for video steps and for any
  recording's `media_size_kb` over the challenge cap. Voice *steps* keep their per-step
  cap (Phase 9 regression untouched); image proof stays cap-free.
- `PruneProofMedia` action + `challenges:prune-proof-media` command, scheduled daily.
  Deletes the stored file of a **decided** submission past the retention window and
  nulls `proof_path`, keeping status/reviewer/timestamps. Decided = check-in status in
  {approved, rejected, missed, frozen} with decided-at `reviewed_at ?? updated_at`;
  step submissions are pruned only when their session has left `in_progress`. Re-runs
  are free; a row whose file already vanished is still cleared so it is not revisited.
- Bot copy (en+fa) for every new refusal reason; the wizard's proof-type and step-input
  choices are pinned to the bot-completable set so the new enum cases cannot produce an
  unusable challenge before Task 5.

### Judgment calls

- **Caps are challenge-level, not per-step** — a challenge records one duration/size
  pair for every recording it accepts (addendum 5's own framing); voice steps keep
  their per-step `voice_max_seconds` because that cap predates the pair and is already
  enforced.
- **Missing size measurement is not a refusal**: `sizeKb = null` passes (the surface's
  pre-download check is then the only size gate); duration is always known from the
  payload and always enforced.
- **Rejected check-ins count as decided for pruning**: a rejected row can only be
  resubmitted while its period is open, and no period outlives the retention window.
- **Public-proof gate widened with the media set**: voice/video join image in
  `supportsPublicProof()` — same shape of submission, same opt-in toggle.

### Tests

`tests/Feature/Domain/ProofMediaCapsTest.php` (enum semantics; creation acceptance and
ceiling/refusal/degradation; caps-at-submission over duration and size for both entry
points, verified to leave no row behind; video-step design requiring caps; video-step
submission over challenge cap with its carried numbers) and
`tests/Feature/Domain/PruneProofMediaTest.php` (decided-past-window → file gone, record
kept intact; awaiting-review never pruned; inside-window untouched; rollover verdicts
decided at `updated_at`; step media follows its session; idempotent re-runs; missing-file
clearing; scheduled command). `SettingsPanelTest` updated for the registry's 15 → 18 keys.

### Left for later tasks

- Task 2: admin gating of AI review (`ai_review_enabled`-style setting).
- Tasks 3–4: voice/video AI review, environment-capability-aware.
- Task 5: bot/Mini App capture surfaces and the review UI — the only place the new
  proof types become user-visible.
