# Phase 17 — Defect fixes reported from the deployed bot and Mini App

Nine tasks. Every one of them starts from an issue in `prompts/issues.md` and a specific defect located in the
code — none is a greenfield feature. Line references below were accurate when this file was written; re-verify
with graphify before editing, since Phase 16 landed after some of them.

---

## Phase 17 Task 1: Messenger media sending — voice, video, and a keyboard on media

### Goal
Give `MessengerPlatform` the ability to send a voice message and a video, and let every media send carry an
inline keyboard — so a proof can be delivered to the creator with its verdict buttons attached, in one send.

### Before starting
- Query graphify: `graphify query "MessengerPlatform"`, `graphify explain "BotMessenger"`,
  `graphify path "TelegramMessengerPlatform" "BaleMessengerPlatform"`.
- Read `app/Messaging/Contracts/MessengerPlatform.php` in full. Note `sendPhoto(int $chatId, string $bytes,
  string $filename, array $captionLines)` **already exists** on the contract and on both implementations
  (`TelegramMessengerPlatform.php:167`, `BaleMessengerPlatform.php:181`) — this task extends that shape, it does
  not invent one.
- Read the contract's own docblock ("The method set is exactly what the codebase calls today — nothing
  speculative"). This task is the caller that justifies `sendVoice`/`sendVideo`; say so in the commit.

### What to Build
1. Add to `MessengerPlatform`:
   - `sendVoice(int $chatId, string $bytes, string $filename, array $captionLines, ?array $inlineKeyboard = null): SentMessage`
   - `sendVideo(int $chatId, string $bytes, string $filename, array $captionLines, ?array $inlineKeyboard = null): SentMessage`
2. Add an **optional trailing** `?array $inlineKeyboard = null` to the existing `sendPhoto`, so the signature
   stays backward compatible for its current caller (`ChatBroadcaster::sendPhoto`).
3. Implement all three on `TelegramMessengerPlatform` and `BaleMessengerPlatform`, following the existing
   `sendPhoto` body exactly: multipart upload of the bytes, `caption` from the caption lines, `reply_markup`
   as a JSON-encoded keyboard when one is given. Reuse whatever private helper `sent()`/keyboard-encoding the
   photo path already uses rather than writing a second encoder.
4. Caption discipline must match `BotMessenger::paragraphs`: blank-line separated, nulls and blanks dropped.
   Keep that normalisation in one place — do not duplicate the `array_filter` logic per method if a small
   private helper will do.

### Tests (Pest, required)
- `Http::fake()`; assert a `sendVoice` call posts multipart with the bytes, the caption, and (when supplied) a
  `reply_markup` whose decoded keyboard is the expected rows.
- The same for `sendVideo`, and for `sendPhoto` **with** and **without** a keyboard — the no-keyboard case is a
  regression check on the existing caller.
- Null caption lines are dropped, blank ones are dropped, survivors are joined with `\n\n`.
- Both platforms: run the assertion set against Telegram and Bale, so a future third platform has a template.
- `MessengerException` propagates on a non-2xx response rather than being swallowed.

### Explicitly Out of Scope
- No caller changes — nothing in this task sends a proof anywhere. Tasks 2 and 3 do that.
- No `sendDocument`. Nothing needs it.
- No `editMessageText`, no `editMessageCaption`. A verdict edits nothing today.

### Code Rules
- Follow `CLAUDE.md`. The contract describes *intent* ("send a voice message into a chat"), never Telegram's
  wire format; wire details stay inside the implementations.
- Keep the added parameter optional and trailing everywhere so no existing call site changes.

Work only on this task.

---

## Phase 17 Task 2: The creator receives the actual proof (simple flow)

### Goal
When a participant submits a photo, voice note or video for an `*_approval` challenge, the creator's
notification must **carry that media**, with the approve/reject buttons on the same message. Today it carries
only a sentence.

### Before starting
- Query graphify: `graphify query "notifyReviewer"`, `graphify query "proofKind"`,
  `graphify path "CheckInFlow" "ReviewCheckInCallback"`.
- Read `app/Services/Telegram/CheckInFlow.php:784-827`. The docblock above `notifyReviewer()` currently asserts
  the opposite of what this task builds — it says the bot "has no way to attach a stored recording to a message
  without re-uploading it, and the queue is where a verdict can be considered anyway". **That justification is
  what this task removes**: re-uploading is exactly what we now do, and the caller's own copy says so.
- Read `app/Models/CheckIn.php:110-122` (`proofKind()`) — the extension→kind mapping you need already exists
  and already returns `image`/`voice`/`video`/null.
- Read `app/Services/Telegram/ChatBroadcaster.php:97` (`sendPhoto`) for the existing "read a `proof_path` off
  disk and send it" precedent — including which disk it reads and how it handles a missing file. Reuse the
  same disk convention; do not open a second one.
- Check how `PostCheckInAnnouncement.php:85` calls it, so the public-proof path is not disturbed.

