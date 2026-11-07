## Phase 14 Task 1: Video/voice proof schema & retention

### Goal
Add `voice_approval` and `video_approval` as proof types and `video` as a step input type, with duration/
size caps and a pruning policy so media doesn't unboundedly consume disk on shared hosting.

### Before starting
- Query graphify: `graphify query "proof_type"`, `graphify query "image_approval submission storage"`,
  `graphify query "voice_max_seconds"`, `graphify query "telescope:prune"`.
- Read `prompts/main-addendum-5.md` §2.11 in full, especially "Storage — a real constraint on shared hosting."
- Locate the existing `image_approval` submission storage model (Domain Task 6 / Phase 9) before adding
  columns — mirror its shape for voice/video rather than inventing a new one.

### What to Build
1. Extend the `proof_type` backed enum with `voice_approval`, `video_approval`. Extend
   `ChallengeStep.input_type` (Phase 9) with `video`.
2. Add to `challenges` (nullable, required when the relevant proof_type/step input_type is used):
   `proof_media_max_seconds`, `proof_media_max_size_kb`. Both are **capped by an admin `Setting`** —
   a creator's chosen value can be lower than the admin ceiling but never higher; validate server-side.
3. `Setting`: `proof_media_retention_days` (default 90).
4. Scheduled `PruneProofMedia` command (added to the existing scheduler): for submissions with a final
   decision (approved or rejected) older than the retention window, delete the stored media file but keep the
   submission's decision/score record intact. Never prune a submission still awaiting manual review.
5. Storage: reuse whatever disk/filesystem convention `image_approval` already uses for voice/video files —
   don't introduce a second storage configuration.

### Tests (Pest, required)
- New `proof_type`/`input_type` enum values accepted where the existing ones are; existing `image_approval`/
  `image` behavior is completely unaffected (regression check).
- A challenge's `proof_media_max_seconds`/`_size_kb` above the admin ceiling is rejected at creation/edit.
- A submission over the configured duration or size is rejected before storage, with a clear "resend
  shorter/smaller" message.
- `PruneProofMedia`: a decided submission past the retention window has its media file removed but its
  decision record remains queryable; an undecided (pending manual review) submission is never pruned
  regardless of age; a decided submission within the window is untouched.

### Explicitly Out of Scope
- No AI review logic — Tasks 3/4.
- No admin-gating of AI availability — Task 2.
- No bot/Mini App capture UI — Task 5.

### Code Rules
- Follow `CLAUDE.md`. Backed enums, no magic strings. Don't touch `SettleCheckIn` or the existing
  `image_approval` review Action in this task — schema and retention only.

Work only on this task.

## Phase 14 Task 2: Admin-gated AI approval settings (retrofit)

### Goal
Retrofit Phase 10's AI-approval mode so availability is controlled exclusively by system admins — globally
and per media type — while creators keep authoring their own per-challenge criteria text. This modifies
already-built, already-tested Phase 10 code; the existing image-approval AI test suite must still pass
unchanged.

### Before starting
- Query graphify: `graphify query "approval_mode"`, `graphify query "SuggestApprovalCriteria"`,
  `graphify query "ScreenApprovalCriteria"`.
- Read `prompts/main-addendum-5.md` §2.11 "What 'admin-only AI settings' actually means" — the split there
  (admin controls availability/mechanism, creator controls criteria content) is the actual spec; don't over-
  or under-apply it.
- Read Phase 10 Task 1's actual implementation before changing anything.

### What to Build
1. `Setting`: `ai_approval_globally_enabled` (default `false`), and per media type:
   `ai_approval_allowed_image`, `ai_approval_allowed_voice`, `ai_approval_allowed_video` (each default
   `false`). Note this **changes** Phase 10's original behavior, where image AI-approval was creator-self-
   service once criteria passed screening — confirm in `prompts/progress.md` that this tightening is
   intentional and applied.
2. Server-side enforcement (Form Request/Action, not just UI): a challenge can only be set to
   `approval_mode = ai` for a given proof type if `ai_approval_globally_enabled` **and** the matching
   `ai_approval_allowed_{type}` are both true. Reject otherwise with a clear reason, and fall back the
   creator's UI option list to only show `ai` for types currently allowed.
3. Creator-facing criteria flow (`SuggestApprovalCriteria`, `ScreenApprovalCriteria`) is **unchanged** —
   still per-challenge, still creator-editable, still screened for injection. Confirm no path lets a creator
   see or change provider/model/confidence-threshold settings — those remain admin `Setting`s only, exactly
   as Phase 10 Task 1 originally scoped them; this task is about the on/off gate, not those values.
