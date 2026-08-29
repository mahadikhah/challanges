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

---

## Task 2 — Admin-gated AI approval settings, retrofit (`feat(ai)`, 2026-08-29)

**Status: complete. `sail composer ci:check` green (1447 passed, 4663 assertions).**

### What was built

- Four new boolean Settings, all defaulting **false**: `ai_approval_globally_enabled`
  and `ai_approval_allowed_{image,voice,video}`. **This is the deliberate tightening
  of Phase 10 the spec asked us to confirm**: image AI-approval was creator-self-service
  once criteria passed screening; now every media type is admin-opt-in, per media type,
  and image is merely the first allowed.
- The two-switch rule (global **and** per-type) lives in one class, `App\Services\Ai\
  AiApprovalGate::allows(ProofType)`, fed by a new `ProofType::aiApprovalSetting()`
  mapping. Three consumers, no drift:
  - **`CreateChallenge`** — an `approval_mode = ai` the deployment has not allowed is
    *refused* (`InvalidArgumentException`, clear reason), not degraded: silently
    switching to manual would fill creators' queues with challenges they never
    volunteered to review. The media-type precondition itself generalised from
    `=== ImageApproval` to `isMediaApproval()`, so voice/video ai mode is creatable
    the moment Tasks 3–4 make it meaningful.
  - **`ApplyAiVerdict`** — a media type withdrawn after creation routes new submissions
    to the manual queue without calling any provider; the challenge's `approval_mode`
    row is history and stays `ai`.
  - **`CreateChallengeWizard`** — the who-reviews question is skipped when manual is
    the only mode left (one button is not a choice), and a stale `ai` tap is refused
    with a new en/fa line saying review stays with the creator.
- Admin panel: a new **AI approval** group — the four toggles plus
  `ai_approval_confidence_threshold`, which had been registry-only until now and
  finally joins the panel. Each per-type toggle carries a read-only capability note:
  image answers from the real provider-account rows (active + configured account on
  the proof-moderation capability), voice/video honestly read **"not yet checked"**
  (`null`) until Tasks 3–4 ship their review paths. `SettingsController::current()`
  also gained its missing boolean arm — it had been routing booleans to
  `Settings::integer()`, unexploded only because no boolean setting existed before.
- `ScreenApprovalCriteria`/`SuggestApprovalCriteria`: **untouched**, per spec. No
  creator-visible path exposes provider/model/threshold values beyond the admin panel.

### Judgment calls

- **Refuse, don't degrade, at creation** — a caller offering AI review the platform
  never promised is a bug to surface, unlike a stray parameter on a working challenge.
- **Runtime gate in `ApplyAiVerdict`** (not strictly demanded by the task) — "admin
  controls whether AI approval exists at all" must survive an admin flipping a switch
  mid-challenge; without this, withdrawal would only stop *new* challenges.
- **Boolean transport coercion in the controller only** — form-encoded checkboxes
  arrive as `"1"`; the request validated it *is* a boolean, the controller converts,
  and `Settings::set()` keeps its strict no-coercion stance for everything else.

### Tests

New `tests/Feature/Ai/AiApprovalGateTest.php` (defaults off; both-switches rule;
per-type independence; creation refused with global off whatever the flags; image
allowed while voice refused under image-only permissions; withdrawal routes a real
submission to the manual queue with zero provider calls). Two wizard-surface tests in
`CreateChallengeWizardTest` (question skipped → challenge lands manual; stale ai tap
refused). `SettingsPanelTest` grows the registry 18 → 23 with the gate keys asserted
and a toggle-update + honest-capability test. Existing Phase 10 suites
(`ApprovalCriteriaTest`, `ProofModerationTest`, wizard ai-mode tests) pass with their
assertions unchanged; their fixtures enable the gate in `beforeEach` — the documented,
intentional accommodation of the tightening.

### Left for later tasks

- Task 3: voice AI review through the generalized `ReviewProofWithAi`; feeds the
  voice capability readout.
- Task 4: video AI review, environment-capability-aware; feeds the video readout and
  must make the video toggle honestly disableable when neither path exists.
