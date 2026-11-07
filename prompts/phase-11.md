## Phase 11 Task 1: Messenger platform abstraction (refactor, no new platform yet)

### Goal
Introduce a `MessengerPlatform` contract and normalized `BotUpdate` DTO, and move every existing Telegram-
specific call in Phases 3, 8, and 9 behind it — with **zero behavior change** for current Telegram users.
This is groundwork for Bale (Task 2); it adds no new platform itself.

### Before starting
- Query graphify: `graphify query "telegram bot sdk"`, `graphify query "webhook controller"`,
  `graphify query "reminder dispatch"`, `graphify query "creator chat post"`.
- Read `prompts/main-addendum-3.md` §2.9 and §3.8 in full.
- `grep -r` the codebase for every direct use of the Telegram SDK / raw Bot API HTTP client, and for every
  `telegram_user_id` reference. Build the actual list before starting — don't assume it's only the webhook
  controller; reminders (Phase 3), creator-chat posting (Phase 8), and timed-session step handling (Phase 9)
  all send messages too.

### What to Build
1. `App\Messaging\Contracts\MessengerPlatform` interface covering every method actually used today:
   `sendMessage`, `editMessageText`, `sendPhoto`, `answerCallbackQuery`, `getChatMember`, `getChat`,
   `getFile`/download, `createInvoice` (payment). Add nothing speculative — only what's already called
   somewhere in the codebase.
2. `App\Messaging\DTO\BotUpdate` — normalized incoming-update shape: `platform`, `platform_user_id`,
   `chat_id`, `text`, `callback_data`, `photo_file_id`, `voice_file_id`, `voice_duration`,
   `forwarded_chat_id`, plus a `raw` passthrough for anything platform-specific a handler still needs.
3. `TelegramMessengerPlatform implements MessengerPlatform` — wraps the existing Telegram SDK/HTTP calls
   Phase 3 already built. A Telegram update-to-`BotUpdate` normalizer sits in front of the existing webhook
   controller logic.
4. Refactor every call site found in the "before starting" grep to depend on `MessengerPlatform` (resolved
   per-update or per-recipient's stored `platform`) instead of the Telegram SDK directly. This includes the
   webhook controller, `BotConversation` wizard steps, check-in handlers (all three `proof_type`s and the
   timed-session flow), reminder dispatch jobs, and creator-chat posting jobs.
5. `users` table: replace `telegram_user_id` with `platform` (backed enum, `telegram` only for now) +
   `platform_user_id`, unique composite. Write a real data migration (not a fresh migration assuming an
   empty table) mapping existing rows to `platform = 'telegram'`.
6. Namespace platform-specific `Setting`/config entries (required channel, bot token, webhook secret) by
   platform, even though only `telegram` has values yet — `TELEGRAM_REQUIRED_CHANNEL` stays, structured so
   `BALE_REQUIRED_CHANNEL` (Task 2) is an addition, not a schema change.

### Tests (Pest, required)
- The **entire existing Phase 3, 8, and 9 Pest suite still passes**, assertions unchanged — this is the
  actual definition of done for this task, not a nice-to-have. If any assertion needed to change to keep
  passing, that's a behavior change and needs to be justified explicitly in `progress.md`, not silently
  accepted.
- New: a `BotUpdate` normalization test per update type (text message, callback query, photo, voice,
  forwarded message) against `TelegramMessengerPlatform`.
- The `users` migration correctly backfills existing rows; a fresh install still creates the composite key
  correctly.

### Explicitly Out of Scope
- No Bale implementation of the interface — Task 2.
- No Bale Pay — Task 3.
- No cross-platform account linking (see addendum §2.9 — deliberately deferred).

### Code Rules
- Follow `CLAUDE.md`. This task adds an interface and moves call sites behind it; it must not change what any
  Telegram-facing message, keyboard, or timing behaves like.
- If a call site can't be cleanly abstracted (something genuinely Telegram-only), leave a narrow, clearly-
  named escape hatch on `TelegramMessengerPlatform` rather than leaking Telegram types into shared Actions.

Work only on this task.

## Phase 11 Task 2: Bale bot integration

### Goal
Implement `BaleMessengerPlatform`, a Bale webhook, and a `BotUpdate` normalizer for Bale updates, so that
every existing bot feature (channel gate, creation wizard, all check-in flows including timed sessions,
reminders, creator chats) works for Bale users through the same Actions Telegram already uses.

### Before starting
- Query graphify: `graphify query "MessengerPlatform"`, `graphify query "BotUpdate"` (Task 1 must be merged
  first).
- Read `prompts/main-addendum-3.md` §2.9 — feature parity is **not** assumed; verify each Bale API capability
  against Bale's own Bot API documentation before relying on it.
- Confirm specifically: does Bale support inline keyboards the same way (used throughout the wizards and
  timed-session UI)? Voice messages with duration metadata (used by timed-session voice steps)? A forwarded-
  message chat-discovery mechanism equivalent to Telegram's `forward_from_chat` (used by creator-chat
  registration, Phase 8)? Does Bale's webhook offer a secret/signature mechanism comparable to Telegram's
  `secret_token` header? Record the actual answers in `prompts/progress.md` before writing code that assumes
  any of them.