### What to Build
1. In `notifyReviewer()`, after the existing `platform_user_id === null` guard, read the bytes of
   `$checkIn->proof_path` from the same storage disk the review queue uses and send them through the matching
   `sendPhoto`/`sendVoice`/`sendVideo` (Task 1), with:
   - caption = the existing `bot.checkin.review_prompt_{kind}` line (keep the exact keys; the wording may change
     in Task 7, not here), and
   - inline keyboard = the existing approve/reject rows, unchanged.
2. **One send, not two.** The keyboard rides on the media message. Do not send the text message and then the
   media — Telegram allows roughly a message a second per chat, and the second send is the one that gets a 429.
3. **Degrade honestly when the media cannot be read or sent.** If the file is missing from disk, or the media
   send throws `MessengerException`, fall back to the existing text-plus-buttons message and log the reason with
   the `check_in_id`. A creator who can still approve from the text message is strictly better served than one
   who gets nothing — and the admin review queue remains the backstop.
4. When `proofKind()` returns null (an unexpected extension), use the existing text-only path and log it.
5. Update the `notifyReviewer()` docblock to describe what it now does, and drop the "the bot has no way"
   sentence.

### Tests (Pest, required)
- A photo submission delivers the creator a `sendPhoto` carrying the stored bytes, the `review_prompt_image`
  caption, and the two verdict buttons — assert the media call *and* the keyboard on it.
- A voice submission does the same via `sendVoice`; a video submission via `sendVideo`.
- **Exactly one send** to the creator per submission — assert the recorded request count, so a future refactor
  cannot quietly reintroduce a second message.
- A submission whose `proof_path` is missing from disk still notifies the creator (**with** the buttons, text
  only) and writes a log line; it does not throw and does not leave the submission unnotified.
- A creator with no `platform_user_id` still only logs (existing behaviour, unchanged).
- **Regression:** the participant-side messages (`photo_sent`, `voice_sent`, `video_sent`), the confirmations,
  and the `ReviewCheckInCallback` approve/reject round-trip all pass unchanged.
- Existing `tests/Feature/Bot/CheckInFlowTest.php` assertions at lines ~439, ~442, ~526, ~552 currently pin the
  text-only notification — update them to the new shape deliberately and say so in the commit message; do not
  delete them.

### Explicitly Out of Scope
- No `file_id` storage or reuse — see the goal file's baked-in decision.
- No wording changes to `review_prompt_*` — Task 7 owns the copy.
- No change to `ReviewCheckIn`, `ReviewCheckInCallback`, or the verdict path. This task changes only what the
  creator is *shown*, never what a verdict *does*.
- No AI-review path changes (`ApprovalMode::Ai` already auto-decides and needs no notification).

### Code Rules
- Follow `CLAUDE.md`. Media bytes never leave the server except to the challenge's own creator — re-check that
  the recipient is `$challenge->creator` and not anything derived from the submission payload.
- Any new failure string goes through i18n in both locales; the fallback path mostly reuses existing keys, so
  prefer adding none.

Work only on this task.

---

## Phase 17 Task 3: Timed-session submissions reach the creator at all

### Goal
A completed timed session currently tells the participant "your session is in for review" and tells the creator
**nothing**. Deliver the session's media to the creator with verdict buttons, exactly as Task 2 does for the
simple flow.

### Before starting
- Query graphify: `graphify query "SessionStepFlow"`, `graphify query "CompleteCheckInSession"`,
  `graphify path "SessionStepFlow" "CheckInFlow"`.
- Read `app/Services/Telegram/SessionStepFlow.php:437-470` (`settledOrNext`) — it sends
  `bot.session.submitted_for_review` to the *participant* and stops. Confirm by grep that `notifyReviewer`
  exists nowhere outside `CheckInFlow` (it does not, as of this writing).
- Read `app/Actions/CheckIns/CompleteCheckInSession.php` — it is documented as "an adapter, not a second
  engine": it opens the check-in row and calls `SettleCheckIn::approve()` inside one transaction. Understand
  where a notification can be *triggered* without putting a network call inside that transaction.
- Read `create_check_in_step_submissions_table`'s migration comment: step submissions mirror
  `check_ins.proof_path` exactly, "same name, same meaning, one convention". Decide from the actual schema
  whether the session's reviewable media is the check-in's `proof_path` or the step submissions — and state
  which you found in the commit message.

### What to Build
1. When a timed session completes with a media submission, notify the creator with the media and the same
   approve/reject buttons — reusing `ReviewCheckInCallback` and the `bot.checkin.*` review keys rather than
   minting a parallel set. The verdict path must remain **one** engine: a creator's tap settles a session
   submission through exactly the code a simple-flow tap uses.
2. **Extract, do not copy.** If Task 2 left `notifyReviewer()` private to `CheckInFlow`, lift the shared part
   into a small collaborator both flows inject (a `ProofReviewNotifier` alongside the existing Telegram
   services), and have `CheckInFlow` call it too. `CLAUDE.md`: "Every surface calls the same Actions — never
   three copies of the rule."
3. Dispatch the notification **after** the transaction commits, not inside it. `CompleteCheckInSession` holds
   locks; a Telegram call inside that window risks a slow send holding them. Follow whatever post-commit
   convention the codebase already uses for `CheckInSettled` (check its listeners under `app/Listeners/`).