- Task 5: bot/Mini App capture surfaces and review UI.


## Task 3 — AI review for voice proof (`feat(ai)`, 2026-08-29)

**What shipped.** Voice proof joins image in AI review — on both call sites, through
one shared verdict router. `ReviewProofWithAi` is now media-type-aware; a voice
recording is **transcribed first, then judged as a transcript** — verified against the
vendored `laravel/ai` SDK that no catalog driver accepts audio as a chat attachment
(only Gemini/OpenRouter do, and they are not in `AiDriverCatalog`), while a first-class
`Transcription` pipeline exists. The path taken is recorded per decision.

- `AiReviewPath` enum (`attachment` | `transcript`) + nullable `review_path`,
  `transcript` columns on `ai_approval_decisions` (nullable deliberately: Phase 10's
  image rows predate the distinction).
- `BuildProofModerationPrompt`: `systemPrompt(ProofType)` (voice gets its own
  platform-authored system prompt — criteria AND transcript both tagged untrusted
  data, plus a "a garble is not a lie" mercy clause for STT noise) and
  `transcriptPrompt()` with `<criteria>` + `<transcript>` fences. What the participant
  *said* is an injection surface and gets the same fence discipline as creator
  criteria.
- `ReviewProofWithAi`: the STT call runs **inside the chain's attempt closure** — same
  account, same budget reservation, same rotation. An empty transcript throws to fail
  the attempt rather than asking a verdict about a recording nobody heard. Voice rows
  get `review_path = transcript` + truncated transcript; image rows get `attachment`.
- `AiTextClient::transcribe()` + `LaravelAiTextClient` via
  `Transcription::of(StoredAudio)`; `AiDriverCatalog::supportsTranscription()/
  transcriptionModelFor()`; `AiProviderConfig::toConnectionConfig()` registers
  `models.transcription.default = whisper-1` for `openai_compatible` (the provider
  throws without it).
- **Timed sessions on ai-mode challenges** (`AdvanceCheckInStep`): the final step's
  last proof-bearing submission opens the check-in, attaches the media, sets
  `Submitted`, and hands it to the one shared `ApplyAiVerdict` **outside the
  transaction** (multi-second HTTP under a row lock is how every worker deadlocks).
  Approve → session completes through the ordinary `CompleteCheckInSession` (its
  approve is an idempotent no-op on the settled row); reject/fallback → session
  bookkeeping only, *without* the bundled approval, because `CompleteCheckInSession`
  would approve a row the verdict deliberately left open. `SettleCheckIn` and
  `CompleteCheckInSession` themselves are untouched, per the code rules.
- `ProofType::supportsAiReview()` (image+voice yes, video no until Task 4) — separate
  from the admin gate because "admin allows" ≠ "platform built". `ApplyAiVerdict`
  routes allowed-but-unbuilt (video) to the manual queue, not an error: a
  participant's submission must not fail for an admin's optimism.
- `SubmitCheckIn::uploadRecording()` now runs the shared verdict for voice (video is
  Task 4). `SessionStepFlow` says `bot.session.submitted_for_review` (en+fa) when a
  completed session's check-in is still undecided, instead of claiming "confirmed".
- Admin settings voice capability readout answers from real rows: a moderation
  account whose driver can transcribe. Video stays `null` ("not yet checked") until
  Task 4.

### Judgement calls

- **Transcribe-then-evaluate, not native audio** — recorded in code docblocks: no
  catalog driver maps audio into a chat message, so there is no "send audio if the
  provider accepts it" path to build. `AiReviewPath` records which path a decision
  took so a future native-audio driver can be adopted per account.
- **STT inside the chain** — shares the account's budget reservation and rotation; an
  outage at transcription rotates accounts exactly like a chat-leg outage.
- **Two endings for the final step** — the ordinary completion approves; the
  AI-reviewed one must not, since the verdict may reject. Rejection keeps the session
  honestly Completed (the steps were genuinely run) with the check-in resubmittable
  until the period closes.

### Tests

