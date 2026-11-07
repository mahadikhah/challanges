# Phase 19 — The Mini App becomes a surface of its own

Nine tasks. Every one starts from an issue in `prompts/issues.md` (lines 23–27) and a defect located in the
code — none is a greenfield feature invented for the spec. Line references below were accurate when this file
was written; re-verify with graphify before editing, since Phase 18 landed after some of them.

The five reports, in the reporter's words:

```
# i see undefined coins in mini app
# i see no create challenge in mini app
# add check in button in mini app for each challange, if simple input , use the mini app , if document point the user to the bot so they upload in bot not in the mini app
# the owner of the challenge is not joind to the challange , atleast they should see the join button immidiatly after they create it , also owner should be able to see the challange and stats in the miniapp if no joined too
# the owner also should be able to approve or reject from the miniapp too , so in that way the inline button of the proof message should get disapeared (i think updating the message needed)
```

Read them together and the shape is plain: **the Mini App is a read-mostly window on a product whose other
surfaces can all act.** Coins, creating, checking in, joining and reviewing are all missing or half-present at
the one surface a user reaches with a thumb. This phase closes that gap without inventing a second rule
anywhere — every task routes through the Action the bot already calls.

---

## Phase 19 Task 1: `/auth` stops shipping half a user

### Goal
The Mini App renders "undefined coins" in its header on every open, because the boot user it is handed has no
`coins` field. One boot-user resource, used by every endpoint that returns one, so the shape cannot drift
again.

### Before starting
- Query graphify: `graphify query "MiniAppUser"`, `graphify explain "MeController"`,
  `graphify path "AuthController" "MeController"`.
- Read `app/Http/Controllers/MiniApp/AuthController.php` — the response's `user` block is **hand-built**, and
  it lists `id`, `first_name`, `username`, `locale`. No `coins`.
- Read `app/Http/Controllers/MiniApp/MeController.php` — the *same* five-field shape, built a second time,
  with `coins` last. **Two hand-built copies of one contract, one of them wrong.** That duplication is the
  defect, not the missing key: a one-line addition to `AuthController` would leave the next field to drift the
  same way.
- Read `resources/js/miniapp/types.ts` — `MiniAppUser` declares `coins: number`, so the SPA is right and the
  server is wrong.
- Read `resources/js/miniapp/app.tsx` — the header renders `t('miniapp.coins', { count: user.coins })`.
  `translate()` in `resources/js/lib/i18n.ts` does `String(value)` on a replacement, so `undefined` reaches the
  screen as the literal word.
- Read `resources/js/miniapp/api.ts` — there is **no** `/me` fetcher. `MeController` is dead API surface today;
  the SPA can never refresh a balance after the exchange.
- Read `app/Services/CoinLedger.php:54-62` (`balanceFor`) — the balance is the newest ledger entry's
  `balance_after`, derived from the ledger and never a column on `users`.

### What to Build
1. Add `app/Http/Resources/MiniApp/MiniAppUserResource.php` — `id`, `first_name`, `username` (from
   `telegram_username`), `locale`, `coins`. It resolves its own balance through `CoinLedger` (constructor
   injection, `JsonResource` supports it via the container), so `coins` is present *by construction* for every
   caller and no caller can forget it.
2. Use it from **both** `AuthController::store` and `MeController::__invoke`. `MeController` collapses to
   returning the resource — its whole body was the shape the resource now owns.
3. Add `fetchMe(): Promise<MiniAppUser>` to `resources/js/miniapp/api.ts` and call it after an action that can
   move the balance (the check-in path at minimum), so the header is refreshable rather than frozen at boot.
4. Keep the `/auth` envelope (`token`, `token_type`, `expires_at`, `user`) exactly as it is — only the `user`
   block's construction changes.

### Tests (Pest, required)
- `POST /api/v1/miniapp/auth` returns `user.coins` as an integer, and it equals a balance written through
  `CoinLedger` before the exchange — a real ledger row, not a zero.
- A user with no ledger rows at all gets `coins === 0`, not `null` and not a missing key.
- **Field parity between `/auth` and `/me`**: hit both and assert the `user` objects are identical for the same
  token. This is the regression that makes the two-copies bug unrepresentable.
- A balance that changes between the two calls is reflected by `/me` — the refresh actually refreshes.
- Existing `tests/Feature/MiniApp/MiniAppAuthTest.php` assertions on the user block still pass; update any that
  pinned the exact key set, deliberately, and say so in the commit message.

### Explicitly Out of Scope
- No new `users` column and no cached balance anywhere — `CoinLedger::balanceFor()` is the reader.
- No change to the `/auth` refusal (still one uniform 401) and no change to the token's lifetime.
- No `/me`-driven polling or interval refresh in the SPA; one refresh per settling action.

### Code Rules
- Follow `CLAUDE.md`. Money is read through `CoinLedger` and nowhere else.
- A resource is the only place this shape is written down. If a third endpoint later returns a boot user, it
  uses this resource too.

Work only on this task.

---

## Phase 19 Task 2: The creator sees their own challenge, joined or not

### Goal
A challenge a user created but never joined is invisible in the Mini App — the list is participations only, and
the detail 404s on any challenge without one. The creator must see what they are running, with their stats when
they have them and a way in when they do not.

### Before starting
- Query graphify: `graphify query "ChallengeController"`, `graphify path "ChallengeResource" "ChallengeParticipant"`,
  `graphify query "MyChallengesFlow rows"`.
- Read `app/Http/Controllers/MiniApp/ChallengeController.php` in full. `index` reads `participations()` alone;
  `show` resolves a participation from the token's user *and* the route id together and 404s on a mismatch.
  The class docblock states the current rule out loud — "creating a challenge does not enrol its creator, so a
  challenge they run but never joined is not theirs on this surface" — and **that sentence is what this task
  reverses.** Update it; do not leave a docblock describing the behaviour that was just fixed.