4. The participant-facing `bot.session.submitted_for_review` line stays exactly as it is.

### Tests (Pest, required)
- A completed session with an image step delivers the creator a media send carrying the bytes and both verdict
  buttons; same for a voice and a video step.
- Tapping the creator's approve button on a **session** submission settles it through the same path a simple
  check-in uses — assert the resulting `CheckIn` status and the participant's streak, not merely that a
  callback fired.
- A session whose media is unreadable still notifies the creator (text + buttons) and logs.
- The notification is not dispatched when the transaction rolls back (mirror the existing pattern for
  post-commit dispatch if one exists; otherwise assert the notification does not fire on a refused completion).
- **Regression:** Phase 9's `CheckInSessionFlowTest` (19 tests) and `CheckInSessionConcurrencyTest` pass
  unchanged. The participant's session messages are byte-identical to before.

### Explicitly Out of Scope
- No changes to session step semantics, wait windows, voice caps, or the expiry sweep.
- No creator notification for *intermediate* steps — only at completion. A creator watching every step of a
  five-step session is not the point of the review queue.
- No AI-review changes.

### Code Rules
- Follow `CLAUDE.md`. Authorization re-validated per request: the notifier resolves the creator from
  `$challenge->creator` server-side.
- If the shared notifier is extracted, Task 2's tests must keep passing untouched — that is the extraction's
  pass/fail bar.

Work only on this task.

---

## Phase 17 Task 4: Ask for the language after `/start` when none is set

### Goal
A user who has never chosen a language is asked to pick one immediately after `/start` — before the welcome,
before the join gate's continuation, and without losing whatever invite or join payload they arrived with.

### Before starting
- Query graphify: `graphify query "StartCommand"`, `graphify query "LanguageCallback"`,
  `graphify path "LanguageCommand" "LanguageCallback"`.
- Read `app/Services/Telegram/Commands/StartCommand.php` in full. Its docblock explains why attribution runs
  **first** — an invite pays only when the invitee is brand-new (`wasRecentlyCreated`), and blocking before
  that loses the credit forever. **The language prompt must not break that ordering.**
- Read `app/Services/Telegram/Commands/LanguageCommand.php` and
  `app/Services/Telegram/Callbacks/LanguageCallback.php` — the button rows, the `Localization::options()`
  source, the `lg` action word, and the `isSupported()` allowlist check already exist and must be reused, not
  reimplemented.
- Read `app/Services/Localization.php` (`best()`, `options()`, `isSupported()`, `nativeName()`).
- Check `users.locale` is nullable (`add_telegram_columns_to_users_table` — it is) and how `locale` is
  currently seeded, if at all, from Telegram's `language_code` (`ResolveTelegramUser`).
- Read `app/Enums/ConversationState.php` and `ConversationRouter` to see whether a blocking state is the right
  mechanism or whether a callback argument is enough.

### What to Build
1. On `/start`, when `$user->locale === null`, send the language prompt (the same buttons `/language` sends,
   built from the same source) **instead of** the welcome, and remember that this prompt came from `/start`.
2. On the user's pick, `LanguageCallback` sets the locale as it does today and then, when this was a
   start-gate prompt, delivers what `/start` would have delivered: the welcome paragraph(s) — and, if they
   arrived with an invite code or a join payload, the invite note / join preview. In the language they just
   picked.
3. **Preserve the payload across the gate.** The invite claim and the join-arrival resolution must not be
   re-run (re-running `ClaimInvite` a second time risks a double credit or a spurious "already claimed"); the
   outcome has to be carried, not recomputed. Decide between a `BotConversation` row and a callback argument
   and **state which you chose and why** in the commit message. Whichever it is, the carried data must not
   allow a second credit.
4. A user who already has a locale set sees today's `/start` behaviour, byte-identical.
5. The gate ordering is unchanged: attribution still happens before the gate is consulted, and a user still
   behind the channel gate still gets the gate prompt.

### Tests (Pest, required)
- Brand-new user, no locale: `/start` sends the language buttons and **not** the welcome.
- After the button tap: the user gets the welcome in the chosen language, and `users.locale` is set.
- `/start` with an invite code from a brand-new user: the language prompt appears, the inviter is credited
  **exactly once** across the whole flow, and the credited note is delivered after the pick. Assert the
  `CoinTransaction` count and the invite's `credited_at`, not just the visible message.
- `/start j_<token>` (join payload) with no locale: after the pick, the join preview is shown.
- A user with a locale already set gets today's message and no language prompt — explicit regression.
- A user behind the channel gate with no locale: the gate prompt still comes, and the flow still completes
  correctly afterwards.
- Tapping a stale/forged language button still resolves through `isSupported()` and refuses safely (existing
  behaviour, re-pinned because this task changes the callback).

### Explicitly Out of Scope
- No new locale, no change to `localization.supported`.
- No auto-detection from Telegram's `language_code` as a substitute for asking. (If you find `ResolveTelegramUser`
  already seeds `locale` from it, that is *why* the prompt never appears for some users — report it in
  `progress.md` and decide explicitly whether the prompt should still fire; do not silently change the seeding.)
