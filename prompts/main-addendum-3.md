# Main Prompt Addendum 3 — Bale Multi-Platform & Documentation

Append after `prompts/main-addendum-2.md`. §2.9 for spec, §3.8 for architecture, §5 gets Phases 11–12, §7 gets
new open questions. Assumes Phases 1–10 (bot core, payments, Mini App, admin, website, creator chats, timed
sessions, AI approval) exist as built.

---

## 2.9 Bale — second messenger platform

The same product runs on Bale (an Iranian Telegram-API-alike) in addition to Telegram: same challenges, same
economy, same bot conversations — a different transport underneath. Bale also gets its own payment rail
(Bale Pay) alongside Telegram Stars, both crediting the same `CoinLedger`.

**Account model:** a Bale identity and a Telegram identity are **separate app accounts** for now, even for
the same real person. No auto-linking. Cross-platform account merging is a real feature (needs a verified
login path, most naturally through the website) and is explicitly deferred, not accidentally solved here.

**Feature parity is not assumed.** Bale's Bot API is Telegram-shaped but not guaranteed identical — inline
keyboards, voice message support, forwarded-message chat discovery (used by creator-chat registration, §2.6)
and webhook secret validation may all differ in some detail. Verify each one actually used by this codebase
against Bale's own Bot API docs before relying on it; where Bale genuinely lacks something Telegram has,
degrade gracefully (e.g. text-only creator-chat linking instructions if forward-based discovery isn't
available) rather than assuming parity and finding out in production.

---

## 3.8 Architecture — the messenger abstraction

This only works if the *existing* Telegram-specific bot code (Phases 3, 8, 9 — webhook handling, wizards,
check-ins, reminders, creator-chat posting, timed sessions) sits behind a platform contract instead of
calling the Telegram SDK/HTTP client directly. That contract doesn't exist yet, so **Task 11.1 is a pure
refactor of already-built, already-tested code** before any Bale-specific line is written:

```
MessengerPlatform (contract)
├── TelegramMessengerPlatform   (existing behavior, now behind the interface)
└── BaleMessengerPlatform       (new)
```

Every Action or job that currently sends a message, edits one, checks chat membership, fetches a file, or
creates an invoice must be updated to depend on `MessengerPlatform`, resolved from the *update's* platform —
not on the Telegram SDK directly. Reminders and creator-chat posting jobs pick the platform from the
recipient/chat's stored `platform`, same as the webhook handler picks it from the incoming update.

This is the highest-regression-risk task in the project so far, because it touches code that's already
shipped and tested. It is not done until the **entire existing Pest suite for Phases 3, 8, and 9 still
passes unchanged in its assertions**, just re-targeted through the interface.

---

## 5. Roadmap — Phases 11–12

**Phase 11 — Bale.** Task 1: messenger platform abstraction (refactor only, no new platform yet). Task 2:
Bale webhook + `BaleMessengerPlatform`, routed through the same Actions Telegram already uses. Task 3: Bale
Pay into `CoinLedger`, using the `bale-payments` skill.

**Phase 12 — Documentation.** Only starts once Phases 1–11 are all ✅. Task 1: `README.md`. Task 2: setup
guides for shared cPanel hosting and Ubuntu VPS. Task 3: a user/creator/admin flow document. Every one of
these ships as an English `.md` and a separate Farsi `.fa.md` sibling.

## 7. New open product questions

1. **Cross-platform account linking** — deferred. Note it as a known gap in the README's roadmap/limitations
   section (Phase 12) so it's not a surprise later.
2. **Bale webhook secret mechanism** — confirm it against Bale's docs; don't assume it's the same
   `secret_token` header Telegram uses.
3. **Bale refund support** — if Bale Pay doesn't expose a refund endpoint, say so explicitly in Task 11.3
   rather than approximating one.
