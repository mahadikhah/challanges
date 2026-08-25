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
     */
    private function __construct(
        public string $action,
        public array $arguments,
    ) {}

    /**
     * Read a callback out of its raw data, or null if there is nothing to read.
     *
     * Null covers a button from an older deploy whose format has changed, and a
     * `callback_query` with no data at all (a game or inline-mode callback). The
     * caller answers the query either way — leaving it unanswered is what makes a
     * Telegram client spin forever.
     */
    public static function parse(?string $data): ?self
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

        return new self($action, array_map('trim', $parts));
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