- No `/language` command changes beyond what the shared prompt/callback refactor requires.
- No Mini App or admin-panel language UI changes.

### Code Rules
- Follow `CLAUDE.md`. Every new string through i18n in `en` + `fa`; the language prompt itself already resolves
  in the *fallback* locale until a choice is made, which is correct — do not try to guess.
- Reuse `LanguageCommand`'s button-building. If it needs sharing, extract it rather than duplicating the loop.

Work only on this task.

---

## Phase 17 Task 5: Register the bot's command menu

### Goal
The bot currently registers no command list with Telegram, so a user opening the bot sees no menu button and
has no way to discover that `/create`, `/checkin`, `/shop` and `/language` exist. Register them, localized.

### Before starting
- Query graphify: `graphify query "TelegramServiceProvider"`, `graphify explain "SetWebhookCommand"`,
  `graphify query "CommandRouter"`.
- Read `app/Providers/TelegramServiceProvider.php:88-100` — the `BOT_COMMANDS` map is the single source of
  truth for what exists (`start`, `create`, `checkin`, `chatlink`, `shop`, `language`, `cancel`). Derive the
  menu from it; do not hand-write a second list.
- Read `app/Console/Commands/Telegram/SetWebhookCommand.php` — this is where `setWebhook` is already called, and
  the natural place for `setMyCommands` to be called alongside it.
- Read `app/Services/Telegram/BotMessenger.php` and `Localization` for how a locale is resolved — but note the
  menu is a *platform* call, not a per-user send, so it does not go through `BotMessenger`.
- Check how the Telegram SDK (`irazasyed/telegram-bot-sdk` `^3.16`) exposes `setMyCommands` and what
  `BotCommand` DTO the SDK wants (`command`, `description`). Confirm against the installed version, not docs.

### What to Build
1. A single source for the menu: for each handler in `BOT_COMMANDS`, a translated name and description. Put the
   descriptions in `lang/{en,fa}/bot.php` under a `commands` group, one key per command.
2. Extend `SetWebhookCommand` (or add a sibling artisan command if the webhook command is genuinely the wrong
   home — say why if so) to register the command list, **once per supported locale**, using the platform's
   per-language command-scope mechanism. If the platform supports language-scoped command sets, use them; if
   it does not, register the fallback-locale set only and record that limitation in `progress.md`.
3. `start` should be first in the menu and `cancel` last — the ordering rule is part of the user-facing
   affordance, so encode it deliberately rather than relying on array order.
4. `chatlink` is a creator-only command. Decide whether it belongs in the public menu and state the reasoning
   in the commit message; if it is excluded, the exclusion must be a visible rule in the code, not an omission.

### Tests (Pest, required)
- `Http::fake()`; running the command issues the expected `setMyCommands` call(s) with the expected
  command/description payload, asserting the **order** (`start` first, `cancel` last).
- Every entry in `BOT_COMMANDS` appears in the registered menu — a coverage test that fails when a handler is
  added without a menu line, mirroring the registry-coverage test the admin settings tabs already use.
- Every menu description resolves in **both** locales and neither returns its own key (i.e. no missing
  translation).
- The command is safe to re-run (idempotent at the platform level — no local state to corrupt).

### Explicitly Out of Scope
- No change to `CommandRouter`, any handler, or `BotCommand::parse`. This task adds discoverability only.
- No persistent reply keyboard — see the goal file's baked-in decision.
- No in-message buttons. That is Task 6.

### Code Rules
- Follow `CLAUDE.md`. Descriptions are short (Telegram truncates) — terse, imperative, no trailing period.
- **Never log the bot token** — the SDK client already holds it; do not echo config while debugging this.

Work only on this task.

---

## Phase 17 Task 6: Buttons instead of "send /command"

### Goal
Replace every place the bot tells a user to *type* a slash-command with a tappable inline button, so the bot is
usable without knowing any command syntax.

### Before starting
- Query graphify: `graphify query "CallbackRouter"`, `graphify query "BotCallback::encode"`,
  `graphify query "MessageHandler"`.
- Read `app/Services/Telegram/Handlers/MessageHandler.php:90-118` — the routing order is
  **payment → command → conversation → session media → fallback**. A button tap must re-enter at the *command*
  step, not the conversation step, or an open wizard will eat it.
- Read `app/Services/Telegram/CallbackRouter.php`, `HandlesCallback.php`, and the existing callbacks under
  `app/Services/Telegram/Callbacks/` (`CheckInCallback`, `WizardCallback`, `LanguageCallback`,
  `ShopCallback`, `SessionStepCallback`, `JoinCallback`, `ReviewCheckInCallback`). Reuse `BotCallback::encode`
  and the existing action-word convention; check the callback-data length limit the encoder already respects.
