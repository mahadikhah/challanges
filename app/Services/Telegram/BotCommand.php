<?php

namespace App\Services\Telegram;

use Illuminate\Support\Str;

/**
 * A slash command, parsed out of a message.
 *
 * Telegram sends commands as ordinary message text, so "what did the user ask
 * for?" is a string-parsing question. Answering it once, here, keeps every
 * command handler from re-deriving it — and keeps the awkward parts (a trailing
 * `@botusername`, a capitalised `/Start` from a phone keyboard, the deep-link
 * payload riding after a space) in one tested place.
 */
readonly class BotCommand
{
    /**
     * @param  string  $name  the command word, lowercased and without its slash
     * @param  string|null  $argument  everything after it, or null when nothing followed
     */
    private function __construct(
        public string $name,
        public ?string $argument,
    ) {}

    /**
     * Read a command out of message text, or null if there isn't one.
     *
     * Free text is not a command and is not an error — once the create-challenge
     * wizard exists, a plain message is how a user answers its questions.
     */
    public static function parse(?string $text): ?self
    {
        $text = trim((string) $text);

        if (! str_starts_with($text, '/')) {
            return null;
        }

        // Split once: a deep-link payload is a single token, but a future command
        // may take an argument with spaces in it, and the whole remainder is the
        // argument either way.
        $parts = preg_split('/\s+/', $text, 2) ?: [];

        // `/start@ourbot` is what Telegram sends when a command is copied out of a
        // group. This surface only serves private chats, where there is exactly
        // one bot to address, so the suffix is stripped rather than checked.
        $name = Str::of((string) ($parts[0] ?? ''))->after('/')->before('@')->lower()->value();

        if ($name === '') {
            return null;
        }

        $argument = trim($parts[1] ?? '');

        return new self($name, $argument === '' ? null : $argument);
    }

    /**
     * The argument, or an empty string — for callers that would rather not
     * branch on null.
     */
    public function argumentOrEmpty(): string
    {
        return $this->argument ?? '';
    }
}
