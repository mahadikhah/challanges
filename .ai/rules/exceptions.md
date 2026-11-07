---
paths:
  - 'app/Exceptions/**'
---

# Exceptions

## Never name an exception property $code or $message
`Exception` already declares non-readonly `$code` and `$message`. A promoted `public readonly string $code` on a subclass is a **compile-time fatal** ("Cannot redeclare non-readonly property Exception::$code as readonly") thrown when the class is autoloaded — not at throw time.

Why it bites hard: under Pest's agent output format the whole run dies with exit 1 and **zero output**, so it looks like a segfault rather than a type error. `php -l` passes. Reproduce the real message with `sail artisan tinker --execute 'throw \App\Exceptions\Foo::make();'`.

Prefix the domain noun instead: `$inviteCode`, `$participantId`. See `InviteNotClaimableException`.