- **Audit the current copy.** Run `grep -rn "/" lang/en/bot.php lang/fa/bot.php` and collect every string that
  instructs the user to type a command. As of this writing at least: `start.next_steps` (`/create`, `/cancel`),
  `gate.then_start_again` (`/start`), `cancel.nothing_open` (`/create`), `wizard.opening` (`/cancel`),
  `wizard.stale_step`, `wizard.incomplete` (`/create`), `wizard.error` (`/create`), `session.stale`
  (`/checkin`), `fallback.unknown` (`/create`, `/start`), `fallback.stale_button` (`/create`),
  `shop.pre_checkout_error` (`/shop`), `chatlink.prompt_cancel` (`/cancel`), `chatlink.no_challenge`
  (`/chatlink <payload>`). Re-run the grep yourself — do not trust this list.
- Read `app/Services/Telegram/ChannelGatePrompt.php` — it already solves this pattern (a join button instead of
  an instruction) and is the model to follow.

### What to Build
1. Add a **command callback**: one action word that resolves a command name from our own allowlist and
   dispatches it through the existing `CommandRouter`, so a tap and a typed command end in the same handler.
   The command name must be validated against the registered handler map — a crafted `callback_data` must not
   be able to reach a handler that was not offered. Take the same care `LanguageCallback` takes with its locale
   allowlist.
2. Attach buttons to the messages that today say "send /x":
   - `bot.start.next_steps` → a button for create, and one for cancel only when a conversation is actually
     open (a "cancel" button on a fresh start does nothing and is noise — decide and justify).
   - `fallback.unknown` / `fallback.stale_button` → a create/start button.
   - `cancel.nothing_open`, `wizard.incomplete`, `wizard.error` → a create button.
   - `session.stale` → a check-in button.
   - `gate.then_start_again` → a "try again" button, but note the gate already ships a join button; do not
     stack two.
3. The copy itself loses the literal `/command` where a button now carries the action — a message that shows
   the button *and* says "send /create" reads as two different instructions. **Task 7 runs after this one** and
   audits the final wording; leave the strings consistent here and let Task 7 add the check-in instruction.
4. `chatlink.no_challenge` is the one case where a payload must be typed (`/chatlink j_abc123`) — a button
   cannot carry a free-form argument. Decide how to handle it (a button that starts the flow and then asks for
   the payload, or leave it as the single documented typed command) and state the decision.

### Tests (Pest, required)
- For each converted message: it carries the expected button(s), with `callback_data` that decodes to the
  expected action — assert on the recorded Bot API request.
- Tapping a command button lands in the **same handler** a typed command does: pin at least one write-path
  case end to end (e.g. the check-in button produces the same outcome as `/checkin`, with the same participant
  state afterwards).
- A crafted/unknown command name in `callback_data` is refused safely and does not reach a handler.
- A button tapped while a create-challenge wizard is open does not get swallowed as a wizard answer — the
  routing-order regression.
- Every converted message still renders in both locales with no missing key.

### Explicitly Out of Scope
- No persistent reply keyboard (goal-file decision).
- No new commands, no new handlers.
- No wizard flow redesign — the wizard's own buttons already exist (`skip_button`, `today_button`,
  `create_button`, `cancel_button`, `add_step_button`, `done_steps_button`) and are fine.
- No Mini App or admin-panel changes.

### Code Rules
- Follow `CLAUDE.md`. Buttons are rows (`list<list<InlineButton>>`); respect the callback-data length limit the
  existing encoder enforces.
- Every string change lands in `en` + `fa` together.

Work only on this task.

---

## Phase 17 Task 7: Tell users to check in — and how

### Goal
No bot message currently tells a user *how* to check in. The reminders say "Send /checkin"; the join message
says "Check in every period"; the `/checkin` reply for a due period names the period and stops. Every message
that asks for a check-in must say what the participant actually has to do — which differs per proof type.

### Before starting
- Query graphify: `graphify query "CheckInConfirmation"`, `graphify query "ReminderKind"`,
  `graphify query "CheckInFlow"`.
- Read `lang/en/bot.php` and `lang/fa/bot.php` side by side. The messages in play, with their current shape:
  - `start.welcome` / `start.welcome_back` / `start.next_steps` (Task 6 rewrote the last one)
  - `join.joined` — "Check in every period to keep the streak alive."
  - `join.preview_*`, `announce.details`
  - `checkin.nothing_due` — "Nothing is due from you right now…"
  - `checkin.todo` — "“:title” — period :index of :total is open." ← **the key one: a period is open and the
    user is told nothing else**
  - `checkin.awaiting_review`, `checkin.done`
  - `reminder.challenge_starting` / `period_opened` / `period_ending`
  - `wizard.created` / `created_timeline` — the creator never learns how their own participants check in
- Read `app/Services/Telegram/CheckInFlow.php:150` (`nothing_due`) and the `todo` path, plus
  `app/Jobs/Telegram/SendReminder.php:125-139` (`compose()`) — the reminder composer currently passes only
  `title`, `index`, `total`, `moment`, `timezone`, so a proof-type-aware instruction needs a new replacement
  value threaded through.
- Read `app/Enums/ProofType.php` — `expectsText()`, `expectsFile()`, `expectsDuration()` and the
  `button`/`text_autogen`/`image_approval`/`voice_approval`/`video_approval` cases are the branches the
  instruction has to follow.
