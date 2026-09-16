---
paths:
  - 'tests/**'
---

# Tests

## Http::fake stubs match first-registered, not last
`Http::fake()` **appends** to the stub list and Laravel takes the **first** match, so a catch-all or default fake registered in a top-level `beforeEach` cannot be overridden by a later `Http::fake()` in a test — it silently shadows it and the test asserts against the wrong world.

Register the default on the `describe()` blocks whose world does not vary, and let tests that vary the answer state their own fake. If a test installs its own stubs, do not also register a catch-all for it.

Related: `Http::assertNothingSent()` is **vacuous** with no fake installed — Laravel only records requests once something turns recording on. Add `Http::fake()` (catch-all) in tests that assert nothing was sent, so the assertion has something to be wrong about; `Http::preventStrayRequests()` alone does not turn recording on.

Both traps were hit while adding outward checks to `telegram:miniapp-diagnose` and `telegram:set-menu-button`.
