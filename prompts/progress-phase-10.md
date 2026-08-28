# Progress — Phase 10: AI Provider Kit & AI-Assisted Approval

## Phase 10 Task 0 — AI provider subsystem (kit) ✅ (2026-08-29)

**Commit:** `feat(ai): provider-account chain, token leases, and the append-only usage ledger`

Built per the `ai-provider-kit-headless` skill (references 01–04), on `laravel/ai ^0.11.0`
as the SDK client.

### What landed

**Schema (5 migrations, seeded):**
- `ai_capabilities` — three fixed rows seeded **is_active=false**: `criteria_generation` (text),
  `criteria_screening` (text), `proof_moderation` (vision). Master switch per capability.
- `ai_provider_accounts` — one credential set per row, `config` as `encrypted:array` (never
  `.env`), driver/model/sort_order, per-dimension token limits, `limit_period` +
  `limit_timezone`, pricing per million, health (`unavailable_until`, `last_failure_reason`,
  `last_succeeded_at`).
- `ai_usage_records` — append-only ledger; unique `idempotency_key`; terminal statuses
  (succeeded/failed/no_usage) refuse updates AND deletes via model hooks.
- `ai_usage_reservations` — the token lease; unique `idempotency_key`
  (`ai:op:subject:runId:attempt:n:account:id`); status state machine
  queued→started→completed→reconciled / failed→released.
- `ai_global_usage_limits` — explicit app-wide budget windows (is_active, half-open
  [starts_at, ends_at)).

