# Phase 11 — Bale (progress)

## Task 1 — Messenger platform abstraction (refactor, no new platform) ✅

Commit `ffd2d7e` (feat-side) + this file. `sail composer ci:check` green after.

### What landed

- `App\Messaging\Contracts\MessengerPlatform` — the seam. Method set is **exactly** what the codebase
  calls today: `platform()`, `normalizeUpdate()`, `sendMessage`, `sendPhoto`, `answerCallbackQuery`,
  `answerPreCheckoutQuery`, `getChatMember`, `botId()`, `downloadFile`, `createInvoiceLink`,
  `refundPayment`. Parameters are intent-shaped (named, typed), not Telegram param arrays, so the Bale
  implementation writes intent rather than wire format.
- `App\Messaging\DTO\BotUpdate` — normalized update: `platform`, `platformUserId`, `chatId`, `text`,
  `callbackData`, `photoFileId` (largest ladder size pre-picked), `voiceFileId`/`voiceDuration`,
  `forwardedChatId`, `raw` verbatim passthrough + `value()` dot-path reader (same contract as
  `TelegramUpdate::value()`).
- `App\Messaging\DTO\ChatMemberSnapshot` (status token + `isMember`/`canPostMessages` as `?bool`,
  absent stays null) and `SentMessage` (message id only — nothing reads anything else off a send).
- `App\Messaging\Telegram\TelegramMessengerPlatform` — reference implementation; absorbs the wire
  details call sites used to carry inline: JSON-serialized `reply_markup`, `InputFile::
  createFromContents`, absent-not-false booleans, `provider_token: ''` for Stars, raw
  `post('refundStarPayment')` (SDK 3.16 has no wrapper), `getFile` + token-keyed file URL.
- `App\Enums\MessagingPlatform` — `telegram` case; `configKey()` = `services.telegram` makes the
  per-platform config block a convention `BALE_*` slots into (item 6 of the task: config was already
  namespaced under `services.telegram.*`; the enum encodes it, no moves needed).
- Every SDK call site refactored to the interface: `BotMessenger`, `ChatBroadcaster` (the dynamic
  `->{$method}` dispatch became explicit `sendMessage`/`sendPhoto`), `ChannelBroadcaster`,
  `LinkedChatHandler`, `CallbackQueryHandler`, `PreCheckoutQueryHandler`, `VerifyChannelMembership`,
  `VerifyChallengeChat`, `CreateStarsInvoice`, `RefundStarsPayment`, `TelegramFileDownloader` (now:
  platform fetches bytes, downloader stores them).
- **Deliberate escape hatches kept on the SDK, not leaked into shared code:** `BotIdentity` (memoised
  `getMe` — the platform delegates `botId()` to it), the `telegram:set-webhook`/`webhook-info` console
  commands (webhook lifecycle is Telegram-only by nature), the `Api` singleton + `LaravelHttpClient`
  in `TelegramServiceProvider`.
- **users migration:** `telegram_id` → `platform` (backed enum cast) + `platform_user_id`, unique
  composite `users_platform_platform_user_id_unique`, real data backfill (`platform='telegram'`,
  `platform_user_id = telegram_id`), reversible `down()`. User model keeps `isTelegramUser()` and the
  `telegram()` scope (now `where platform = telegram`) and the factory keeps the `telegram()` state —
  the vocabulary stays Telegram because the actors still are; the *columns* are platform-shaped.
- Interface bound `MessengerPlatform` → `TelegramMessengerPlatform` (singleton) in
  `TelegramServiceProvider`. Per-update/per-recipient platform *resolution* arrives with the Bale
  webhook (Task 2) — today there is exactly one platform, so the single binding is the whole truth.

### Two deviations from the task text, explicitly (per the task's own rule)

1. **`editMessageText`, `getChat`, and a separate `getFile` method are not on the interface.** The task
   text lists them; a `grep` inventory found **zero** call sites for `editMessageText` or `getChat`
   anywhere in the codebase, and file fetching is expressed as `downloadFile(fileId): bytes` (what
   every caller actually wants — the two consumers both store bytes immediately). Adding uncalled
   methods would violate the task's own "add nothing speculative" rule; they join when a caller does.
2. **Test touch-ups outside Phases 3/8/9.** The Phase 3/8/9 suites pass with assertions unchanged.
   The mandated column rename forced mechanical renames elsewhere: `where('telegram_id', …)` lookups
   in bot tests (same meaning, new column), `$user->telegram_id` property reads
   (`ResolveTelegramUserTest`, `MiniAppAuthTest`, `ChallengeSchemaTest`, `RefundStarsPaymentTest`),
   and the admin users surface (`UsersController` prop key `telegram_id` → `platform_user_id`, the
   Users/Show TSX types, `admin.users.platform_user_id` lang key → "Messenger id"/"شناسهٔ پیام‌رسان",
   and three `UsersAndCoinsTest` Inertia-prop assertions). None of these change *what* is asserted —
   only the name of the column the value comes from. Behavior change: none intended.

