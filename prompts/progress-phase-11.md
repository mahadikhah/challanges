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

## Task 2 — Bale bot integration (delivered)

**What landed** (commit `feat(bot): Bale messenger platform — second bot surface alongside Telegram`):

- `App\Messaging\PlatformRegistry` — the one place a `MessagingPlatform` case becomes an implementation
  class. A `match` with **no default arm**: a platform added to the enum without an implementation is a
  fatal at resolution, not a silent fallthrough to the wrong bot. The old
  `MessengerPlatform → TelegramMessengerPlatform` container binding is **gone**; nothing can resolve
  "the platform" without naming whose platform it is.
- `App\Messaging\Bale\BaleMessengerPlatform` — full contract implementation. SDK constructor
  `baseBotUrl: 'https://tapi.bale.ai/bot'` (never `setBaseBotUrl()`), same `LaravelHttpClient` transport
  so `Http::fake()` sees every call. `createInvoiceLink`/`refundPayment` throw
  `MessengerException('Bale Pay is not wired yet (Phase 11 Task 3)…')` — refuse loudly, never pretend.
- Platform facts as **enum methods** on `MessagingPlatform` (`requiredChannelSetting()`,
  `channelUrl()`, `startLink()`, `supportsNativePayments()`) — this is how the task's "no Bale-specific
  branches inside Actions" constraint was met: the Actions ask the enum, the enum knows.
- Migrations: `telegram_updates` unique `(platform, update_id)`; `challenge_chats` gains `platform` with
  unique `(challenge_id, platform, telegram_chat_id)`.