- Read `app/Services/Telegram/MyChallengesFlow.php:100-140` (`rows()`) — the bot already answers exactly this
  question, with the merge rule and the reason for it. **Reuse the rule and say so in the commit**; do not
  re-derive a second merge with a different tie-break.
- Read `app/Models/User.php` — `participations()` (line 116) and `createdChallenges()` (line 108) both exist.
- Read `app/Http/Resources/MiniApp/ChallengeResource.php` in full. It **wraps a `ChallengeParticipant`** and
  reads `$this->user_id`, `$this->status`, `$this->checkIns`, `$this->freezes_total` throughout — a creator who
  never joined has no such row, so the resource cannot simply be handed null. Decide the shape deliberately (see
  below) rather than scattering null guards through `toArray()`.
- Read `tests/Feature/MiniApp/ChallengesSurfaceTest.php` — it pins the 404 uniformity this task must not break.

### What to Build
1. **`index` lists what the token's user owns or is in.** Union the participations with `createdChallenges()`,
   keyed on the challenge id, newest first — the same merge `MyChallengesFlow::rows()` performs. One entry per
   challenge; a challenge the user both created and joined appears once.
2. **`show` resolves a participation or ownership.** A challenge the token's user neither owns nor is in still
   returns the uniform 404, with no way to tell "not yours" from "does not exist". That property is load-bearing
   and must survive unchanged.
3. **Give `ChallengeResource` an explicit not-joined shape.** The recommended approach: the resource takes the
   *challenge* and the *optional participant*, exposes `has_joined` (bool), makes `me` nullable, and returns
   empty `history` / null `current_period` when there is no participant. State in the class docblock why `me` is
   nullable — a creator is a viewer of their own challenge before they are a participant in it — and keep
   `is_creator` as it is. Whichever shape you pick, the SPA must be able to render the screen without guessing,
   and a `me: null` must never be confused with a participant whose counters are zero.
4. **The not-joined detail still shows the challenge.** Title, description, timeline, status, proof type,
   visibility, the timeline's own progress — everything that belongs to the challenge rather than to a
   participant. What it cannot show is a streak, because there is none.
5. `resources/js/miniapp/types.ts` and `challenge-detail.tsx` follow the shape: the resource is the contract, so
   the TS type changes in the same commit, and the detail screen renders the creator-but-not-joined state (the
   join CTA itself is Task 3 — this task may show a placeholder-free, honest "you are not in this yet" state if
   Task 3 has not landed, but do not fake stats).

### Tests (Pest, required)
- A creator who never joined sees their challenge in `index`, flagged `is_creator: true` and `has_joined: false`.
- The same challenge's `show` returns 200 for its creator with `me: null`, and the challenge's own fields
  (title, timeline, status) intact.
- **A stranger still gets 404** from `show`, and a challenge they own nothing of and are in nothing of is absent
  from their `index`. Both are explicit regressions of the property this task is widening around.
- A user who created *and* joined a challenge appears **once** in `index`, with `has_joined: true` and their real
  streak — the merge cannot double-list.
- Ordering is newest-first across the merged set, not participations-then-created.
- The existing `ChallengesSurfaceTest` cases for a plain participant pass unchanged.

### Explicitly Out of Scope
- No join endpoint — that is Task 3. This task makes the challenge visible and honest about not being joined.
- No change to `Challenge::factory` beyond what a test needs; no new columns.
- No widening of the listing to challenges the user has nothing to do with. Public discovery is not this phase.

### Code Rules
- Follow `CLAUDE.md`, especially "**Never trust a client-supplied `challenge_id`** — always re-resolve the actor
  server-side from the verified token and re-check ownership." The route id is a lookup key, never an
  authorization.
- The merge rule is stated once in the repository. If it cannot be shared with `MyChallengesFlow` cleanly, say
  why in `progress.md` rather than writing a silently different one.

Work only on this task.

---

## Phase 19 Task 3: Join from the Mini App

### Goal
The creator — and any user who reaches a challenge's page — can join it from the Mini App, with the slot refusal
answered the way Phase 18 answered it in the bot: a price, a balance, and a way to buy.

### Before starting
- Query graphify: `graphify query "JoinChallenge"`, `graphify path "JoinChallengeFlow" "JoinChallenge"`,
  `graphify explain "NoEntitlementAvailableException"`.
- Read `app/Actions/Challenges/JoinChallenge.php` in full. Note the two properties the endpoint inherits for
  free and must not re-implement: it is **idempotent** (a second call returns the existing participation and
  spends nothing), and it locks the user row before reading the participant so two concurrent joins cannot both
  spend a slot.
- Read `app/Services/Telegram/JoinChallengeFlow.php:96-140` (`confirm()`) — the bot's own join path: re-verify
  the channel gate, then act. The API does the same, in the same order.
- Read `app/Services/Telegram/Callbacks/SlotPurchaseCallback.php` and the Phase 18 write-up in
  `prompts/progress.md:3795-3870` — the refusal shape (price, balance, shortfall, where to buy) and why the
  purchase button carries no price.
- Read `app/Actions/Entitlements/PurchaseEntitlement.php:96` (`priceOf`) and `ConsumeEntitlement::available()`
  — how a price and a remaining slot count are read.
- Read `app/Http/Controllers/MiniApp/CheckInController.php` for the established refusal idiom: `{reason}`, plus
  `join_url` for the gate, in the SPA's own vocabulary rather than a server sentence.

### What to Build
1. `POST /api/v1/miniapp/challenges/{challenge}/join` → `JoinChallenge::handle()`, returning the refreshed
   `ChallengeResource`. **No second copy of the join rule** — no slot check here, no status check here.