### Gotchas hit

- **`MessengerException` must extend `TelegramSDKException`.** First draft extended `RuntimeException`
  and five refusal-path tests failed — the suite asserts `TelegramSDKException` propagates, and
  callers (`LinkChatFlow`, admin controllers) catch it. Extending the SDK exception keeps every
  existing catch and assertion live while new shared code catches only `MessengerException`. Bale's
  exception will subclass `MessengerException`, not the Telegram type.
- **`ChannelBroadcaster` passes `@username` channels as chat ids** — `sendMessage`'s `$chatId` is
  `int|string` on the interface for exactly that reason (the announcement channel can be either).
- **DDL inside a RefreshDatabase test implicitly commits the wrapper transaction.** The backfill test
  rolls the split migration back, inserts a legacy row, re-runs `up()`, and then **deletes its rows
  explicitly** — otherwise they leak into every test file that runs afterwards (no rollback left to
  catch them).
- `migrate:rollback --path=<file>` + `migrate --path=<file>` (via `Artisan::call`) is the clean way to
  exercise a data migration against production-shaped rows without hand-building schema.
- SDK `Message` has no `getMessageId()` under PHPStan's eye — read `->get('message_id')`, same as the
  codebase reads every other response field.
- `DB::table()` query builder has no `whereKey()` — `where('id', …)`.

### New tests

`tests/Feature/Messaging/TelegramMessengerPlatformTest.php` — normalization per update type (text,
callback query, photo ladder, voice, forwarded), `raw` passthrough, kinds the DTO doesn't describe
(`pre_checkout_query`, `my_chat_member`) returning null. `PlatformIdentityMigrationTest.php` —
backfill of legacy rows, composite-unique enforcement (duplicate identity rejected), fresh-install
index shape.

**Quality gate:** `sail composer ci:check` green — 1349 passed / 4 skipped / 1 incomplete
(pre-existing), pint, phpstan lvl 7, eslint, prettier, tsc all clean. `graphify update .` run.

## Task 2 — Bale bot integration

### Capability verification (recorded before any code, per the task's "Before starting")

All answers verified against the official docs at `docs.bale.ai` (fetched via curl, since WebSearch was
degraded) plus the `bale-payments` skill's vendored contract. These are facts the implementation below
assumes; if any is wrong, the code built on it is wrong.

1. **Inline keyboards: supported.** `sendMessage.reply_markup` takes `InlineKeyboardMarkup.
   inline_keyboard` (rows of `InlineKeyboardButton`), `callback_query` updates carry `data`, and
   `answerCallbackQuery(callback_query_id)` exists. Bale's docs add a wrinkle Telegram doesn't: the
   answer **must always be sent**, and clients too old to render the answer are detectable by
   `callback_query_id` starting with `"1"` — our handler already answers unconditionally and ignores the
   response, so no code change.
2. **Voice with duration: supported.** `sendVoice` and a `Voice` type (`file_id`, `file_unique_id`,
   …) exist. The Farsi docs page enumerates `duration` on the message's voice object alongside the file
   ids; the normalizer reads it defensively (`is_int` guard) so an absent field degrades to null rather
   than breaking.
3. **`forward_from_chat`: supported.** Forwarded messages carry `forward_from_chat` as a `Chat`
   object — creator-chat discovery by forwarding works identically to Telegram.
4. **Webhook secret: does not exist.** `setWebhook` accepts **only** `url` (HTTPS, ports 443/88; an
   empty string disables). There is no `secret_token` parameter and no signature header on deliveries.
   Authenticity therefore rests entirely on an **unguessable URL path secret** plus server-side
   re-verification of everything the payload claims — the same posture the Telegram route already has
   via its URL token, minus the header second factor.
5. **Bonus facts relied on:** `getChatMember` returns ChatMember subtypes with `status` strings
   `"creator"` (ChatMemberOwner), `"administrator"`, `"member"`, `"restricted"` (with `is_member`
   boolean) — compatible with `ChatMemberStatus::fromTelegram`. `getFile` (20 MB max) + download at
   `https://tapi.bale.ai/file/bot<token>/<file_path>`, valid 1 hour. Bot links are
   `https://ble.ir/<bot_username>?start=<token>`. The SDK runs against Bale with
   `new Api($token, false, null, 'https://tapi.bale.ai/bot')` (base URL via constructor only —
   `setBaseBotUrl()` mangles the `/bot` suffix).