- Read `app/Enums/PeriodType.php` — Task 8 changes the same strings' *period* wording. **Coordinate: this task
  owns the check-in instruction, Task 8 owns the period noun.** Do not both rewrite the same sentence's other
  half; add your clause without touching the period phrasing, and let Task 8 land on top.

### What to Build
1. One small collaborator (or an enum method on `ProofType`, whichever fits the codebase's grain better — state
   the choice) that answers "what does a participant do to check in for this challenge?", returning the i18n
   key for the instruction. One branch per proof type:
   - `button` → tap the check-in button
   - `text_autogen` → the bot sends a phrase; type it back
   - `image_approval` → send a photo
   - `voice_approval` → send a voice message
   - `video_approval` → send a video
   For a timed-flow challenge (`FlowType`) the instruction names starting the session instead — the steps are
   the flow, not a single submission.
2. Thread the instruction into the messages that ask for a check-in, each in the recipient's locale:
   - `checkin.todo` — the highest-value one; it is the reply when something *is* due.
   - `checkin.nothing_due` — say to come back and what to do then.
   - `reminder.period_opened` and `reminder.period_ending` — thread the proof type through `compose()`.
   - `join.joined` — a newly joined participant should learn the mechanic at the moment they join.
   - `wizard.created` / a new `wizard.created_checkin` line — the creator should know what they are asking of
     people.
   - `start.welcome` for a returning user with an open period, if it can be done without a second query on
     every `/start`; if it cannot, skip it and say so.
3. Keep it to **one added sentence**, not a paragraph. Telegram messages here are already multi-paragraph and
   the instruction has to be scannable.
4. `announce.details` is read by a mixed-language audience in the announcement channel — the instruction there
   must be proof-agnostic and short (a public post is not the place for a five-branch sentence). Handle it
   deliberately or leave it out and say why.

### Tests (Pest, required)
- **Dataset over all five proof types plus the timed flow:** each yields an instruction naming the right action
  — assert the rendered message, not the key.
- `checkin.todo` for a `text_autogen` challenge tells the user to type the phrase; for `image_approval`, to send
  a photo. Assert on the actual Bot API request body.