2. Refusals, each as `{reason}` with the fields its client needs and nothing more:
   - the channel gate → `channel_gate` + `join_url` (reuse `VerifyChannelMembership` + its `joinUrl()`, exactly
     as `CheckInController` does);
   - `ChallengeNotJoinableException` → its own reason;
   - `NoEntitlementAvailableException` → `no_join_slot` carrying `price` and `balance` (both read through the
     shared readers above), so the SPA can say "you are 20 coins short" instead of "no".
3. A challenge the token's user cannot see at all stays a 404, by the same rule as Task 2 — resolve the
   challenge, then resolve the actor's relationship to it, then decide.
4. The SPA: the detail screen's not-joined state gains the join button, the refusal banner (gate link, price
   banner), and — once it is joined — the ordinary participant view it already renders.

### Tests (Pest, required)
- Joining spends exactly one `JoinSlot` and creates one participant; the response is the joined view.
- **Posting the same join twice spends one slot**, and the second response reports the same participation —
  the idempotency `JoinChallenge` already guarantees, pinned at the API boundary so a future controller-level
  "fix" cannot turn it into two slots.
- A user with no join slot is refused with `no_join_slot` and a body carrying the configured price and their
  real balance; raising the price between the two calls changes the quoted price (nothing is hardcoded).
- A user who is not in the required channel is refused with `channel_gate` and the join URL.
- A challenge that is closed, or whose timeline is exhausted, is refused with the action's own reason rather
  than a 500 — `ChallengeNotJoinableException` maps to a body.
- A stranger gets 404, consistent with Task 2.
- The join is a **privileged action**: the gate is re-verified at the tap, not inherited from the token's mint
  time (CLAUDE.md, "re-validate authorization on every request").

### Explicitly Out of Scope
- No coin *purchase* endpoint in the Mini App. This task surfaces the shortfall; buying extra coins is the shop's
  job and stays where it is until a later phase says otherwise.
- No auto-join for the creator — see the goal file's baked-in decisions.
- No invite/join-token entry point in the Mini App (`?startapp=` resolution stays the follow-up it already is).

### Code Rules
- Follow `CLAUDE.md`. The controller resolves the actor from the bearer token, never from the body.
- The price and balance on a refusal come from `PurchaseEntitlement::priceOf()` and `CoinLedger::balanceFor()` —
  never from a literal, and never from a `Setting` read the buyer's tap will not also make.

Work only on this task.

---

## Phase 19 Task 4: The bot offers the join the moment the challenge exists

### Goal
A creator finishes the wizard and is shown four paragraphs about a challenge they are **not in**, with nothing
to tap. The confirmation must offer the join — the same join the bot already knows how to make.

### Before starting
- Query graphify: `graphify query "CreateChallengeWizard announceOutcome"`,
  `graphify path "CreateChallengeWizard" "JoinChallengeFlow"`.
- Read `app/Services/Telegram/Wizards/CreateChallengeWizard.php:956-973` (`announceOutcome()`) — four
  `paragraphs`, **no keyboard argument at all**. This is the defect: not a wrong button, an absent one.
- Read `app/Services/Telegram/JoinChallengeFlow.php:52-95` (`preview()`) for the keyboard the join uses, and
  `app/Services/Telegram/Callbacks/JoinCallback.php` for the action word (`jn`) and what the button carries.
- Read `app/Services/Telegram/JoinChallengeFlow.php:96-140` (`confirm()`) — what the tap will run. Nothing about
  the gate or the slot check changes in this task; the button arrives at the code that already decides.
- Read `app/Actions/Challenges/JoinChallenge.php`'s docblock on the creator joining their own challenge ("The
  creator may join their own challenge, and pays a join-slot for it like anybody else"). The design is settled;
  this task is the missing offer.
- Read `lang/en/bot.php` under `wizard` — `created`, `created_timeline`, `created_checkin`, `created_public`,
  `created_private`. The new line joins them.

### What to Build
1. A new line on the confirmation, in both locales, saying plainly that creating a challenge does not put them
   in it and that they can join now or later. One sentence, not a paragraph.
2. A keyboard carrying the join button, built with the **same** payload the preview uses
   (`BotCallback::encode(JoinCallback::ACTION, $challenge->join_token)`), so the tap runs
   `JoinChallengeFlow::confirm()` with its gate check, its slot check and its idempotency intact.
3. Consider whether the confirmation should also offer the challenge's share link — the creator now has a
   challenge and no obvious way to invite anyone. If you add it, it is a `url` button built from
   `Challenge::joinLink($platform)` and it must not replace the join button. Say what you decided in
   `progress.md`. (Recommended: yes, a second row — the moment after creation is the one moment a creator is
   thinking about who else should be in it.)
4. Keep the existing four paragraphs and their order. The join line lands after them, not woven through them.

### Tests (Pest, required)
- Finishing the wizard sends a message whose keyboard carries a join button, and its `callback_data` parses to
  `JoinCallback`'s action with the challenge's own join token — assert the payload, not just that a keyboard
  exists.
- **The button actually joins**: tap the payload the confirmation sent, through the real callback path, and
  expect one `ChallengeParticipant` and one spent `JoinSlot`.
- A creator with no join slot taps it and is refused by `SlotRefusal` with the price — the button does not
  promise what the action will not do.
- The existing `CreateChallengeWizardTest` assertions on the confirmation text still hold; update any that pinned
  the message as keyboard-free, and say so in the commit message rather than deleting them.
- Both locales define the new line, with parity.

### Explicitly Out of Scope
- No auto-join, no `JoinChallenge` call from creation, no change to `CreateChallenge`.
- No change to the preview, the join refusal, or the gate.
- No new callback action word — `jn` already exists and already does this.