New `tests/Feature/Ai/VoiceProofModerationTest.php` (8) — simple call site: STT-first
prompt shape (`VOICE_SYSTEM_PROMPT`, `<transcript>` fence, no `image_url`,
`review_path = transcript`, transcript stored); spoken-injection fenced; approve =
manual-approval downstream state (streak, `reviewed_by` null); reject + resubmit;
below-threshold → manual queue (`FellBack`); STT failure → manual queue with zero
`chat/completions` requests; voice gate off → zero provider calls and zero decision
rows; video gate flipped early → manual queue. New
`tests/Feature/Domain/SessionAiReviewTest.php` (7) — session call site: approve
settles + completes through the ordinary path; reject lands resubmittable with the
session honestly closed; below-threshold and provider-outage → manual queue; gate
withdrawn mid-run → manual queue with zero provider calls; manual-mode sessions
byte-for-byte unchanged; multi-step designs review the last proof-bearing submission
(not the button tap). Updated `SettingsPanelTest` (voice capability now answers `false`
with no accounts / `true` with a transcription-capable one) and `AiProviderRotationTest`
(`models.transcription.default` is an intentional, non-leaked key in connection
configs).

**Trap found while testing:** the Domain concurrency suites use `DatabaseTruncation`,
which truncates every table *without re-seeding* — and the `ai_capabilities` rows are
migration-seeded. Any test running after them that expects the seeded row dies;
the Ai/ suites survive only because alphabetical discovery puts them first. The two
files that look the capability up by key now use `firstOrCreate` (the migration's own
idempotent shape) instead of `firstOrFail`.

### Left for later tasks

- Task 4: video AI review, environment-capability-aware (native video bytes vs
  ffmpeg frame sampling); feeds the video capability readout; the video toggle must
  disable honestly when neither path works.
- Task 5: bot/Mini App capture & review UI for voice/video.

## Task 4 — AI review for video proof, environment-capability-aware

Commit `bf076b2`. Prompts `prompts/phase-14.md` L145-199.

**The leg is the environment's to choose.** A video review needs a way to get the
recording to a model, and there are exactly two: a provider whose gateway maps video
bytes into a chat attachment, or ffmpeg on the host turning the recording into stills.
Verified against the vendored SDK rather than assumed: only Gemini's and OpenRouter's
`MapsAttachments` handle `StoredVideo`/`Base64Video`/`LocalVideo` — and neither driver
is in `AiDriverCatalog`. So the native path is unreachable with today's catalog, and
frame sampling is the only live leg. `AiDriverCatalog` carries the truth as a
per-driver `supports_video` flag on `DRIVERS` (all false today); the day a
video-mapping driver joins the catalog, its flag flips and the native path lights up
alongside the sampling one. (It is a flag on the driver row rather than a
`VIDEO_DRIVERS` list because PHPStan correctly proves `in_array` against a constant
empty array can never be true — the flag keeps the lookup honest and analysable.)

**The combination rule: one call, one verdict, over the set.** `VideoFrameSampler`
extracts four evenly-spaced frames (ffprobe reads the duration; `ffmpeg -ss <t>
-frames:v 1` grabs each still into a scratch temp dir that a `finally` removes) and
`ReviewProofWithAi` sends **one** provider request with all frames attached as
`LocalImage`s — they are absolute paths that exist only for this call, which is exactly
what `LocalImage` (not `StoredImage`) is for. One budget reservation, one decision row,
one locked `{approved, confidence, reason}` schema — a video is one submission, not
four images. The video system prompt says so explicitly: the frames are slices of one
recording, not independent submissions; a frame that shows less is a moment between
actions; judge the set, not the worst frame. `AiReviewPath::Frames` records the leg
taken.

**Sampling runs inside the chain's attempt closure** — same account lease, same budget
reservation, same account rotation as the prompt itself. An unreadable video therefore
behaves like a provider outage: the attempt fails, rotation gets its chance, and the
exhausted chain falls back to the manual queue with a decision row whose `approved` is
null and zero `chat/completions` calls made.