- A reminder for each `ReminderKind` carries the instruction, in the recipient's locale; a Farsi user gets the
  Farsi branch (the reminder composer has had locale bugs before — `SendReminder`'s docblock is explicit that
  the message is composed at send time in the recipient's locale).
- Both locales define every new key; neither returns its own key. This is the parity test the repo already runs
  for new strings — extend it rather than writing a second one.
- **Regression:** Task 6's button assertions still pass — adding a sentence must not disturb the keyboard.

### Explicitly Out of Scope
- No change to when a reminder is sent, its idempotency (`(participant, period, kind)`), or its rate limiting.
- No period-noun work — Task 8.
- No proof-type semantics, no new proof type.
- No Mini App copy (the Mini App has its own `miniapp.php` catalogue; a follow-up if wanted, noted not built).

### Code Rules
- Follow `CLAUDE.md`. Every string in `en` + `fa`.
- A placeholder (`:what` / `:how`) is better than five full sentence variants if the sentence shape is the same
  — fewer keys to keep in parity. Decide and be consistent.

Work only on this task.

---

## Phase 17 Task 8: Say "day", not "period"

### Goal
A daily challenge's messages should say "day", a weekly one "week", and so on — instead of the generic word
"period" leaking into copy where the challenge's own cadence is known.

### Before starting
- Query graphify: `graphify query "PeriodType"`, `graphify explain "HasTranslatedLabel"`,
  `graphify query "CompactDuration"`.
- Read `app/Enums/Concerns/HasTranslatedLabel.php` — the label key is derived as
  `enums.<snake_class>.<case_value>`, and `label()` resolves in the *ambient* locale. The bot cannot use
  ambient resolution (`BotMessenger`'s docblock explains why), so any new label must be reachable through
  `translationKey()` and interpolated with `BotMessenger::line()`.
- Read `lang/en/enums.php:16-23` and `lang/fa/enums.php` — `period_type` already holds `Daily`, `Weekly`,
  `Monthly`, `Seasonal`, `Yearly`, `Custom`. Those are **picker labels** and must not change.
- **Itemise the offending copy.** Grep `lang/en/bot.php` + `lang/fa/bot.php` for `period` (case-insensitive) and
  classify each hit as user-facing or internal. As of this writing the user-facing ones include:
  `join.preview_details`, `join.joined`, `checkin.nothing_due`, `checkin.todo`, `checkin.below_target_frozen`,
  `checkin.below_target_missed`, `checkin.review_rejected_*`, `wizard.summary`, `wizard.summary_steps`,
  `wizard.summary_scoring`, `wizard.steps_too_long`, `wizard.created_timeline`,
  `wizard.awaiting_custom_period_days`, `wizard.awaiting_timezone`, `wizard.awaiting_total_periods`,
  `wizard.awaiting_scoring_target`, `wizard.awaiting_scoring_base_points`, `announce.details`,
  `reminder.challenge_starting`, `reminder.period_opened`, `reminder.period_ending`, `chatpost.checkin`,
  `chatpost.checkin_scored`. Re-derive this list yourself.
- Note `custom` has **no** natural noun — it is "N days" (`custom_period_days`). Decide the noun for it
  explicitly.

### What to Build
1. A singular unit noun per `PeriodType`, plus whatever plural/count form the sentences need, in
   `lang/{en,fa}/enums.php` under a **new** group (e.g. `period_unit`) — leaving `period_type` untouched. For
   `custom`, the unit is `day` and the count is the challenge's own `custom_period_days`.
2. A way to resolve that noun **per recipient locale** — either a method on `PeriodType` returning a
   translation key for `BotMessenger::line()`, or a tiny service. **Do not** use `label()`; it resolves in the
   ambient locale, which in a queue worker is whoever was processed last.
3. Interpolate it into every user-facing string from the inventory above. Where a sentence says "3 periods",
   it should read "3 days" — so the *count* form matters as much as the singular. English needs `day`/`days`;
   Farsi needs its own pluralisation rule (Farsi commonly uses the singular after a numeral — check what the
   existing Farsi catalogue does elsewhere for counts and follow it rather than assuming English's rule).
4. **Leave internal vocabulary alone.** `ChallengePeriod`, `challenge_periods`, `ReminderKind::PeriodOpened`,
   `total_periods`, `period_type` and the wizard's question *about choosing* a cadence
   (`wizard.awaiting_period_type.prompt` — "How often does everyone check in?") are domain terms, not copy.
   Changing them is a large rename with no user-visible benefit. The one to consider is
   `wizard.awaiting_period_type.error`/`.expected` ("pick one of the periods offered") — decide and state it.
5. `chatpost.checkin` renders in the platform fallback locale for a mixed-language audience (see `bot.php`'s
   `chatpost` comment). It still has the challenge's period type available, so it can still say "day" — do it,
   but keep it terse.

### Tests (Pest, required)
- **Dataset over all six `PeriodType` cases** — the natural dataset this repo already reaches for (see
  `ChallengeStepDesignTest`'s six-period-type dataset). For each: the reminder, the join confirmation, the
  `checkin.todo` line and the chat post name the right unit and never contain the word "period".
- `custom` with `custom_period_days = 3` renders a 3-day unit correctly (singular *and* count form).
- A Farsi recipient gets the Farsi noun, resolved per recipient — the ambient-locale bug this whole mechanism
  exists to avoid. Pin it with two users of different locales in the same test.
- A count of 1 renders the singular in English ("1 day", not "1 days").
- `enums.period_type.*` is **unchanged** in both locales — the picker labels are a regression bar.
- Both locales define every new key, with parity.
- **Regression:** Task 7's check-in-instruction assertions still pass.

### Explicitly Out of Scope
- No schema change, no column rename, no `ChallengePeriod` rename.
- No `proof_type` or `scoring` copy changes beyond the period noun.
- No admin-panel or Mini App copy — the admin panel shows raw period types in tables, which is developer-facing
  and correct as it is. Note in `progress.md` if you find a user-facing admin surface that says "period".
- No date/duration formatting changes (`CompactDuration` stays as it is).

### Code Rules
- Follow `CLAUDE.md`. Backed enums, no magic strings; the noun is keyed off `PeriodType`, never a `match` on
  `$challenge->period_type->value` scattered across call sites.
- `en` + `fa` in the same commit, as every prior copy task has done.

Work only on this task.

---

## Phase 17 Task 9: The Mini App's failure message tells the truth

### Goal
The Mini App reports *every* boot failure as "We could not verify your Telegram identity. Please close and
reopen the app." — a message that is wrong for anything after authentication, and whose advice cannot help.
Split the failure modes, report the real one, and give the deployment a way to be diagnosed.

### Before starting
- **This is a diagnose-then-fix task.** The reported symptom is that the error appears even when the app is
  opened from inside Telegram, and reopening does not help. Establish the actual cause before changing
  behaviour, and record your finding in `progress.md`.
- Read `resources/js/miniapp/app.tsx:38-62`. **The likely core defect is here**: a single `.catch()` is
  chained onto `authenticate(initData).then(...).then(fetchChallenges)`, so a failure of `fetchChallenges()`
  renders the *authentication* failure screen. Read it carefully and confirm.
- Read `resources/js/miniapp/api.ts` — `ApiError` already carries `status`, `reason` and `joinUrl`; nothing
  consumes them at boot. Note `authenticate()` is the only writer of the module-private `token`.
- Read `lang/{en,fa}/miniapp.php` — `failed` is the offending string; check what other keys exist.
- Read `app/Services/Telegram/InitDataVerifier.php` in full and **verify it against `CLAUDE.md`'s Telegram
  Integration Rules**. It appeared correct when this task was written (HMAC argument order right: the
  data-check-string is the message and the derived secret is the key; `signature` excluded; `ksort`; `\n`
  join; `hash_equals`; `auth_date` windowed). Re-verify rather than assume — if you find a defect, that is a
  finding worth reporting prominently.
- Read `app/Http/Controllers/MiniApp/AuthController.php` — refusals are deliberately uniform (one 401, no
  reason). Do not change that. Read `app/Actions/MiniApp/AuthenticateMiniAppUser.php`.
- Check the settings that can break a *later* request: `SettingKey::MiniAppTokenTtlMinutes` (registry default
  60) and `SettingKey::InitDataMaxAgeSeconds` (defaults from `services.telegram.initdata_ttl`, 3600).
  `now()->addMinutes(0)` would mint an already-expired token, which fails at `/challenges`, not at `/auth` —
  exactly the shape that produces the misleading message. Check `app/Enums/SettingKey.php:48-49,199-200`.
- Check `config/services.php:53-71` and `config/telegram.php:35` — the bot token is read via
  `Config::string('services.telegram.bot_token')`. An unset token makes every verification fail. Also confirm
  whether the Mini App is served over HTTPS: Telegram supplies `initData` **only** over HTTPS (localhost
  excepted), so a plain-HTTP deployment yields an empty string. Check `deploy/*.env.example` for
  `MINIAPP_URL` / `APP_URL`.
- Read `resources/views/miniapp.blade.php` and `resources/js/miniapp/telegram.ts` — the SDK script tag and the
  `webApp()` accessor are already correct; `app.tsx` already tolerates `Telegram` being absent.

### What to Build
1. **Correct attribution in `app.tsx`.** Separate the boot into distinct phases with distinct outcomes:
   - `webApp()` present but `initData` empty, or `webApp()` absent → "this must be opened from inside
     Telegram" (its own key).
   - the `/auth` exchange failing with 401 → the existing identity message, which is now *accurate* because it
     is only shown for that case.
   - the exchange succeeding and a **later request** failing → a data-load failure with its own key, carrying
     whatever `ApiError.status`/`reason` the API named, plus a retry affordance.
   Keep the "three boot states, never more" spirit the file's docblock states — extend it to four deliberately
   and update that docblock, rather than leaving a comment that no longer matches.
2. **Make the misattribution impossible to reintroduce**: no `.catch()` that spans both the auth call and the
   data calls.
3. **A server-side diagnostic.** An artisan command that reports, without printing any secret: whether
   `services.telegram.bot_token` is configured (and its length, never its value), whether `MINIAPP_URL` is set
   and `https://`, the effective `initdata_max_age_seconds` and `miniapp_token_ttl_minutes`, and a
   self-consistency check that `InitDataVerifier` accepts a synthetic payload this command signs itself with
   the configured token. A failing self-test means the token or the algorithm is wrong; both are the two things
   that cannot otherwise be seen from a browser.
4. **Guard the TTL settings.** A `miniapp_token_ttl_minutes` of 0 or less must not mint a dead token. Enforce a
   floor where the token is minted (or validate the setting), and test it. Check whether the same class of bug
   applies to `initdata_max_age_seconds` — note that a non-positive value there *disables* the staleness check
   (see `InitDataVerifier::assertFresh`), which is permissive rather than broken; decide whether that is
   acceptable and say so.
5. If the diagnosis turns out to be environmental (unset token, HTTP-only deployment), the code changes above
   still stand — they are what makes the *next* occurrence diagnosable. Write the operational fix in
   `progress.md` and do not invent credentials.

### Tests (Pest, required)
- **There is no JS test framework in this repo and this task must not add one.** The `app.tsx` change is
  covered by: (a) the diagnostic command's tests, (b) the TTL guard tests, (c) lang-key parity for the new
  keys. State in the commit message that the client branch is verified manually in a Telegram client, and say
  what you observed.
- Diagnostic command: with a token configured, the self-test passes and the output never contains the token
  value — assert the raw output string does **not** contain it.
- Diagnostic command: with no token configured, it reports the token as missing and fails loudly rather than
  reporting a green self-test.
- `miniapp_token_ttl_minutes = 0` no longer mints an immediately-expired token; the resulting token
  authenticates a subsequent `/api/v1/miniapp/challenges` request successfully.
- The existing `InitDataVerifier` tests still pass unchanged, and one new test pins a **known-good vector**:
  a payload with a hash computed by the test itself from a fixture token, asserting the verifier accepts it,
  plus the same payload with one character of the hash altered asserting refusal. (Check whether such a vector
  already exists before adding one.)
- `AuthController` still returns a single uniform 401 with no reason for every `InvalidInitDataException` —
  explicit regression, because this task is about being more informative to *our users*, not to strangers.
- Both locales define every new key, with parity.

### Explicitly Out of Scope
- No relaxation of `initData` security: no skipping the hash check, no `signature`-only path, no trusting
  `initDataUnsafe`.
- No reason field in the `/auth` 401 response.
- No new JS dependency, no `@telegram-apps/sdk`, no Vitest.
- No change to the Mini App's challenge/check-in features.
- No Sanctum configuration change beyond the TTL floor.

### Code Rules
- Follow `CLAUDE.md`, especially the Telegram Integration Rules section — those are the spec this task is
  verifying against.
- **Never log or print a bot token**, including in the diagnostic command's verbose mode, its tests, or a
  pasted stack trace in `progress.md`.

Work only on this task.
