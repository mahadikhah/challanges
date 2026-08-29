# Main Prompt Addendum 5 — Video/Voice Proof, Admin-Gated AI Settings, Scoring

Append after `prompts/main-addendum-4.md`. §2.11–2.12 for spec, §3.10 for architecture, §5 gets Phases 14–15,
§7 gets new open questions. Assumes Phases 1–13 exist as built. Phase 14 **modifies** already-built Phase 9
and Phase 10 code; Phase 15 modifies already-built Phase 8 code. Both need full regression passes on what
they touch, same discipline as the Phase 11 messenger refactor.

---

## 2.11 Video & voice proof, and admin-only AI settings

Two new `proof_type` values, `voice_approval` and `video_approval`, structurally identical to the existing
`image_approval`: participant submits media, it's reviewed per `approval_mode`. `video` also becomes a valid
`ChallengeStep.input_type` (Phase 9), alongside the existing `button`/`image`/`voice`.

### What "admin-only AI settings" actually means

Read literally, "only system admins configure AI approval" could mean admins would have to write the
approval criteria for every challenge in the system — which doesn't scale and defeats the point of a creator
customizing their own challenge ("a plate of food with visible vegetables" is meaningless as a global
setting). The split that actually works:

- **Admin-only:** whether AI approval exists at all (global on/off), whether it's available **per media
  type** (`image`/`voice`/`video` each independently toggleable — video and voice are newer and riskier than
  image, so an admin may allow image AI-review while keeping voice/video manual-only), which provider/model
  is used, and the confidence threshold. All `Setting`-backed, as Phase 10 already started.
- **Still creator-controlled:** the challenge's own `approval_criteria` text (screened for injection per
  Phase 10 §2.8, unchanged) and the choice of `manual` vs `ai` **for a media type the admin has allowed**.

This is a **retrofit** onto Phase 10's existing image-approval flow, not a parallel system: the same
`approval_mode`, screening, confidence-threshold fallback, audit log, and `ReverseCheckIn` mechanics apply
uniformly across image/voice/video. Generalize `ReviewProofWithAi` to take a media type rather than writing
three parallel Actions.

### Voice and video AI review — verify, don't assume

Same posture as Bale's feature parity (§2.9): confirm what the `ai-provider-kit-headless` skill's configured
providers can actually do before designing around a capability.

- **Voice:** if a provider accepts audio input directly, use it. If not, transcribe (via the kit's
  speech-to-text capability if it has one) then evaluate the transcript text with the same structured-output
  moderation pattern already built for images.
- **Video:** strongly prefer a provider that accepts video bytes directly. Frame-sampling as a fallback
  requires `ffmpeg` (or an equivalent), which **most shared cPanel hosts do not provide** — confirm its
  actual availability in the target environment before building around it. Where neither a native-video
  provider nor `ffmpeg` is available, `ai_approval_allowed_video` simply cannot be turned on in that
  deployment — the admin UI should say so, not pretend the capability exists.

### Storage — a real constraint on shared hosting

Video proof, submitted regularly by many participants, can fill a shared host's disk quota fast. Enforce a
strict duration **and** file-size cap (admin-tunable, with a hard ceiling the creator's own setting can't
exceed) and add a scheduled pruning job — delete the raw media for already-decided submissions after a
`Setting`-backed retention window (default 90 days), keeping the decision record (approved/rejected, score)
forever. Same pattern as Telescope's pruning in Phase 13.

---

## 2.12 Scoring system

Some challenges need more than done/not-done: a quantity reported each period, scored relative to a target.
"30 pushups" is the minimum; 60 earns bonus score, fewer earns less.

`Challenge.scoring_type`: `binary` (default, unchanged) | `quantity`. For `quantity`:

- `target_value` (numeric), `unit_label` (localized — "pushups", "seconds").
- `scoring_strategy` — a **fixed enum of safe strategies with numeric parameters**, never a free-form,
  creator-supplied formula. A creator-authored expression evaluated server-side is a code-injection surface
  for exactly the reason arbitrary formulas always are; a small strategy enum (starting with `proportional`:
  `score = round(reported_value / target_value * base_points)`, uncapped so exceeding target scores above
  `base_points`) covers the stated use case without that risk.
- `base_points` — score awarded at exactly `target_value`.
- `quantity_partial_counts_as_done` (default `false`) — the open product question below.

**Does falling short of `target_value` still count as a completed period?** Defaulting to **no** — reaching
`target_value` remains the bar for a settled/done period, identical to how `image_approval` etc. already
work; falling short is a miss, handled by the existing streak/freeze engine unchanged. If a creator wants
"any positive effort keeps the streak alive, just scored lower," they opt in via
`quantity_partial_counts_as_done = true`. Ship the safer default; don't silently assume the permissive one.

`reported_value` is collected **in addition to** whatever `proof_type` already requires — a quantity
challenge might still be `button` (the number *is* the check-in) or `image_approval` (photo plus a number).
For `flow_type = timed_session` (Phase 9), the value is reported once, at session completion.

Score accumulates on `ChallengeParticipant.total_score`, updated atomically alongside the existing streak
update in `SettleCheckIn` — same row-locking discipline as the coin ledger.

### Leaderboard integration

Phase 8's leaderboard currently ranks by streak only. For `scoring_type = quantity` challenges it must rank
by `total_score` instead — this is a **modification** to Phase 8's existing `PostDailyLeaderboard` logic, not
new isolated code.

---

## 3.10 Architecture notes

- **Video/voice approval** reuses Phase 10's entire trust boundary (platform-authored prompt, delimited
  criteria, locked schema, confidence fallback, audit, reversal) — only the media-handling and the
  admin-gating around *availability* are new.
- **Scoring** is an additive accumulator alongside the existing binary engine, not a replacement — a
  `binary` challenge is completely unaffected; ship this without touching any code path a `binary` challenge
  exercises.

## 5. Roadmap — Phases 14–15

**Phase 14 — Video/voice proof & admin-gated AI settings.** Task 1: schema + retention/pruning. Task 2:
retrofit Phase 10's AI-approval gating to be admin-controlled per media type (regression-tested against
existing image-approval tests). Task 3: voice AI review. Task 4: video AI review (environment-capability-
aware). Task 5: bot/Mini App capture and review UI for voice/video.

**Phase 15 — Scoring.** Task 1: schema. Task 2: scoring calculation + wiring into `SettleCheckIn`/
`CompleteCheckInSession`. Task 3: bot/Mini App/creator-wizard UI for quantity input and configuration. Task 4:
leaderboard integration (modifies Phase 8).

## 7. New open product questions

1. **`quantity_partial_counts_as_done` default** — shipping `false`. Revisit once there's real usage data on
   which creators want.
2. **Video AI-approval on shared hosting specifically** — may simply be unavailable there depending on the
   configured provider. Document this plainly in the admin UI and in `docs/user-flows.md`, don't hide it.
3. **Per-media-type AI defaults** — all three (`image`/`voice`/`video`) default `false` (admin opt-in) even
   though Phase 10 originally shipped image-approval as creator-self-service; Task 2 tightens that. Confirm
   this is the intended tightening, not just documentation of existing behavior.
