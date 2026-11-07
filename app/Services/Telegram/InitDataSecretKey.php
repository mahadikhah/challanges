<?php

namespace App\Services\Telegram;

/**
 * The Mini App signing key, derived in exactly one place.
 *
 * Telegram's chain is `secret_key = HMAC_SHA256(<bot_token>, "WebAppData")`, and
 * the result is **32 raw bytes of key material**, not its hex rendering. The
 * distinction is not cosmetic: HMAC uses a key of exactly one block (64 bytes)
 * verbatim, so the 64-character hex *string* is a different key of a different
 * length, and every MAC computed with it is wrong.
 *
 * This exists as a named function because that one missing argument was written
 * out three times — in the verifier, in the diagnose command's self-test, and in
 * the test fixtures — and was wrong in all three at once. The self-test
 * therefore signed with one wrong key and verified with the same wrong key, so
 * it reported a clean bill of health to the operator while production refused
 * every real user. One definition, one place to be right.
 *
 * `tests/Feature/MiniApp/InitDataGoldenVectorTest.php` pins the output against a
 * value generated outside PHP entirely, so "the app and its tests agree" is no
 * longer evidence that either is correct.
 */
final class InitDataSecretKey
{
    private function __construct() {}

    /**
     * The raw digest — never `bin2hex()`ed, never compared as a string.
     */
    public static function derive(string $botToken): string
    {
        return hash_hmac('sha256', $botToken, 'WebAppData', true);
    }
}
