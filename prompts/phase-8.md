## Phase 8 Task 1: Creator chat registration & verification

### Goal
Let a challenge creator link a Telegram channel or group they administer to their challenge, with the bot
independently verifying it is admin *and* that the creator is admin — no broadcast surface until both hold.

### Before starting
- Query graphify: `graphify query "challenge ownership"`, `graphify query "bot conversation wizard"`.
- Read `prompts/main-addendum-2.md` §2.6 and §3.5.
- Confirm the current `BotConversation` pattern from Phase 3 before adding a new wizard branch — reuse it,
  don't build a second conversation-state mechanism.

### What to Build
1. Migration + model: `ChallengeChat` (`challenge_id`, `telegram_chat_id`, `chat_type` enum(`channel`,
   `group`), `title`, `bot_admin_verified_at`, `creator_admin_verified_at`, `is_active`, `share_proof_media`
   default `false`, `post_checkin_announcements` default `false`, `post_daily_leaderboard` default `false`).
2. Bot entry point: a "Link a chat" option inside the creator's own challenge management menu (creator-only
   — check `challenge.creator_id` against the caller before entering the wizard).
3. Discovery step: instruct the creator to add the bot as admin, then forward a message from that chat back
   to the bot DM. Extract `forward_from_chat.id` from the forwarded message; reject anything without it with
   a clear "that wasn't forwarded from a chat" message rather than a stack trace.
4. Verification: `getChatMember(chat_id, bot_id)` (must be admin, `can_post_messages` true for channels) and
   `getChatMember(chat_id, creator_telegram_id)` (must be admin). Both must pass to activate the row; if
   either fails, tell the creator specifically which one failed.
5. A lightweight `VerifyChallengeChat` Action, callable both from the wizard and re-run lazily before any
   scheduled/triggered post (Task 2 will call it). On failure at that later point: set `is_active = false`,
   notify the creator once (don't retry-loop), don't throw into the queue's failure handler.
6. Guard: `share_proof_media` cannot be set `true` if `challenge.proof_is_public` is `false` — enforce in
   the Form Request / Action, not only in whatever UI eventually toggles it.

### Tests (Pest, required)
- Full happy path: forward → both memberships true → row activated.
- Bot-not-admin and creator-not-admin cases each rejected with a distinguishable message; `Http::fake()` the
  Bot API for both.
- Non-creator attempting to link a chat for someone else's challenge is rejected before any Telegram call is
  made.
- `share_proof_media = true` on a `proof_is_public = false` challenge is rejected at the Action layer.
- Re-verification: an active `ChallengeChat` whose bot admin status is later revoked (mocked) gets
  deactivated on the next `VerifyChallengeChat` call, not left active.

### Explicitly Out of Scope
- No posting logic yet (Task 2).
- No Mini App or admin-panel UI for managing linked chats — bot-only for this task.
- No support for linking more than one chat per challenge unless the schema already trivially allows it (it
  does, by not adding a uniqueness constraint on `challenge_id`) — don't add a limit unless asked.

### Code Rules
- Follow `CLAUDE.md`. Thin controller/handler → Action; Form Requests for the forwarded-message payload
  shape; backed enum for `chat_type`.
- Never trust `chat_id` from anywhere except the forwarded message's `forward_from_chat` — never accept a
  raw chat_id typed by the user.

Work only on this task.

## Phase 8 Task 2: Event-driven posting — check-ins & leaderboards

### Goal
Post check-in announcements and leaderboards into linked chats, queued and rate-respecting, with
`share_proof_media` and `proof_is_public` honored exactly and idempotency that survives retries.

### Before starting
- Query graphify: `graphify query "SettleCheckIn"`, `graphify query "reminder dispatch staggering"`.
- Read `prompts/main-addendum-2.md` §2.6 (privacy + fan-out subsections) — the privacy rules there are
  non-negotiable, not suggestions.
- Confirm how `SettleCheckIn` currently signals completion (event, return value, or model observer) before
  deciding where to hook the announcement dispatch.

### What to Build
1. Listener/hook on successful `SettleCheckIn` that, for each `is_active` `ChallengeChat` with
   `post_checkin_announcements = true` on that challenge, dispatches a queued `PostCheckInAnnouncement` job.
   Idempotency key `(challenge_chat_id, period_id, participant_id)` — check-and-insert a
   `ChallengeChatPost` row inside the job before sending, so a re-dispatched job is a no-op.
2. Message content: participant display name + period number + streak count only. If `share_proof_media` is
   `true` and this period's proof was an approved image, attach it — otherwise text-only, always, regardless
   of `proof_is_public`.
3. Scheduled `PostDailyLeaderboard` command (piggybacks the existing scheduler from §2.4), one run/day at an
   admin-configurable hour (`Setting`), one queued job per active `ChallengeChat` with
   `post_daily_leaderboard = true`. Idempotency key `(challenge_chat_id, date)`.
4. On-demand leaderboard: a bot command usable inside the linked chat itself, restricted to chat admins
   (`getChatMember` check against the *caller*, not the stored creator — any admin of the chat, not just the
   original registrant). Rate-limited to once per 5 minutes per chat (`RateLimiter::attempt`), respond with
   remaining cooldown on rejection rather than silently dropping the command.
5. Before any send: call `VerifyChallengeChat` (Task 1) if the chat's last verification is older than a
   configurable TTL (start at 24h); skip the post and deactivate the row on failure rather than sending into
   a chat the bot lost access to.
6. All sends via queued jobs using the same `delay()` staggering convention as reminder fan-out — never a
   synchronous loop of `sendMessage` calls in a scheduled command.

### Tests (Pest, required)
- Check-in announcement fires once per (chat, period, participant) even if the job is dispatched twice.
- `share_proof_media = false` never includes an image, even when the underlying proof was an image approval.
- A challenge with `proof_is_public = false` never has an active `share_proof_media = true` chat to begin
  with (defense in depth — assert the invariant holds even if Task 1's guard were somehow bypassed).
- Daily leaderboard idempotent per (chat, date); running the scheduled command twice sends once.
- On-demand command: non-admin caller rejected; admin caller within cooldown rejected with remaining time;
  admin caller outside cooldown succeeds.
- A `ChallengeChat` failing lazy re-verification is deactivated and no message is sent for that run.

### Explicitly Out of Scope
- No leaderboard customization (sort order, count shown) beyond streak-descending, top N (pick N=10, make it
  a `Setting`) — don't build creator-configurable leaderboard formatting yet.
- No Mini App/admin surfacing of post history — a `ChallengeChatPost` table existing is enough for now.

### Code Rules
- Follow `CLAUDE.md`. Reuse the reminder system's staggering/queue conventions rather than inventing a
  second fan-out pattern.
- Every outbound message string goes through the i18n layer.

Work only on this task.
