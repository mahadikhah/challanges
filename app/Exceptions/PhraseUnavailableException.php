<?php

namespace App\Exceptions;

use App\Models\CheckIn;
use RuntimeException;
use Throwable;

/**
 * No phrase could be issued for a check-in.
 *
 * Both causes are bugs rather than things a participant did, and both are worth
 * failing loudly for. Issuing *no* phrase leaves someone unable to check in,
 * which they will report; issuing a *broken* one — a raw `:adjective :noun`
 * template, or a duplicate of the phrase the person next to them was given —
 * looks like it worked and quietly voids the mechanic.
 */
class PhraseUnavailableException extends RuntimeException
{
    /**
     * `$attempts` is 0 for a vocabulary failure: nothing was drawn, so nothing
     * was attempted.
     */
    private function __construct(
        public readonly string $locale,
        public readonly ?CheckIn $checkIn,
        public readonly int $attempts,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * `lang/{locale}/phrases.php` is missing, empty, or does not contain the
     * placeholders the generator substitutes into.
     */
    public static function vocabularyMissing(string $locale, string $part): self
    {
        return new self(
            $locale,
            null,
            0,
            "The {$locale} check-in phrase vocabulary has no usable {$part}.",
        );
    }

    /**
     * Every draw collided with a phrase already issued in the same period.
     *
     * In practice this means the vocabulary is far too small for the number of
     * participants — the bundled banks give six figures of combinations — so the
     * fix is more words, not more retries.
     */
    public static function noFreePhrase(CheckIn $checkIn, string $locale, int $attempts, Throwable $previous): self
    {
        return new self(
            $locale,
            $checkIn,
            $attempts,
            sprintf(
                'Could not find a free %s phrase for participant %d in period %d after %d attempts.',
                $locale,
                $checkIn->challenge_participant_id,
                $checkIn->challenge_period_id,
                $attempts,
            ),
            $previous,
        );
    }
}