**The toggle must not lie.** `VideoReviewCapability` answers one question — can this
environment review a video at all — with a reason: `no_provider` (no active, configured
moderation account) or `no_toolchain` (accounts exist but no native-video driver and no
ffmpeg; `FfmpegDetector` caches its probe for 15 minutes so the settings page isn't
spawning processes per request). Three places enforce it independently: the settings
panel shows the readout under the toggle and greys the checkbox when unavailable; the
update endpoint *refuses* to turn the gate on (`ValidationException`, message names
both remedies) — a switch that looks on and silently routes everything to the manual
queue is worse than no switch; and `ApplyAiVerdict` re-checks on every submission, so
a challenge created while the capability existed, or a host that lost ffmpeg since,
still routes to the manual queue rather than erroring.

**Trap: the router memoizes the controller, so constructor injection froze the first
detector swap.** The settings panel tests swap `FfmpegDetector` for a stub between two
requests in one test — and the second request kept the *first* capability object,
because Laravel memoizes the controller instance on the route object for the process's
lifetime (`spl_object_id` proved it). Moving `VideoReviewCapability` to **method
injection** on `index()`/`update()` resolves it per request from the live container.
Same lesson as the session-flow re-read: anything a test (or a deployment) swaps
mid-process must be resolved at call time, not captured at construction.

**Tests.** New `tests/Feature/Ai/VideoProofModerationTest.php` (9): the frame leg —
video system prompt, frame count stated in the user turn, criteria fenced, exactly
`FRAME_COUNT` image parts (counted from the decoded parts: one image part's JSON
spells `image_url` twice), `review_path = frames`, no transcript; approve = manual
downstream; reject + resubmit round-trip; below-threshold → `FellBack`; sampling
failure → manual queue with zero chat calls; environment cannot review → zero provider
calls and zero decision rows (gate enabled but ffmpeg gone — the runtime re-check is
what protects); the toggle refusal (`assertInvalid` + setting stays off, `forget()`
first because the `beforeEach` had enabled it); the panel readout across all three
states (`no_provider` / `no_toolchain` / available); and the timed-session video step
riding the same router to completion. ffmpeg presence is *always* a swapped stub
(`theEnvironmentHasFfmpeg`) — the suite's verdicts must never depend on whether the
container ships the binary. `SettingsPanelTest` now asserts video `available: false,
reason: no_provider` (deterministic: no accounts fires before ffmpeg is even asked).
`VoiceProofModerationTest`'s last test renamed to cover the neither-available case.

**Also fixed while here:** `ChallengeStepDesignTest`'s "refuses a timed-session
challenge with no steps at all" was a `->throws()` chain on a closure-less body — Pest
counted it incomplete and never ran it (pre-existing at HEAD, verified by stashing).
Given a closure, it passes for real: the validator does say "at least one step".

**Out of scope, as specified:** no bundling/installing ffmpeg (the panel names the
remedy instead), and no audio-track transcription fallback for video — a video whose
frames say nothing is a human's to watch.

### Left for later tasks

- Task 5: bot/Mini App capture & review UI for voice/video. Feeds on Task 3/4's
  review legs; needs video routed through `AdvanceCheckInStep`, review-queue preview,
  and the Mini App upload surface.

## Task 5 — Voice/video capture & review UI (`feat(bot)`, 2026-08-29)

What the last line of Task 4 needed: the surfaces. A `voice_approval` or
`video_approval` challenge now runs the same conversation the photo flow owns —
`AwaitingCheckInVoice`/`AwaitingCheckInVideo` join `ConversationState`,
`ConversationRouter` routes a message carrying the matching object into
`CheckInFlow::receiveVoice/receiveVideo`, and both funnel into one shared
`receiveRecording` body (the photo's mirror, plus the duration and byte size
the messenger itself measured riding through to `SubmitCheckIn::uploadVoice/
uploadVideo`, which refuse an over-cap recording *before anything is written*).

**Doctrine held: a cap refusal re-asks, it does not abandon.** An overlong or
oversized recording gets the `media_too_long`/`media_too_large` line with the
conversation still open — the same treatment a mismatched phrase gets — because
a shorter take is one message away. Everything else (state refusals) abandons;
infrastructure failures log and keep the flow open, exactly as the photo flow
already did.

