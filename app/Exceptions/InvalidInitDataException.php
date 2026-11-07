<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * initData that failed verification, so no Mini App identity stands behind it.
 *
 * One class for every way it can fail because the caller's response is the same
 * for all of them: refuse to authenticate, without saying *why* to the client.
 * The distinction between "tampered" and "outdated" is for the log, not for the
 * reply — a detailed answer would tell an attacker which of their forgeries is
 * closer to working.
 */
class InvalidInitDataException extends RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function malformed(string $reason): self
    {
        return new self("The initData is not a well-formed payload: {$reason}.");
    }

    /**
     * The payload may be perfectly good, but this server cannot check it —
     * there is no bot token to derive the signing key from, so there is nothing
     * a valid hash could have been computed with.
     *
     * Distinct from `tampered()` for the log's sake only: the reply is the same
     * uniform 401. Without this, an unset token escapes as an
     * `InvalidArgumentException` and the caller gets a 500, which reads to the
     * operator as "the Mini App is broken" rather than "the token is missing".
     */
    public static function unverifiable(string $reason): self
    {
        return new self("The initData cannot be verified: {$reason}.");
    }

    public static function tampered(): self
    {
        return new self('The initData hash does not match its contents.');
    }

    public static function outdated(int $ageSeconds, int $maxAgeSeconds): self
    {
        return new self(
            "The initData is {$ageSeconds}s old, past the {$maxAgeSeconds}s window.",
        );
    }
}
