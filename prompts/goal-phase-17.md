# Project Goal — Phase 17 (Creator Media Review, Bot Discoverability, Period Naming, Mini App Auth)

Continuation of the earlier goal files. Same repo, same conventions, same `CLAUDE.md`. Drives Phase 17 only.

## Before the first run
Phases 1–16 are ✅ (confirm in `prompts/progress.md`). Phase 17 is a **defect-fix phase**: seven issues from real
use of the deployed bot and Mini App, raw in `prompts/issues.md`. Several tasks **modify** already-tested code
(Phase 14's media pipeline, Phase 9's session flow, Phase 3's `/start`) — those originals must be green first.

## North Star (definition of done)
1. **The creator can see the proof.** `CheckInFlow::notifyReviewer()` sends only a sentence and two buttons; a
   creator must get the media *and* the verdict buttons on one message. The timed-session flow notifies the
   creator at all, which it never does today.
2. **No slash-commands required.** The command menu is registered with Telegram, and every message that reads
   "send /create" carries a button instead.
3. **A new user is asked their language before anything else**, and the answer survives the deep-link/invite
   payload they arrived with.
4. **Check-in is never asked for without saying how** — per proof type: tap, type the phrase, send a photo,
   a voice message, a video.
5. **"Period" stops leaking into copy.** A daily challenge says "day", weekly says "week", in en + fa.
6. **The Mini App failure message tells the truth.** One `.catch()` currently labels an auth failure and a
   data-load failure identically: "We could not verify your Telegram identity."
7. Every regression bar in a task file is met, and `sail composer ci:check` passes wholesale.
8. `progress.md` records Task 9's real root cause and confirms the attach re-uploads bytes.

## Operating loop
As the earlier goal files: orient from `progress.md` + `git log`, query graphify before writing code, implement
per `CLAUDE.md`, test with Pest, commit per green task, record, repeat.

## Build order (strict)
- **1 → 2 → 3** — the media chain: messenger capability, then the simple flow, then the session flow.
- **5 → 6 → 7** — register the menu, replace typed-command copy with buttons, then layer the proof-type-aware
  check-in instruction onto the rewritten copy (Task 7 audits the *final* wording, so it runs last).
- **9 early and independent** — the only task ending in a diagnosis rather than a fix. Do not let it block 4–8.
- 4 (language gate) and 8 (period noun) are independent of everything and of each other.

## Baked-in decisions — do NOT stop to ask about these
- **Re-upload bytes; do not re-send by `file_id`.** `check_ins` has no column for one, and `proof_path` is the
  single storage convention. A parallel media-reference column is not worth two sources of truth for where a
  proof lives. Note in `progress.md` if the byte cost proves to matter.
- **Inline buttons, never a persistent reply keyboard.** A reply keyboard sends *text*, and `MessageHandler`
  routes text to the conversation router after the command router — an open wizard would swallow the tap as an
  answer. Inline buttons carry `callback_data` through `CallbackRouter` and cannot collide. Do not add a
  text-matching layer to "fix" it.
- **`period_type` gains a noun, not a reformatted `label()`.** `label()` keeps rendering "Daily" for pickers; the
  new thing is a singular unit word ("day") for interpolation into sentences. Do not change `enums.period_type.*`.
- **The Mini App `/auth` refusal stays uniform.** One 401 with no reason for every `InvalidInitDataException` —
  deliberate anti-reconnaissance, not relaxed. The *client* gets better attribution; the server does not get
  chattier to strangers.
- **No JS test framework.** Task 9's client-side change is covered from PHP (lang-key parity, the diagnostic
  command, the TTL guard). Do not add Vitest/Jest.
- **Every regression bar named in a task file is non-negotiable.**

## When you MAY stop and ask (only these)
- Task 9's diagnosis points at a deployment problem invisible from the repo (unset token, HTTP-only host).
  Leave the code fix in place, note the operational fix in `progress.md` — do not invent credentials.
- A new money/authorization/security question genuinely not covered by `CLAUDE.md` or the addenda.
- Everything else: choose the reasonable default, write it down, keep going.

## Guardrails
- No Redis, no Filament; never hit a real Telegram/Bale/AI-provider endpoint in tests — `Http::fake()` everywhere.
- **Never store or log a raw bot token** — Task 9's diagnostics report whether one is set, never its value.
- Every new string in **both** `lang/en/` and `lang/fa/`, key parity pinned by test. RTL wherever touched.
- Telegram rate limits: ~1 msg/sec per chat. Tasks 2 and 3 must not add a second send per proof — buttons ride
  on the media message's caption.
- Context running low mid-task: commit + update `progress.md` before stopping; resume by pasting this file.