4. Admin panel: extend the existing settings UI with the new toggles, and surface (read-only) whether each
   media type's underlying provider capability is actually available in this deployment (Task 4 will feed
   video's capability check into this — stub it here as "unknown"/"not yet checked" if Task 4 hasn't run).

### Tests (Pest, required)
- **Regression:** every existing Phase 10 image-approval AI test still passes.
- With `ai_approval_globally_enabled = false`, no challenge of any media type can be set to `ai` mode, even
  if a per-type flag is `true`.
- With global `true` but `ai_approval_allowed_voice = false`, a voice challenge cannot be set to `ai` mode
  while an image challenge (allowed) can.
- A creator's edited criteria text still goes through `ScreenApprovalCriteria` exactly as before — this task
  does not touch that flow's behavior.
- Admin settings UI correctly reflects and updates all four new toggles.

### Explicitly Out of Scope
- No voice/video AI review implementation — Tasks 3/4.
- No changes to the screening or criteria-suggestion logic itself.

### Code Rules
- Follow `CLAUDE.md`. This is a retrofit — prefer the smallest change that adds the gate correctly over
  restructuring Phase 10's existing code.

Work only on this task.

## Phase 14 Task 3: AI review for voice proof

### Goal
Extend AI-assisted approval to voice submissions — both simple `voice_approval` check-ins and timed-session
voice steps — reusing Phase 10's entire trust boundary, gated by Task 2's `ai_approval_allowed_voice`.

### Before starting
- Query graphify: `graphify query "ReviewProofWithAi"`, `graphify query "AiApprovalDecision"`,
  `graphify query "AdvanceCheckInStep"`.
- Read `prompts/main-addendum-5.md` §2.11 "Voice and video AI review — verify, don't assume."
- Read the `ai-provider-kit-headless` skill's docs for whatever audio-input or speech-to-text capability its
  configured provider(s) actually expose — confirm before choosing native-audio vs transcribe-then-evaluate.

### What to Build
1. Generalize `ReviewProofWithAi` to accept a media type (`image`|`voice`|`video`) rather than assuming
   image — same platform-authored system prompt, same delimited untrusted-criteria handling, same locked
   `{approved, confidence, reason}` schema, just a different content payload.
2. Voice path: if the provider accepts audio input directly, send it. Otherwise, transcribe via the kit's
   STT capability (or a documented fallback it points to), then run the transcript through the existing
   text-based structured-output evaluation. Record which path was used on the `AiApprovalDecision` row.
3. Wire into **both** call sites: a simple `voice_approval` submission, and a timed-session voice step
   submission (`AdvanceCheckInStep`, Phase 9) — one shared review call, not two implementations. Respect the
   `ai_approval_allowed_voice` gate (Task 2) in both.
4. Confidence-threshold fallback to manual review, audit logging, and `ReverseCheckIn` on override all apply
   unchanged, per Phase 10.

