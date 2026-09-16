<?php

namespace App\Services\Telegram;

use LogicException;

/**
 * The payload behind an inline button, parsed.
 *
 * Telegram gives `callback_data` **64 bytes**, and it is opaque: whatever a button
 * carried when it was sent comes back verbatim when it is tapped, however long ago
 * that was. Two consequences shape this class.
 *
 * First, the format is `action:argument:argument…` — an action word the router can
 * dispatch on, then whatever that action needs. The same shape as `BotCommand` one
 * surface over, for the same reason: one tested place that knows how the string is
 * built and read.
 *
 * Second, **the data is not trusted**. It is a string a client sent us; it names
 * an intent, never an authorization. Nothing here carries a user id, a challenge
 * id or a participant id that a handler then acts on — handlers re-resolve the
 * actor from `callback_query.from` against our own rows and re-check ownership.
 * A tapped button is a request, exactly like a typed message.
 *
 * The `updateId` alongside the data is the one thing here that comes from the
 * envelope rather than the button, and it is carried for **idempotency only —
 * never as an authorization input**. Its one use is letting a handler that spends
 * money derive a stable key for "this delivery of this tap", so a redelivered
 * update replays instead of charging twice.
 */
readonly class BotCallback
{
    /**
     * Telegram's documented limit for `callback_data`.
     */
    public const int MAX_BYTES = 64;

    private const SEPARATOR = ':';

    /**
     * @param  list<string>  $arguments
     * @param  string|null  $updateId  the delivering update, for idempotency keys
     */
    private function __construct(
        public string $action,
        public array $arguments,
        public ?string $updateId = null,
    ) {}

    /**
     * Read a callback out of its raw data, or null if there is nothing to read.
     *
     * Null covers a button from an older deploy whose format has changed, and a
     * `callback_query` with no data at all (a game or inline-mode callback). The
     * caller answers the query either way — leaving it unanswered is what makes a
     * Telegram client spin forever.
     *
     * `$updateId` is optional so that a parser with no envelope to hand — a test,
     * or a caller that only wants to read the action word — still constructs one.
     * A handler that needs it for a payment must treat its absence as a refusal
     * rather than invent a substitute: see `SlotPurchaseCallback`.
     */
    public static function parse(?string $data, ?string $updateId = null): ?self
    {
        $data = trim((string) $data);

        if ($data === '') {
            return null;
        }

        $parts = explode(self::SEPARATOR, $data);

        // `explode` on a non-empty string always yields at least one part, so the
        // shift is safe and re-indexes what is left back into a list.
        $action = trim(array_shift($parts));

        if ($action === '') {
            return null;
        }

        return new self($action, array_map('trim', $parts), $updateId);
    }

    /**
     * Build the data for a button.
     *
     * The length check is an assertion rather than a truncation: over the limit,
     * Telegram rejects the whole `sendMessage`, so a silently shortened payload
     * would be a button that parses into the wrong intent. Both outcomes are bugs
     * in *our* keyboard, so they belong at the point the keyboard is built.
     *
     * @throws LogicException when the encoded data would exceed Telegram's limit
     */
    public static function encode(string $action, string ...$arguments): string
    {
        $data = implode(self::SEPARATOR, [$action, ...$arguments]);

        if (strlen($data) > self::MAX_BYTES) {
            throw new LogicException(
                "Callback data '{$data}' is ".strlen($data).' bytes, over Telegram’s '.self::MAX_BYTES.'.',
            );
        }

        return $data;
    }

    /**
     * One positional argument, or null when the button did not carry it.
     */
    public function argument(int $index): ?string
    {
        $value = $this->arguments[$index] ?? null;

        return $value === '' ? null : $value;
    }
}