- Bale webhook: `routes/bale.php` + `BaleWebhookRequest` (path secret via `hash_equals`, **fail-closed**
  when unconfigured, 404 not 403 so a probe can't distinguish wrong-secret from unrouted) +
  `BaleWebhookController` (record → queue → 200, same contract as Telegram's).
- Swept every Telegram hardcoding to the seam: `ResolveTelegramUser` (platform param, defaults Telegram
  for the Mini App initData path), `TelegramFileDownloader` (PlatformRegistry, per-call platform), all
  four update handlers (per-update resolution), `BotMessenger`, `VerifyChannelMembership` (per-user
  channel setting), `ChannelGatePrompt`, `ChannelBroadcaster`, `ChatBroadcaster`, `VerifyChallengeChat`,
  `RegisterChallengeChat`, `CreateStarsInvoice`, `RefundStarsPayment`, and `Challenge::joinLink()` /
  `Invite::deepLink()` now take a **required** `MessagingPlatform` argument — no silent t.me default
  inside a ble.ir post.
- Shop (`/shop` + the package callback) refuses Bale users via `supportsNativePayments()` with the new
  `bot.shop.unavailable` line (en+fa), pending Task 3.
- New lang: `admin.settings.keys.required_channel_bale` (en+fa; the Telegram one is now labelled
  "(Telegram)" for disambiguation), `bot.shop.unavailable` (en+fa). Factory states: `TelegramUpdateFactory::bale()`,
  `UserFactory::bale()`.

**Tests** (all `Http::fake()`d; `tapi.bale.ai` and `api.telegram.org` are never really hit):
- `tests/Feature/Messaging/BaleMessengerPlatformTest.php` (11) — normalization mirror of the Telegram
  suite (text/callback/photo-ladder/voice with **missing duration tolerated as null**/forward_from_chat/
  unknown-shape), sendMessage URL + keyboard serialization, `getMe` memoised to one call, ChatMember
  snapshot mapping, no-token refusal, invoice/refund refusal with nothing sent.
- `tests/Feature/Bot/BaleBotTest.php` (11) — webhook records on the Bale platform and queues; 404 on
  wrong/absent secret and when unconfigured; **same `update_id` on both platforms = two updates** (the
  collision the composite unique exists for); `/start` gates on `required_channel_bale` asking
  `tapi.bale.ai` (never Telegram) with a `ble.ir` join button on refusal; a Telegram inviter is paid for
  a brand-new Bale arrival; `/shop` refuses; `/create` opens the wizard on Bale; reminders fan out
  through each recipient's own platform (one Bale + one Telegram participant in one challenge).

**Deviations & gotchas worth remembering:**
- **MySQL refused `dropUnique` before the wider unique existed** (error 1553: index needed by the
  `challenge_id` foreign key) — the chats migration creates `(challenge_id, platform, telegram_chat_id)`
  *first*, then drops the old two-column one. Anyone widening a unique under an FK must do the same.
- `users.platform` is **nullable** (web-only Fortify admins have no messenger). The gate/broadcaster read
  it with an explicit `?? MessagingPlatform::Telegram` fallback rather than assuming the backfill covered
  everyone — the docblock claim "never null" was wrong and is corrected in code.
- `VerifyChannelMembership::handle()` now checks **identity before channel**: a web-only admin is refused
  as `notATelegramUser` before any platform is asked anything (previously the channel resolution could
  throw first, masking the real reason).
- `ChannelBroadcaster` resolves the channel **before** claiming `announced_at` — an unconfigured channel
  must not burn the once-only claim and strand the challenge marked-announced-but-unseen.
- `Http::fake()` appends and the **first matching pattern wins** — catch-all fakes in `beforeEach`
  shadow per-test stubs; the Bale tests use `Http::preventStrayRequests()` + method-name wildcards
  (`*getChatMember*`), which match either host, and assert on *which host* was asked.
- Pest `expect(...)->toBeCanonicalizingEq` doesn't exist; sorting a collection of enums is order-unstable
  (enum comparison, not string) — map to `->value` **then** sort.
- Running two Pest processes against the same `testing` database deadlocks (RefreshDatabase metadata
  table locks) and leaves the DB half-migrated — always one suite at a time, and
  `DROP DATABASE testing; CREATE DATABASE testing` recovers.

## Task 3 — Bale Pay ✅

`sail composer ci:check` green (Pint, PHPStan lvl 7, 1394 Pest assertions-bearing tests, ESLint, Prettier,
`tsc --noEmit`).

### What landed

**The rail facts, encoded as enum methods** (`App\Enums\PaymentProvider`, backed `telegram_stars` |
`bale_pay`): `forPlatform()`, `priceKey()` (stars|rial), `currency()` (XTR|IRR), `packageLabelKey()`,
`supportsRefunds()`. The payment model's "provider concept" is this enum on `star_payments.provider`
(string(32), default `telegram_stars` so existing rows are owned by the only rail that wrote them), plus a
nullable `rial_amount` and — required by MySQL to accept the null Stars column on Bale rows —
`stars_amount` itself made nullable. Exactly one of the two price columns is set, per `provider`.

**The flow, initiate → invoice → callback → verify:**
- `CreateBaleInvoice` — prices the package from the admin-tuned table's optional `rial` key, writes the
  Pending row (`stars_amount` null), and **sends the invoice into the payer's chat** (`sendInvoice`) —
  no link exists to hand back (trap #1). The wallet `provider_token` is read per call and refuses loudly
  when `BALE_PROVIDER_TOKEN` is unset (trap #2).
- `sendInvoice` on the contract — both platforms implement; Telegram sends XTR + empty `provider_token`
  unchanged, Bale sends Rial with the wallet token and **no `currency` parameter at all** (Bale takes
  none). `createInvoiceLink` stays on the contract (Telegram's link-button flow is untouched); Bale
  refuses it with the no-link fact as the message.
- `inquireTransaction` on the contract — the verify() of the rail. Bale implements it as a raw
  `Api::post()` (absent from Telegram SDKs); Telegram refuses it ("a successful_payment is its own
  confirmation"). Returns the platform-neutral `App\Messaging\DTO\PaymentTransaction`
  (`PaymentTransactionStatus::fromRail()` tolerates unknown status strings as `Unknown`, never paid).
- `CompleteStarsPayment` is **the one completion action for both rails** (see design notes).
- `PreCheckoutQueryHandler` now asks the row `acceptsPreCheckout(currency, total)` — the row's own rail's
  tag (`XTR`/`IRR`) and the row's own price. `ShopCommand` filters shelves by the payer rail's
  `priceKey()` so button indexes still name the shared table's rows; `ShopCallback` branches once —
  Bale's counter sends the invoice, Telegram's keeps its link button exactly as before.
- Webhook path needed **zero changes**: `UpdateRouter` routes by payload key, so Bale's
  `pre_checkout_query`/`successful_payment` already reach the same handlers.

**Refunds: none built.** The `bale-payments` skill documents **no refund endpoint** on Bale's rail
(verified against docs.bale.ai). `PaymentProvider::supportsRefunds()` is false for Bale →
`StarPayment::isRefundable()` is false → the admin refund lever is hidden, `RefundStarsPayment` throws
`LogicException`, and `BaleMessengerPlatform::refundPayment()` throws with the documented-absence message.
Bale purchases are watch-only in the audit view (its description line says so).

**Pricing:** one `stars_packages` table, optional per-row `rial` key (default rows get placeholder
50k/100k/250k/500k Rial, admin-tunable; `UpdateSettingRequest` gained
`value.*.rial: sometimes|int|min:0|max:100000000`). Admin Settings gained a Rial column in the packages
editor; the admin Payments audit gained a Rail badge and a per-rail Price cell (`XTR`/`IRR`, one currency
per line). Lang: `bot.shop.package_rial`, rail-neutral `bot.shop.prompt`, new
`bot.shop.pending_confirmation` (inquiry pending ≠ failure), `enums.payment_provider.*`,
`coin_transaction_reason.bale_pay_purchase`, en+fa everywhere.

### Design notes

- **One completion action, not two.** `CompleteStarsPayment` kept a single provider `match` that swaps
  the *source of truth* for (currency, total): Telegram trusts its secret-token-authenticated payload;
  Bale re-asks via `inquireTransaction` **before** the DB transaction — no HTTP under the row lock, no
  re-inquiry of a spent transaction (rows already Paid get a synthetic confirmed answer). Duplicating
  the transactional crediting path into a `CompleteBalePayment` was judged the bigger risk for a money
  path: two copies of lock/replay/credit is how they drift.
- **Inquiry statuses:** `failed`/`rejected` → row marked Failed, nothing credited; `pending`/unknown →
  row stays Pending, payer told `bot.shop.pending_confirmation`, a redelivery re-asks. Never credit short
  of `paid`.
- **Idempotency:** shared `telegram_payment_charge_id` column (Bale's equals the earlier
  `PreCheckoutQuery.id`), ledger keys namespaced per rail (`star_payment:credit:` /
  `bale_payment:credit:`) so the rails' id spaces can never collide into one key.
- `supportsNativePayments()` is now true for both platforms; the guard stays for future rails, and its
  meaning is "wired", not "priced" — Bale shelves need `rial` rows *and* `BALE_PROVIDER_TOKEN`.

### Tests

- `tests/Feature/Payments/BalePayTest.php` (12) — the mirror of StarsPurchaseTest through the Bale
  webhook: tap records a `bale_pay` row and the invoice on the wire (chat_id, wallet provider_token,
  Rial prices, **no currency key**, invoice-is-the-reply); rial-less package refused; pre-checkout
  approved on `IRR` == rial_amount, declined on mismatch with the row surviving; successful_payment
  credits from the *inquiry's* answer (reason `bale_pay_purchase`, key `bale_payment:credit:<charge>`,
  drift 0, exactly one inquiry); triple delivery credits once and inquires once; inquiry failed/rejected
  → Failed + no credit; pending → row Pending + `pending_confirmation` line; amount mismatch → Failed;
  crossed payer → row untouched, *no inquiry at all*; refund lever absent on every layer.
- `BaleMessengerPlatformTest` (17) — the wire contract per the skill: no-link refusal, no-refund
  refusal, sendInvoice exact URL + params + provider-token refusal, inquireTransaction mapping /
  unknown-status-as-Unknown / no-transaction refusal.
- `BaleBotTest` — `/shop` now lists only Rial-priced shelves (index math intact), says `no_packages`
  when none are; the old "refuses until wired" test replaced.
- Existing suites untouched except: `EconomySchemaTest` enum pin gained `bale_pay_purchase`, and the
  migration's `stars_amount` nullable change (Bale rows carry no Stars price — the columns are not
  exchange rates of each other).

**Phase 11 complete. Next: per the user's instruction (2026-08-29), phases 12/13 are deferred — proceed
to Phase 14, then 15, then return to 12 and 13.**