### Code Rules
- Follow `CLAUDE.md`. The button re-derives nothing; it hands the tap to `JoinChallengeFlow`.
- Same rule as Phase 17 Task 6: a message that tells a user what they could do carries the thing that does it.

Work only on this task.

---

## Phase 19 Task 5: Check in from the Mini App, for every proof that is not media

### Goal
The detail screen offers a working check-in for `button` and `text_autogen` challenges (and the quantity report
that rides on either). A media-proof challenge says so and opens the bot instead of naming it.

### Before starting
- Query graphify: `graphify query "SubmitCheckIn typePhrase"`, `graphify path "CheckInController" "SubmitCheckIn"`,
  `graphify query "IssueCheckInPhrase"`.
- Read `app/Http/Controllers/MiniApp/CheckInController.php` in full. It calls `SubmitCheckIn::tap()` only, and
  its docblock already concedes the rest: "Phrase and photo challenges keep their proof path in the bot for now
  — recorded as a follow-up in progress.md". **This task is that follow-up.**
- Read `app/Actions/CheckIns/SubmitCheckIn.php` — `tap()` and `typePhrase()` are the two entry points you need;
  `typePhrase()` issues the phrase on demand, normalises both sides of the comparison and refuses with
  `phrase_mismatch`. Neither needs changing. `tap()`/`typePhrase()` both take `$reportedValue`.
- Read `app/Actions/CheckIns/IssueCheckInPhrase.php:75-110` — the phrase is generated **per participant per
  period** and persisted; a second call is a no-op. It is stored in `check_ins.expected_phrase`.
- Read `app/Http/Resources/MiniApp/ChallengeResource.php`'s `current_period` block and `checkInShape()` — where
  the phrase has to be added, and the precedent for exposing a row's field only in the period it belongs to.
- Read `app/Services/Telegram/CheckInFlow.php:637-660` (`askPhrase()`) — how the bot shows the phrase. The Mini
  App shows the same string; it does not mint its own.
- Read `resources/js/miniapp/screens/challenge-detail.tsx` — the `canTap` guard, the `scoring` input, and the
  `miniapp.challenges.via_bot` line this task replaces with a real link.
- Read `resources/js/miniapp/telegram.ts` — `openTelegramLink` is already declared and already used by the gate
  banner. Reuse it.

### What to Build
1. **The endpoint takes what is being submitted.** `POST /challenges/{challenge}/check-in` accepts
   `{text?: string, reported_value?: number}` and dispatches on `challenge.proof_type`:
   - `button` → `SubmitCheckIn::tap()`
   - `text_autogen` → `SubmitCheckIn::typePhrase()`
   - anything else → the existing `wrong_proof_type` refusal, unchanged.
   A `text_autogen` challenge with no `text` is refused with the action's own `proof_missing` reason rather than
   a validation error — the reasons the SPA already knows how to render.
2. **`expected_phrase` reaches its owner — and only its owner.** The resource exposes it for the current period
   when the challenge asks for typed proof, the participant's own row exists and has a phrase, and it is not
   settled. A phrase is per participant: it must never appear in a listing, never for another participant, and
   never for a settled period. Guard this in the resource and pin it with a test that would fail if the field
   leaked into `index`.
3. **The SPA's check-in adapts to the proof type.** A phrase field for `text_autogen` (the phrase shown above it,
   in `dir`-correct text), the existing numeric field for quantity challenges, the one-tap MainButton for
   `button` — and for `image_approval`/`voice_approval`/`video_approval`, a button that opens the bot rather
   than a sentence that names it. The bot's URL comes **from the API** (Task 6's options payload is the natural
   home; a `/me`-adjacent field is acceptable if Task 6 has not landed) — never a hardcoded username.
4. Keep the refusal rendering the SPA already has (`miniapp.check_in.refused.*`). `phrase_mismatch` and
   `value_required` are already in that catalogue; add only what is genuinely new, in both locales.

### Tests (Pest, required)
- A `text_autogen` challenge checks in through the API with the participant's own phrase and becomes `Approved`;
  the phrase was issued by `IssueCheckInPhrase` and the response carries the settled row.
- A **wrong** phrase is refused with `phrase_mismatch` and leaves the row unsent — the mechanic is not weakened
  by having a second surface.
- A `button` challenge with `text` in the body still checks in by tap (the extra field is not a second path).
- An `image_approval` (and a `voice_approval`) challenge refuses via the API with `wrong_proof_type`.
- **The phrase is exposed only to its owner**: participant A's detail carries their phrase; participant B's
  detail on the same challenge carries B's, not A's; a settled row carries none; the index carries none.
- A quantity `text_autogen` challenge takes the phrase *and* the number in one request and scores on the number.
- Both locales define every new key, with parity.

### Explicitly Out of Scope
- **No file upload in the Mini App.** The issue is explicit: a document/media proof points the user at the bot.
  Do not add a `Telegram.WebApp` upload path, do not accept multipart on the endpoint, and do not soften the
  `wrong_proof_type` refusal.
- No new check-in rules and no changes to `SubmitCheckIn`, `SettleCheckIn` or the phrase generator.
- No timed-session check-in from the Mini App. A session is a conversation with waits; the bot owns it, and the
  detail screen keeps sending those users there.

### Code Rules
- Follow `CLAUDE.md`: "Every surface calls the same Action. Check-in from the bot, the Mini App and the admin
  panel must all route through one `SubmitCheckIn` action — never three copies of the rule."
- The refusal reasons are machine-readable strings; the sentences live in the SPA catalogue. Do not add server
  prose.

Work only on this task.

---

## Phase 19 Task 6: Create a challenge from the Mini App — the simple flow

### Goal
A user can create a challenge without leaving the Mini App: the same `CreateChallenge` action, the same slot
spend, the same validation floor — with what the pickers need served by an options endpoint.