### What to Build
1. `BaleMessengerPlatform implements MessengerPlatform` — HTTP calls to Bale's Bot API for every method the
   interface requires. Wherever a capability from the "before starting" checklist is missing or different,
   implement the closest safe equivalent and note the degradation (e.g. plain numbered replies instead of
   inline keyboards) rather than silently no-op-ing.
2. `routes/bale.php` webhook route + `BALE_WEBHOOK_SECRET` config, validated per whatever mechanism Bale
   actually offers (confirmed above) — don't assume it matches Telegram's header scheme.
3. Bale update → `BotUpdate` normalizer, mirroring the Telegram one from Task 1.
4. Wire `platform = 'bale'` through: the channel gate (separate `BALE_REQUIRED_CHANNEL` setting), the
   creation wizard, all check-in flows, reminders, and creator-chat posting. No Bale-specific branches inside
   any of those Actions — if one is needed, that's a sign `MessengerPlatform` is missing a method, not a
   reason to special-case the platform inline.
5. A Bale-flavored mirror of the key Phase 3/8/9 integration tests (same scenarios, `Http::fake()`'d against
   Bale's API shape instead of Telegram's) to prove real parity rather than assumed parity.

### Tests (Pest, required)
- `/start` on Bale: channel gate (against `BALE_REQUIRED_CHANNEL`), invite attribution, brand-new-user
  credit rule — same assertions as the Telegram version, run against Bale.
- Create-challenge wizard and join flow complete correctly via Bale updates.
- Each `proof_type` check-in (button, text_autogen, image_approval) and the timed-session flow (Phase 9)
  work via Bale, including whatever degraded behavior was decided for any missing capability.
- Reminders and creator-chat announcements correctly select `BaleMessengerPlatform` for Bale-platform
  recipients/chats.
- Webhook secret validation is enforced using Bale's actual mechanism; a request without it is rejected.
- `Http::fake()` all Bale API calls — never hit the real Bale API in tests.

### Explicitly Out of Scope
- No Bale Pay — Task 3.
- No cross-platform linking.
- Don't touch `TelegramMessengerPlatform` or any Telegram-specific test in this task.

### Code Rules
- Follow `CLAUDE.md`. All new bot-facing strings go through the existing i18n layer (Farsi + English), same
  as Telegram.
- If Bale genuinely can't support something a challenge design relies on (e.g. a voice step, if Bale lacks
  voice messages), fail that specific interaction clearly to the Bale user rather than crashing or silently
  skipping the step.

Work only on this task.

## Phase 11 Task 3: Bale Pay integration

### Goal
Add Bale Pay as a second payment rail into the existing `CoinLedger`, alongside Telegram Stars, using the
`bale-payments` skill for the exact request/verify/webhook contract.

### Before starting
- Query graphify: `graphify query "CoinLedger"`, `graphify query "StarPayment"`, `graphify query "Stars"`.
- Read the `bale-payments` skill in full before writing any code in this task — it documents Bale's actual
  payment flow; do not guess at endpoint names or the request/verify sequence.
- Read Phase 4's Stars implementation (`createInvoiceLink` → `pre_checkout_query` → `successful_payment`,
  idempotent on `telegram_payment_charge_id`) as the pattern to mirror, not to copy verbatim — Bale Pay is
  likely a request-then-verify flow (closer to Iranian gateways like Zarinpal) rather than Telegram's single
  webhook push; follow whatever the skill actually documents.

### What to Build
1. Extend the payment-provider concept to a backed enum (`telegram_stars`, `bale_pay`) wherever it currently
   assumes Telegram only (likely a column on the existing payment/coin-purchase model from Phase 4).
2. `BalePayGateway` (or whatever the skill's own naming convention suggests) implementing the coin-purchase
   flow: initiate a payment request for a coin package, redirect/deep-link the user to pay, handle Bale's
   callback, verify the transaction per the skill's documented verify step, then credit `CoinLedger` — same
   locked, idempotency-keyed pattern Phase 4 already uses, keyed on Bale's transaction/authority id instead
   of `telegram_payment_charge_id`.
3. Bot-side purchase flow for Bale users mirroring the existing Stars purchase flow, routed through
   `BaleMessengerPlatform` (Task 2) for any messages sent.
4. Refund path: implement it only if the `bale-payments` skill documents one. If it doesn't, say so explicitly
   in `prompts/progress.md` rather than approximating a refund endpoint that doesn't exist.

### Tests (Pest, required)
- Successful purchase: request → (mocked) user pays → callback → verify → coins credited exactly once.
- Duplicate callback/verify for the same transaction id credits coins exactly once (mirror Phase 4's
  duplicate-`successful_payment` test).
- Failed/cancelled payment never credits coins.
- `Http::fake()` all Bale Pay calls — never hit the real gateway in tests.
- If a refund path was built, a duplicate refund request is rejected (idempotent), mirroring Phase 4's
  refund test.

### Explicitly Out of Scope
- No changes to the Telegram Stars flow.
- No new coin package types beyond what Phase 4 already defines — Bale Pay sells the same packages through a
  second rail, it doesn't add new ones.

### Code Rules
- Follow `CLAUDE.md`. `CoinLedger` itself is not modified — this task adds a second crediting path into it,
  same locking and idempotency discipline as Stars.
- Every user-facing string (purchase prompts, errors) goes through i18n.

Work only on this task.
