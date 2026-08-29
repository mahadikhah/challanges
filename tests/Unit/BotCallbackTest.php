<?php

use App\Services\Telegram\BotCallback;

/*
 * `callback_data` is the one string in the bot that has to survive a round trip
 * through a Telegram client and come back meaning the same thing. It is opaque to
 * Telegram, capped at 64 *bytes*, and a button sent last week is still tappable
 * today — so the encode side and the parse side have to agree without ever having
 * run in the same process. That is what this tests.
 *
 * A unit test, not a feature test: nothing here touches the container, the
 * database or the Bot API.
 */

describe('encoding', function () {
    it('joins the action and its arguments', function () {
        expect(BotCallback::encode('wz', 'awaiting_period_type', 'daily'))
            ->toBe('wz:awaiting_period_type:daily');
    });

    it('encodes an action on its own', function () {
        expect(BotCallback::encode('gate'))->toBe('gate');
    });

    it('refuses data over Telegram’s limit rather than truncating it', function () {
        // Truncation would be worse than an exception: Telegram rejects the whole
        // `sendMessage` for over-long data, so a shortened payload is a button that
        // parses into a *different* intent. Both are bugs in our keyboard, and this
        // is the line where the keyboard is built.
        expect(fn () => BotCallback::encode('wz', str_repeat('x', BotCallback::MAX_BYTES)))
            ->toThrow(LogicException::class, 'over Telegram');
    });

    it('accepts data of exactly the limit', function () {
        $data = BotCallback::encode('wz', str_repeat('x', BotCallback::MAX_BYTES - 3));

        expect(strlen($data))->toBe(BotCallback::MAX_BYTES);
    });

    it('counts bytes, not characters', function () {
        // Telegram's limit is bytes. A Farsi label is two bytes a character, so a
        // 33-character argument is 66 bytes and must be refused even though it
        // looks half the length of the ASCII one above.
        $farsi = str_repeat('ب', 33);

        expect(mb_strlen($farsi))->toBeLessThan(BotCallback::MAX_BYTES)
            ->and(fn () => BotCallback::encode('wz', $farsi))->toThrow(LogicException::class);
    });

    it('fits the longest payload the create wizard actually builds', function () {
        // The regression this guards: a timezone keyboard that Telegram rejects
        // wholesale, which would look like the wizard silently stalling rather than
        // like an over-long string.
        $longest = BotCallback::encode('wz', 'awaiting_timezone', 'America/Los_Angeles');

        expect(strlen($longest))->toBeLessThanOrEqual(BotCallback::MAX_BYTES);
    });
});

describe('parsing', function () {
    it('reads back what encode wrote', function () {
        $callback = BotCallback::parse(BotCallback::encode('wz', 'awaiting_visibility', 'invite_only'));

        expect($callback)->not->toBeNull()
            ->and($callback->action)->toBe('wz')
            ->and($callback->argument(0))->toBe('awaiting_visibility')
            ->and($callback->argument(1))->toBe('invite_only');
    });

    it('keeps every argument, however many there are', function () {
        $callback = BotCallback::parse('a:b:c:d');

        expect($callback?->arguments)->toBe(['b', 'c', 'd']);
    });

    it('has nothing to read in', function (?string $data) {
        // A `callback_query` with no data at all is a real shape — a game or an
        // inline-mode callback. The handler still has to answer the query, so null
        // is an answer rather than an exception.
        expect(BotCallback::parse($data))->toBeNull();
    })->with([
        'no data' => [null],
        'an empty string' => [''],
        'only whitespace' => ['   '],
        'an empty action' => [':daily'],
        'only separators' => ['::'],
    ]);

    it('trims what a client sent', function () {
        $callback = BotCallback::parse('  wz : awaiting_proof_type : button  ');

        expect($callback?->action)->toBe('wz')
            ->and($callback?->arguments)->toBe(['awaiting_proof_type', 'button']);
    });

    it('reads an argument that was never sent as absent', function () {
        $callback = BotCallback::parse('wz:awaiting_period_type');

        expect($callback?->argument(1))->toBeNull()
            ->and($callback?->argument(99))->toBeNull();
    });

    it('reads an empty argument as absent rather than as an empty choice', function () {
        // `wz::daily` is a malformed button, not a step named ''. Handlers branch on
        // `argument(0) === null`, so an empty segment has to arrive as null or the
        // malformed case falls through to a `ConversationState::tryFrom('')`.
        $callback = BotCallback::parse('wz::daily');

        expect($callback?->argument(0))->toBeNull()
            ->and($callback?->argument(1))->toBe('daily');
    });
});