### Before starting
- Query graphify: `graphify query "CreateChallenge handle"`, `graphify explain "ChallengeDraft"`,
  `graphify path "CreateChallengeWizard" "CreateChallenge"`.
- Read `app/Actions/Challenges/CreateChallenge.php` in full — the signature is long and every parameter is
  already validated by `assertCoherent()`, `assertMediaCaps()` and `assertScoring()`. **Those rules are not
  restated in this task's validation**; the Form Request's bounds come from `CreateChallenge::limits()` so the
  two cannot disagree.
- Read `app/Services/Telegram/Wizards/CreateChallengeWizard.php:670-730` (`summary()`) and the question list —
  what the bot asks, and in what units, so the Mini App's form asks the same questions in the same order.
- Read `app/Http/Resources/MiniApp/ChallengeResource.php` — a created challenge is rendered by it, including a
  creator who has not joined (Task 2).
- Read `app/Enums/PeriodType.php`, `ProofType.php`, `ChallengeVisibility.php`, `ScoringType.php`,
  `ScoringStrategy.php`, `ApprovalMode.php` and `FlowType.php`. Every one is a backed enum with a translated
  label; the options endpoint sends `{value, label}` pairs exactly as `ChallengeResource::enum()` already does.
- Read `app/Actions/Entitlements/ConsumeEntitlement.php:72` (`available`) and `PurchaseEntitlement::priceOf()` —
  the slot count and price the form quotes before the user commits.
- Read `app/Enums/SettingKey.php:92-93` — `ProofMediaMaxSeconds` / `ProofMediaMaxSizeKb`, the ceilings a
  voice/video challenge's caps may not exceed.
- Read `app/Http/Requests/MiniApp/AuthenticateRequest.php` for the Form Request idiom on this surface, and
  `app/Http/Requests/Admin/AdjustCoinsRequest.php` for a fuller one.

### What to Build
1. **`GET /api/v1/miniapp/challenges/options`** — everything the form needs and nothing about anybody else:
   - `period_types`, `proof_types`, `visibilities`, `scoring_types` as `{value, label}` lists;
   - the timezone identifiers (`DateTimeZone::listIdentifiers()`) — the same set `CreateChallenge` accepts;
   - `default_freezes`, and the media-cap ceilings, read from `Settings`;
   - `slots`: how many create-slots the token's user has left and what an extra one costs;
   - `bot_url`: the bot's own deep link, so the SPA can send media-proof users to the bot (Task 5) without
     hardcoding a username.
   This endpoint is a **read**: it must not spend anything, and a user with no slot still gets a full payload
   (the refusal belongs at the create, not at the form).
2. **`POST /api/v1/miniapp/challenges`** → a `MiniApp\StoreChallengeRequest` (rules bounded by
   `CreateChallenge::limits()` and the enums), then `CreateChallenge::handle()`, returning the created
   challenge through `ChallengeResource` with 201 — the same "the response is the new state" contract the
   check-in endpoint already keeps.