**Chain & rotation:**
- `AiProviderChain::forKey()` — `available()` (cooldown-aware) → fall back to `usable()` when
  every account is benched (credentials exist; don't kill the feature behind a timer) → null
  only when nothing is callable. Strict `sort_order` priority, no round-robin.
- `AiClientFactory` registers one SDK connection per account (`ai_{capability}_{accountId}`),
  decrypting credentials and dropping unknown config keys.
- `RunAiProviderChainAction` — the one rotation loop every AI operation runs through. We
  dispatch `ProviderFailedOver` **ourselves** (the SDK's single-provider walk re-throws
  without events); account-cap → next sibling, global-cap → abort; the account that actually
  **answered** (resolved from the response's connection name) is the one credited; total
  outage re-throws the last real provider exception, never a generic "not configured".
- `RecordAiProviderFailover` listener benches accounts: 402 credits → 60 min, rate-limit /
  overloaded / default → 2 min. Scoped per capability (same credential pool can serve
  multiple capabilities).

**Quota (the token lease):**
- `AiQuotaService::reserve()` — locks BOTH scopes (global window + account row) before
  reading consumption; estimate gates the call (total clamped ≥ 1 so a zero estimate can
  never pass every budget); `decision()` takes min() across finite scopes, null = unlimited.
- `claim()` exactly-once (null, not throw, when a concurrent worker claimed it — normal in
  an at-least-once queue). `reconcile()` swaps estimate→truth; **usage missing ⇒ consumed =
  reserved** (missing usage must not be the cheapest outcome).
- `consumedBetween()` — in-flight counts at estimate, released counts zero, reconciled counts
  consumed with its linked record excluded (no double-charge). Half-open windows; account
  windows computed in the **account's** timezone (Tehran day ≠ UTC day). Deadlock retry ×3.

**Accounting:**
- `AiUsageRecorder` — `firstOrCreate` on the retry-stable key; **never throws**; outcome
  derives status; metadata is an **allowlist** (`driver`, `model`, `exception_class`) so
  prompt text or credentials cannot reach the ledger.
- `AiUsageNormalizer` — unwraps `usage`/`usageMetadata`/bare/object shapes, guards integer
  range, flags `contradictory` (keeps both totals) instead of picking a winner.
- `AiUsageCostCalculator` — Brick BigInteger, +500_000 then round **Down** (half-up), rates
  snapshotted on the record; no rate for a used dimension ⇒ null cost ("unknown"), not a
  false zero.

**Wiring:** `AiTextClient` bound to `LaravelAiTextClient` (AnonymousAgent, single named
provider per call). `config/ai_usage.php` carries currency + per-operation estimates.

### Verified findings (SDK, against vendor source)
- `openai_compatible` driver posts **chat-completions** (`{url}/chat/completions`), parses
  `choices[0].message.content` and `usage.prompt_tokens/completion_tokens` — NOT the
  Responses API shape. Tests fake that wire shape.
- Single-provider failover re-throws the final exception without any event → we dispatch it.
- `Provider::additionalConfiguration()` = config minus driver/key/name; base URL from `url`.

### Tests (Pest, 53 AI tests)
- `AiUsageSchemaTest` (10): seeded rows off, terminal immutability, duplicate idempotency
  rejection, encrypted-at-rest, casts, relations.
- `AiProviderRotationTest` (12): chain order & exclusions, capability switch, all-benched
  fallback, standby-answers-on-402 with provenance + 60-min bench, 429/503 → 2-min bench
  (dataset), cooldown persists across calls **and** is scoped per capability, refuse without
  spending, rethrow-real-error, connection registration with decrypted creds + allowlisted
  keys.
- `AiQuotaLedgerTest` (28): estimate clamp, idempotent reserve, account/global refusal,
  inactive+expired windows ignored, single claimant, reconcile-missing vs reconcile-reported,
  no double-charge, in-flight at estimate, released = zero, half-open boundary, Tehran
  window, normalizer shapes (dataset) + contradictory + garbage, retry-stable ledger key,
  metadata allowlist, no_usage outcome, cost math (half-up) + null-cost.
- `AiConsumerPipelineTest` (3): happy path (one reconciled reservation, one ledger row,
  account credited), duplicate settled run refused with zero provider calls, provider
  failure releases the lease and records the attempt.

**Doctrine:** fake at the HTTP layer (`Http::fake` + `preventStrayRequests`), never
`Ai::fake()` (it short-circuits the failover paths under test).

### Quality gate
`sail composer ci:check` green (pint, phpstan lvl 7, 1287 Pest assertions-passing tests,
ESLint/Prettier/tsc — 4 skipped + 1 incomplete are pre-existing).

### Notes for Phase 10 Tasks 1–2
- All three capability rows exist but are switched off; Task 1/2 code must handle
  `AiProviderChain::forKey() === null` (feature-disabled) as a normal path.
- Operations should call `RunAiProviderChainAction` with an `AiOperationIdentity` created
  once per run and serialized across queue retries; estimates come from
  `config('ai_usage.estimates.<operation>')` — add new operations there.

## Phase 10 Task 1: `approval_mode` + criteria generation & screening — DONE

**Commit:** feat(challenges): approval_mode + AI criteria suggestion and injection screening

### What landed
- `ApprovalMode` enum (`manual`/`ai`, HasTranslatedLabel, en+fa labels) + `challenges.approval_mode`
  (default `manual`) and `challenges.approval_criteria` (text, nullable). `Challenge` gained
  `#[Fillable]` entries + casts + `@property` PHPDoc (Laravel 13 attribute fillable — forgetting the
  attribute silently drops the column on create).
- `SuggestApprovalCriteria($title, $description)`: one kit call (`criteria_generation` capability),
  platform-authored system prompt, title/description fenced as `<challenge>` data, clamped to 500
  chars, returns null (never throws) when no provider answers.
- `ScreenApprovalCriteria($text, $creator, ?$challenge)`: one cheap kit call
  (`criteria_screening`) asking ONLY whether the text attempts to redirect an AI reviewer / claim
  system authority / instruct ignoring rules. Strict `PASS` / `FLAG: reason` parsing; anything
  unparseable is `Unscreened` (fail-closed without accusing). Always writes an
  `approval_criteria_screenings` row (verdict Clean/Flagged/Unscreened + submitted_text + reason).
- Wizard wiring: `AwaitingApprovalMode` → (ai) suggestion generated inline →
  `AwaitingApprovalCriteriaConfirm` (Use this / Write my own) → typed path via
  `AwaitingApprovalCriteria` with screening. Flagged/Unscreened → mode reset to manual in the draft,
  criteria dropped, distinct fallback copy (en+fa). No-suggestion → typed path directly. Summary
  shows the criteria for AI-reviewed challenges; `finish()` passes mode+criteria to CreateChallenge.
- `CreateChallenge`: ai mode requires non-empty criteria (InvalidArgumentException otherwise);
  mode/criteria degrade to manual/null for non-`image_approval` proof types; 500-char cap;
  `limits()['approval_criteria_max']` surfaced to prompts.
- `SettingKey::AiApprovalConfidenceThreshold` (default 80) — read by Task 2.
- Lang: 3 wizard states × prompt/error/expected + 5 criteria lines + summary line, en + fa.

### Tests (27 new)
- `tests/Feature/Ai/ApprovalCriteriaTest.php` (19): suggestion clamp + fenced-data prompt shape,
  no-provider null, clean/flagged/unscreened verdicts, 6-dataset injection catalogue, unusual-but-
  honest criteria passes, unparseable answer, provider-500 fail-closed, CreateChallenge invariants
  (ai-without-criteria refused, 501-char cap, non-photo degrade).
- `tests/Feature/Bot/CreateChallengeWizardTest.php` +8: approval branch only for photo proof,
  accept-suggestion-stored-without-screening, decline→typed→clean stored, flagged → manual fallback
  + admin log row, provider-down → manual fallback + unscreened row, no-suggestion path, over-cap
  re-ask, summary line.

### Gotchas hit (worth remembering)
- Promoted constructor property holding an invokable action must be called as `($this->chain)(...)`
  — `$this->chain(...)` is "undefined method" at runtime, silently swallowed by the screening
  catch-block as Unscreened.
- Kit tests must reuse the seeded capability rows (unique key) — `update(['is_active' => true])`,
  not `factory()->create()`.
- `Http::fake()` patterns must be top-level string keys; nesting arrays under a host key silently
  fails to match → StrayRequestException.

**Quality gate:** `sail composer ci:check` green (1315 passed, 4 skipped + 1 incomplete pre-existing;
pint, phpstan lvl 7, eslint, prettier, tsc all clean).