### Tests (Pest, required)
- High-confidence approve/reject via voice apply immediately with the same downstream effects as manual
  approval (mirror Phase 10's assertions, voice-flavored).
- Below-threshold confidence and provider errors route to manual review, same as image.
- Both call sites (simple check-in and timed-session step) produce equivalent, correctly-gated behavior.
- `ai_approval_allowed_voice = false` blocks AI review for voice even if image AI-approval is enabled.
- `Http::fake()`/mock the provider kit throughout — never call a real provider in tests.

### Explicitly Out of Scope
- No video — Task 4.
- No changes to the confidence-threshold, audit, or reversal mechanics themselves.

### Code Rules
- Follow `CLAUDE.md`. `SettleCheckIn` and `CompleteCheckInSession` are not modified — this task only feeds
  a decision into paths that already exist.

Work only on this task.

## Phase 14 Task 4: AI review for video proof (environment-capability-aware)

### Goal
Extend AI-assisted approval to video submissions, only where the deployment environment can actually support
it — prefer a provider that accepts video bytes directly; treat frame-sampling as a fallback that requires
confirming `ffmpeg` (or an equivalent) is actually available, which it typically is not on shared hosting.

### Before starting
- Query graphify: `graphify query "ReviewProofWithAi"` (as generalized in Task 3).
- Read `prompts/main-addendum-5.md` §2.11 "Voice and video AI review" and "Storage" subsections.
- Read the `ai-provider-kit-headless` skill's docs for native video-input support in its configured
  provider(s). Separately, check whether `ffmpeg` is available in the actual target deployment
  environment(s) — do not assume it is just because it's available in the local/CI container.

### What to Build
1. A capability check, run at admin-settings-load time and cached briefly (not per-request): can this
   deployment support video AI review at all? True only if either (a) the configured provider accepts video
   bytes directly, or (b) `ffmpeg` is confirmed present for frame extraction **and** a viable evaluation path
   exists for the extracted frames. Surface this as a read-only status in the admin settings UI from Task 2
   ("Video AI review: available" / "not available in this environment — `ffmpeg` not found and no configured
   provider accepts video directly").
2. If capable via (a): send video bytes directly through the generalized `ReviewProofWithAi`, same trust
   boundary as image/voice.
3. If capable only via (b): extract a small, fixed number of evenly-spaced frames (e.g. 3–5), evaluate them
   as a set against the criteria using the existing image-review path, and combine into a single decision
   (e.g. approve only if a majority of frames support it) — document the combination rule used.
4. If neither is available: `ai_approval_allowed_video` cannot be turned on — the admin Setting toggle
   itself should be disabled/greyed with the reason shown, not merely have a runtime error if someone flips
   it anyway.
5. Wire into both simple `video_approval` submissions and timed-session video steps, same as Task 3's voice
   wiring — one shared review call.

### Tests (Pest, required)
- With a mocked "provider accepts video" capability: high-confidence approve/reject work end to end, same
  downstream effects as image/voice approval.
- With a mocked "no native video, `ffmpeg` available" capability: frame-extraction path runs against a fixture
  video, produces a combined decision, and is logged with which path was used.
- With a mocked "neither available" capability: `ai_approval_allowed_video` cannot be enabled — assert the
  Setting update is rejected or the option is unavailable, and no video submission is ever routed to AI
  review in this configuration (falls to manual).
- `Http::fake()`/mock the provider kit and any frame-extraction call throughout.

### Explicitly Out of Scope
- No attempt to bundle or install `ffmpeg` — detect its presence, don't provision it.
- No audio-track transcription fallback for video in this task (that's a further fallback layer; ship the
  two paths above first and note the gap in `progress.md` if neither applies to a given deployment).

### Code Rules
- Follow `CLAUDE.md`. Be honest in the admin UI about what is and isn't actually available — a toggle that
  looks enabled but silently falls back to manual review everywhere is worse than no toggle at all.

Work only on this task.

## Phase 14 Task 5: Bot/Mini App capture & review UI for voice/video

### Goal
Let participants submit voice/video proof through the bot (and Mini App where applicable) and let creators
review pending voice/video submissions in their manual review queue, without disturbing the existing
image-approval or timed-session routing rules.

### Before starting
- Query graphify: `graphify query "image approval review queue"`, `graphify query "AdvanceCheckInStep"`,
  `graphify query "photo sent while pending check-in"` (the cross-routing rule from Phase 9 Task 3).
- Read `prompts/main-addendum-5.md` §2.11.

### What to Build
1. Bot: accept incoming voice/video messages for challenges with the matching `proof_type`, validating
   duration/size against Task 1's caps before accepting (reject with "resend shorter/smaller" otherwise, per
   Task 1). For `flow_type = timed_session`, extend the existing cross-routing rule from Phase 9 Task 3 so
   video messages during an active session route to `AdvanceCheckInStep`, not the simple check-in handler,
   the same way photo/voice already do.
2. Creator manual-review queue: extend the existing image-approval review UI (bot and/or admin panel,
   whichever Phase 6/10 actually built) to preview and decide on pending voice/video submissions — audio
   playback / video playback in whatever surface the queue lives in.
3. Mini App: if the existing Mini App API surface (Phase 5) already exposes check-in submission, extend it
   to accept voice/video uploads the same way it presumably already handles image uploads — confirm the
   existing shape before adding a parallel endpoint.

### Tests (Pest, required)
- A voice/video message sent during an active timed-session step routes to `AdvanceCheckInStep`; the same
  message type sent for a simple `voice_approval`/`video_approval` challenge routes to the ordinary check-in
  handler — confirming no cross-routing regression (mirror Phase 9 Task 3's existing test for this).
- An oversized/overlong submission is rejected before storage with a clear message.
- The manual review queue correctly lists and can approve/reject a pending voice and a pending video
  submission.

### Explicitly Out of Scope
- No changes to AI review logic (Tasks 3/4) — this task is capture and manual-review UI only.
- No new Mini App screens beyond extending the existing check-in submission surface, if one exists.

### Code Rules
- Follow `CLAUDE.md`. Every new UI string goes through i18n; RTL applies to any admin/Mini App surface
  touched.

Work only on this task.