**Video joined the cross-routing.** `SessionStepFlow::receiveMedia`'s `wants`
match now recognises `message.video` alongside photo and voice, submitting
`video_seconds` plus a `media_size_kb` derived from the payload's `file_size`
for either recording kind. One ordering test proves both legs: the video
message during an active video step routes to `AdvanceCheckInStep` (and its
caps — on the *challenge* row, not the step — are enforced), while the
`checkinChallenge` suite proves the same message type against a simple
`video_approval` challenge routes to the ordinary check-in handler.

**The queue is kind-aware from one source of truth.** `CheckIn::proofKind()`
derives `image|voice|video` from the stored path's extension — deliberately
*not* from the challenge-level `proof_type`, because a session's evidence
(Phase 9) need not match it. Three consumers read it: the queue row's
`proof_kind` (the panel renders native `<audio>`/`<video>` players instead of
the `<img>`), the creator's notification copy (`review_prompt_voice` points
them at the queue — `BotMessenger` has no `sendVoice`/`sendVideo` and inline
buttons stay image-only), and `NotifyCheckInVerdict`'s rejection copy, which
now names what to replace ("send another voice message", not "another photo").

**The proof route names what it streams.** `response()->file()` sniffs bytes,
and a real `.ogg` stub sniffs as octet-stream — so the browser would download
instead of playing. The route now picks the content type from the stored
extension's mime list, disambiguated by `proofKind()` (`.ogg` maps to both
`audio/*` and `video/*`; a voice kind takes the audio answer). Assertions
cover `audio/ogg` and `video/mp4`.

**Mini App untouched, on the spec's own terms.** The task said "extend the
existing check-in submission surface *if one exists*" — it does not; the Mini
App's `CheckInController` is tap-only with no upload endpoint. Building one
would be new-surface work the task explicitly scoped out ("no new Mini App
screens"), so nothing was extended and this line records why.

**Traps hit and closed:**

- **The caps are nullable with no factory default.** A recording-proof
  challenge created without explicit `proof_media_max_seconds`/`_size_kb`
  refuses *everything* (`(int) null === 0`). Tests pass caps explicitly
  (`recordingCaps()`: 120s / 4096 KB); production challenge creation goes
  through `CreateChallenge::assertMediaCaps`, which is not so permissive.
- **The lang keys had to be renamed, not added to.** `review_prompt` and
  `review_rejected` became `review_prompt_image`/`review_rejected_image`
  because the code interpolates the kind into the key — and the generic
  `review_prompt`/`_rejected` keys would sit alongside the `_voice`/`_video`
  ones as dead weight. Two existing test assertions followed the rename.
- **PHPStan caught the enum partition twice:** the wizard's `nextAfter` match
  needed the two new states in its wiring-bug arm, and
  `EconomySchemaTest`'s exhaustive dataset + partition counts needed the two
  new rows/columns. Both are the safety net doing its job.
- `SessionStepFlow::showStep` stated `:max` from `voice_max_seconds` for every
  media step — a video step's ceiling lives on the challenge, so the prompt
  printed an empty "up to  seconds". The `match` now picks by input type.

**Tests.** `CheckInFlowTest` grows a `recording proof` describe (6): prompt
with caps + state; voice stored + creator pointed at the queue; the video
mirror; overlong refused before storage with the flow still open *and* a
shorter take then accepted; oversized refused the same way; re-ask on text
where a recording was due. `TimedSessionBotTest` grows the video
cross-routing test (walk to the video step, assert the `step_video` prompt
carries the challenge's ceiling, send the video, session completes into an
approved check-in). `ReviewQueueTest` grows two: the queue lists a pending
voice and video with the kind that plays them (both content types asserted),
and approve-a-voice / reject-a-video both go through the shared action with
the streak arithmetic landing. Gate: **1482 passed**, Pint/PHPStan(0)/ESLint/
Prettier/tsc all green.