3. **The refusals, as reasons:**
   - no create-slot → `no_create_slot` with `price` and `balance` (Task 3's shape, same readers);
   - the channel gate → `channel_gate` + `join_url`, before anything is spent;
   - `InvalidArgumentException` from the action → this is *our* bug, not the user's, since the request
     validated first: log it with the reason and return a generic failure, the way
     `CreateChallengeWizard::finish()` treats the same exception.
4. **The SPA**: a create screen reachable from the list header, one field per question, a summary step before
   submitting (the bot shows a summary; a creator should see what they are about to spend a slot on), and the
   created challenge landing in the list — which works because of Task 2's merge.
5. Start dates: reuse the constraint the wizard states — a date typed as `Y-m-d`, checked with `checkdate`,
   never before today in the challenge's own timezone. Do not accept a browser-localised date string; the SPA
   sends ISO, the server interprets it in the challenge's timezone, and `CreateChallenge` stores UTC.

### Tests (Pest, required)
- Creating through the API writes one `Challenge`, spends exactly one `CreateSlot`, materialises its periods,
  and returns it through the resource with 201.
- **The created challenge is immediately visible to its creator** in `index` (Task 2's union) — the two tasks'
  contract, asserted together.
- No create-slot → `no_create_slot` with the configured price and the real balance, and **nothing is written**:
  assert no `challenges` row and no spent slot.
- A challenge that is created but not joined reports `has_joined: false` — the creator is not silently enrolled
  by the API path (see the goal file's baked-in decision).
- Validation failures the Form Request owns (a blank title, `total_periods` over the limit, a bad timezone, a
  custom period with no days) return 422 and write nothing.
- A `voice_approval` challenge with no media caps is refused by the action rather than stored half-configured —
  and the options endpoint reports the ceiling the form must clamp to.
- The gate is re-verified at create, not inherited from the token's mint time.
- `GET /challenges/options` never spends: a user with one create-slot who calls it ten times still has one.
- Both locales define every new label and key, with parity.

### Explicitly Out of Scope
- **No timed sessions and no AI approval in this task** — Task 7 adds them. The options endpoint must state
  which flow types this build can create rather than offering a choice the API would refuse.
- No image/voice/video handling of any kind, no file upload, no edit-after-create, no delete.
- No changes to `CreateChallenge`, `ConsumeEntitlement` or `MaterialiseChallengePeriods`.
- No new enum, no new `SettingKey`, no new column.

### Code Rules
- Follow `CLAUDE.md`. The validation floor is the action's; the Form Request is the friendly layer in front of
  it, and the two are kept in step by reading the bounds from `limits()` rather than retyping them.
- Creating is a **privileged action**: gate re-verified, slot spent inside the action's transaction, and the
  announcement dispatched after commit (which `CreateChallenge` already does — do not duplicate it).

Work only on this task.

---

## Phase 19 Task 7: Create — timed sessions and AI approval criteria

### Goal
The Mini App's create screen reaches parity with the bot wizard: a timed-session challenge's step list, and a
media challenge's AI review with creator-written criteria that pass the same injection gate.

### Before starting
- Query graphify: `graphify query "ValidateChallengeStepDesign"`, `graphify query "ScreenApprovalCriteria"`,
  `graphify explain "AiApprovalGate"`, `graphify path "CreateChallengeWizard" "ChallengeStep"`.
- Read `app/Actions/Challenges/ValidateChallengeStepDesign.php` in full — the design-time proof that a session is
  completable inside one period. **Do not reimplement any of it**; the API's job is to collect the step list and
  hand it to `CreateChallenge`, which already calls this.
- Read `app/Services/Telegram/Wizards/CreateChallengeWizard.php:96-100, 440-460, 760-790` — the step loop, its
  states, and its bounds (`STEP_WAIT_MAX`, `VOICE_LIMIT_MAX`, `STEP_LABEL_MAX`). The form's limits are these.
- Read `app/Enums/StepInputType.php` and `database/migrations/2026_08_28_194005_create_challenge_steps_table.php`
  — the step fields: `step_order` (1-based), `input_type`, `min_wait_seconds`, `voice_max_seconds` (voice only),
  `label`.
- Read `app/Actions/Ai/ScreenApprovalCriteria.php` in full. It **never throws**: a provider outage screens as
  `unscreened`, which the caller must treat exactly like `flagged` — criteria stored only on `clean`, mode
  falling back to `manual` otherwise. Reuse it; do not write a second screening call.
- Read `app/Services/Ai/AiApprovalGate.php` and `CreateChallenge`'s AI branch — AI review is allowed only for
  media types the admin has enabled, and the action **refuses** (rather than degrading) when it is not.
- Read `app/Actions/Ai/SuggestApprovalCriteria.php` — the bot's "describe it for me" helper. Decide whether the
  Mini App offers it; if the wiring is more than a call, leave it out and say so in `progress.md`.

### What to Build
1. **The step list**, when `flow_type` is `timed_session`: an ordered array of
   `{input_type, min_wait_seconds, voice_max_seconds?, label?}`, with `step_order` assigned by position, exactly
   as `CreateChallengeWizard::finish()` passes it to the action. Bounds from the wizard's constants; a step list
   the design validator cannot complete inside one period is refused by the action, and that refusal is
   surfaced as a reason the SPA can explain rather than a generic failure.
2. **AI approval**, when `approval_mode` is `ai`: the criteria text is screened through
   `ScreenApprovalCriteria::screen()` **before** `CreateChallenge` is called, and only a `clean` verdict lets
   `ai` through — otherwise the challenge is created with `manual` and the SPA is told the criteria was not
   accepted. This mirrors the wizard's own fallback exactly; the user must not be told "AI will review this"
   for a challenge that fell back to manual.
3. **The options endpoint gains the vocabulary the form needs**: `step_input_types`, `approval_modes`, the
   per-media `ai_allowed` flags (from `AiApprovalGate`, per proof type — an admin may allow image AI and refuse
   voice), the criteria length bound (`CreateChallenge::APPROVAL_CRITERIA_MAX`), and the media-cap ceiling so a
   voice/video challenge's caps can be offered with a floor and a ceiling rather than a free-text guess.
   The `flow_types` list stops saying "not yet" — this task is what makes it true.
4. **The SPA**: a step editor (add/remove/reorder, each step's input type, wait, optional voice limit and label),
   an approval-mode choice shown only for media proof types the gate allows, and a criteria field. A challenge
   with media proof and no caps supplied defaults to the admin ceiling — but say so in the UI, because a creator
   should know they just accepted a 5-minute, 20 MB upload.

### Tests (Pest, required)
- A timed-session challenge created through the API persists its steps in order, 1-based, with the right fields
  — assert the rows, not just that the challenge exists.
- A step list that cannot complete inside one period is refused by `ValidateChallengeStepDesign` through the
  API, and nothing is written (no challenge, no spent slot) — the design check runs before the spend.
- `approval_mode: ai` with criteria that screen `clean` is stored as `ai` with the criteria on the challenge.
- Criteria that screen `flagged` (or `unscreened`, when the provider is unreachable) results in
  `approval_mode: manual` and **no stored criteria** — the fallback is exercised for both verdicts, with the
  provider faked, never called for real.
- `ai` on a proof type the admin has not enabled is refused by `AiApprovalGate`'s rule through the action — the
  API does not get to relax it.
- The options payload's `ai_allowed` flags follow the settings, per media type.
- A voice/video challenge whose caps exceed the ceiling is refused; one that omits them takes the ceiling.
- Both locales define every new key, with parity.

### Explicitly Out of Scope
- No new AI capability, no new provider call, no change to `ScreenApprovalCriteria`'s prompt or
  `ApprovalCriteriaScreening`'s rows.
- No editing a challenge after creation, and no step editing of a live session.
- No changes to `CheckInSession`, `SessionStepFlow` or the session lifecycle — this task only *creates* the
  challenge those flows run on.
- No `ffmpeg`-dependent or environment-probing behaviour: the AI gate's existing answer is the answer.

### Code Rules
- Follow `CLAUDE.md` and `prompts/main-addendum-5.md` §2.11 — admin-gated capability, creator-authored criteria,
  and the anti-injection screening that is the reason criteria exists as a screened field at all.
- Never call a real AI provider in a test. `Http::fake()` / the existing fake clients, throughout.

Work only on this task.

---

## Phase 19 Task 8: The creator reviews proofs from the Mini App

### Goal
A creator can see submitted proofs and approve or reject them without leaving the Mini App — a pending queue
across everything they own, plus each challenge's own pending list — seeing the actual media, not a description
of it.

### Before starting
- Query graphify: `graphify query "ReviewCheckIn"`, `graphify path "ReviewQueueController" "ReviewCheckIn"`,
  `graphify explain "NotifyCheckInVerdict"`.
- Read `app/Actions/CheckIns/ReviewCheckIn.php` in full. Two things it already owns and the API must not
  duplicate: **authorization is re-derived from the row** (`participant->challenge->creator_id === actor`, or an
  admin), and `guardAwaitingVerdict()` re-reads the row inside the transaction so a stale id cannot overwrite a
  settled period. The endpoint passes a verified actor and a check-in id; the action decides everything else.
- Read `app/Http/Controllers/Admin/ReviewQueueController.php` in full — the queue's read shape, the `proof()`
  route that streams from `Storage::disk('local')` behind an auth check, and its note that
  `ReviewCheckIn`/`NotifyCheckInVerdict` are **method-injected** because their binding needs
  `TELEGRAM_BOT_TOKEN`. Reading the queue must not require a bot token; only sending does. The Mini App's
  endpoints inherit that split.
- Read `app/Services/Telegram/NotifyCheckInVerdict.php` — the participant hears the verdict from every surface
  already. Call it; do not invent a second sentence.
- Read `app/Models/CheckIn.php:110-122` (`proofKind()`) — the `image`/`voice`/`video` mapping the preview uses,
  and the same one `NotifyCheckInVerdict` uses to word its rejection.
- Read `app/Http/Resources/MiniApp/ChallengeResource.php` for where a pending count belongs.
- Read `resources/js/miniapp/screens/challenge-detail.tsx` and `app.tsx` — the screen/detail phase machine the
  new screen plugs into.

### What to Build
1. **`GET /api/v1/miniapp/reviews`** — every `Submitted` check-in whose challenge the token's user created (or
   all of them, when they are an admin), newest first, with the participant's name, the challenge, the period
   index and when it was submitted. Bounded and paginated-or-capped the way the admin queue caps at 100, and
   the cap stated in a docblock.
2. **`GET /api/v1/miniapp/reviews/{checkIn}/proof`** — the stored bytes from `Storage::disk('local')`, content
   type resolved the way `ReviewQueueController::proof()` resolves it, **authorized by the same ownership rule**
   (re-derived from the row, never from the URL). A stranger, and even a participant reviewing their own
   submission, gets the uniform 404.
3. **`POST /api/v1/miniapp/reviews/{checkIn}/approve`** and `.../reject` — `ReviewCheckIn::approve()/reject()`
   with the token's user as reviewer, then `NotifyCheckInVerdict` so the participant hears it. Refusals map to
   `{reason}` (`not_the_reviewer`, `already_settled`, `not_awaiting_review`, `value_missing`) using the reasons
   the action already throws. A successful verdict returns the creator's updated queue rather than an empty 204.
4. **The SPA**: a reviews screen listing the queue with an inline preview — `<img>` for a photo, `<audio>` for
   voice, `<video>` for video, all pointed at the gated `/proof` URL — and approve/reject buttons with the
   pending count on the challenge card and detail. The queue refreshes after each verdict (the response carries
   the new state, per this surface's existing contract).
5. A challenge the token's user owns shows its own pending count on the detail screen, so a creator notices the
   queue while looking at the challenge rather than only when visiting the reviews screen.

### Tests (Pest, required)
- The queue lists only check-ins on challenges the token's user created; another creator's submission is absent
  — and asking for its `proof` or its approve/reject returns 404, not 403 (the uniform-hiding rule this surface
  already follows).
- Approving through the API settles the row, stamps `reviewed_by`, moves the streak, and delivers the
  participant's notification — assert all four, since the notification is the half a controller-only
  implementation forgets.
- Rejecting returns the row to a state that accepts another submission, and the participant is told to send
  another photo/voice/video with the wording `NotifyCheckInVerdict` already picks.
- A second verdict on an already-settled row is refused with `already_settled`; a verdict from a non-reviewer is
  refused with `not_the_reviewer`, **even when the check-in id is correct** — the IDOR case, tested explicitly.
- An admin can review through the Mini App the way `ReviewCheckIn` already allows.
- The proof route serves bytes for the owner and 404s for everyone else; a row with no `proof_path` (a tap or a
  typed phrase — which never reaches this queue) is not an error the creator can reach.
- `value_missing` on a quantity challenge's approve is refused rather than silently scoring null.
- Both locales define every new key, with parity.

### Explicitly Out of Scope
- **No message editing in this task** — Task 9 retires the buttons. This task's verdict is complete without it.
- No override/reversal of an AI verdict from the Mini App (the admin panel's `override` route keeps that).
- No bulk approve, no rejection reasons typed by the creator, no per-participant history in the queue.
- No change to what "reviewable" means: the queue is `CheckInStatus::Submitted`, exactly as the admin panel's
  is.

### Code Rules
- Follow `CLAUDE.md`. "**Never trust a client-supplied … `participant_id`** — always re-resolve the actor
  server-side from the verified token and re-check ownership." The check-in id in the route is a lookup key;
  `ReviewCheckIn` is the authorization.
- `ReviewCheckIn` and `NotifyCheckInVerdict` are **method-injected** on this controller, for the reason the
  admin controller states: reading and rendering must not require a bot token.

Work only on this task.

---

## Phase 19 Task 9: The verdict retires the buttons it was given

### Goal
When a proof is decided — from the bot, the Mini App, or the admin panel — the approve/reject buttons on the
creator's notification stop being live. Today they stay under every decided proof, and a creator who taps one
gets a refusal for a decision they already made.

### Before starting
- Query graphify: `graphify query "ProofReviewNotifier"`, `graphify path "ReviewCheckInCallback" "ProofReviewNotifier"`,
  `graphify explain "MessengerPlatform"`, `graphify query "SenderMessage messageId"`.
- Read `app/Services/Telegram/ProofReviewNotifier.php` in full. `notify()` builds the buttons and hands them to
  `sendProofMedia()` or `paragraphs()` — **both return a `SentMessage`, and both return values are discarded.**
  `SentMessage` (`app/Messaging/DTO/SentMessage.php`) carries exactly one field, its docblock saying it holds
  "the platform's id for it — the one field any future use (editing, pinning) would start from". This task is
  that future use.
- Read `app/Messaging/Contracts/MessengerPlatform.php` — its class docblock says the method set is "exactly what
  the codebase calls today — nothing speculative (no `editMessageText` or `getChat`: nothing calls them yet;
  they join when a caller does)". **This task is the caller.** Add the method; do not add `editMessageText` too.
- Read `app/Messaging/Telegram/TelegramMessengerPlatform.php` and `app/Messaging/Bale/BaleMessengerPlatform.php`
  — both already wrap their SDK's calls in `MessengerException`; the new method follows the same shape. Telegram's
  SDK has `editMessageReplyMarkup` (`vendor/irazasyed/telegram-bot-sdk/src/Methods/EditMessage.php:117`).
  **Verify what Bale's API does for an empty keyboard before writing it** — if Bale cannot clear a keyboard,
  implement the closest honest thing and say so in `progress.md`; do not let Bale's limitation change Telegram's
  behaviour.
- Read `app/Services/Telegram/Callbacks/ReviewCheckInCallback.php` — the bot's verdict path, and the surface
  that must stop leaving live buttons behind.
- Read `app/Http/Controllers/Admin/ReviewQueueController.php:180-230` — the panel's verdict path, which settles
  rows whose bot notification is still sitting in the creator's chat.
- Read the `check_ins` migration (`database/migrations/2026_08_24_203356_create_check_ins_table.php`) and the
  later ones that alter it, for the column conventions to follow.

### What to Build
1. **Store where the notification is.** A nullable `check_ins.review_message_id` (unsigned bigint) — the
   Telegram/Bale message id of the creator's notification, written by `ProofReviewNotifier` from the
   `SentMessage` it already receives. No chat id column: the notification always goes to the challenge creator's
   private chat with the bot, and that id is derivable from `creator->platform_user_id` — state that in the
   migration's docblock, because the next reader will ask. No second table, no polymorphic message log.
2. **A way to clear a keyboard**, on the contract and both platforms:
   `removeInlineKeyboard(int|string $chatId, int $messageId): void`. Telegram sends `editMessageReplyMarkup` with
   the keyboard removed (an empty `inline_keyboard`, not an omitted `reply_markup` — check which one actually
   clears it and pin the choice with a test); Bale does whatever its own API supports. Intent on the contract,
   wire format inside the implementations, exactly as every other method there does.
3. **One collaborator, called from every verdict.** `RetireReviewButtons` (or a name you prefer) that takes a
   settled `CheckIn`, resolves the creator and their platform, and asks the platform to clear the keyboard —
   called from `ReviewCheckInCallback`, from the admin panel's `approve`/`reject`/`override`, and from Task 8's
   Mini App endpoints. One rule, three callers; a fourth surface added later gets it by calling the same thing.
4. **Best-effort, and visibly so.** Telegram refuses edits to messages older than about 48 hours, and a
   notification may well be older than that by the time a quiet creator decides. So: log at info with the
   `check_in_id` and the reason, swallow `MessengerException`, and **never fail the verdict because the edit
   failed** — the decision is the thing that must land. Clear `review_message_id` on success so a later
   re-settle cannot try to edit a message twice; leaving it set on failure is fine, and the log is the record.
5. Do **not** delete the message and do not replace its text. The creator keeps the proof they were sent; only
   the buttons go. This is the reporter's own read of the fix ("i think updating the message needed") and it is
   also the right one: the proof is evidence the creator may want to look at again.

### Tests (Pest, required)
- A proof notification stores the message id the platform returned — assert the column against the id in the
  faked send response, not merely that it is non-null.
- A verdict from the **bot** clears the keyboard: assert the recorded `editMessageReplyMarkup` call carries the
  stored message id and the creator's chat id.
- A verdict from the **Mini App** does the same (the Task 8 endpoints, asserted through this collaborator).
- A verdict from the **admin panel** does the same — the third caller, covered explicitly.
- **A failed edit does not fail the verdict**: make the edit throw, and assert the check-in is still settled,
  the participant still notified, and a log line written. A Telegram outage must not strand a decision.
- A check-in with no stored `review_message_id` (notified before this deploy, or a creator the bot could not
  reach) settles without any edit call — and without an error. **`Http::assertNothingSent()` is vacuous with no
  fake installed; install a catch-all fake first** (see `.ai/rules/tests.md`).
- A rejection clears the buttons too — not just an approval.
- The participant-side notification is unchanged in both count and wording: exactly one send to the participant
  per verdict, no extra message to the creator.

### Explicitly Out of Scope
- No `editMessageText`, no message deletion, no editing a caption or the proof itself.
- No retry queue, no scheduled sweep for stale keyboards. An edit that fails is logged and dropped.
- No `file_id` storage, no change to how the proof bytes are delivered.
- No change to `ReviewCheckIn`'s rules, `NotifyCheckInVerdict`'s copy, or the callback payloads.

### Code Rules
- Follow `CLAUDE.md`. The new contract method takes intent and typed parameters; nothing about
  `editMessageReplyMarkup` or `reply_markup` leaks above the implementation.
- Telegram's per-chat rate limit still applies: the edit is a *second* message-shaped call to the same chat. It
  happens on a human tap, not in a fan-out, so it is safe — but do not add a send alongside it.

Work only on this task.
