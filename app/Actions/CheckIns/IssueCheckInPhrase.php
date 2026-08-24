<?php

namespace App\Actions\CheckIns;

use App\Exceptions\PhraseUnavailableException;
use App\Models\ChallengeParticipant;
use App\Models\ChallengePeriod;
use App\Models\CheckIn;
use App\Services\Localization;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;

/**
 * Issue the phrase one participant must type to prove one period.
 *
 * **The phrase is per participant per period, and that is the whole mechanic.**
 * A phrase shared by everyone in a period is one message in the group chat away
 * from worthless: the first person to check in pastes it and nobody else has to
 * do the thing. So two participants in the same period get different phrases,
 * and pasting one into the other's check-in fails.
 *
 * The threat here is *sharing*, not guessing — a participant already knows their
 * own phrase, so entropy is not what protects the mechanic. **Distinctness is**,
 * and it is guaranteed by a unique index on `(challenge_period_id,
 * expected_phrase)` plus a re-roll on rejection, not by hoping two draws from a
 * six-figure space never coincide.
 *
 * **A phrase is generated once and persisted, never recomputed.** A participant
 * reads it off a reminder and types it back minutes or hours later; if a second
 * call could produce a different string, the phrase they are looking at would
 * stop being the right answer. So `expected_phrase` is written exactly once per
 * row and every later call is a no-op — re-running period materialisation,
 * re-sending a reminder, or opening the Mini App twice are all safe.
 *
 * **A stored phrase is already in `CheckIn::normalisePhrase()` form**, because
 * `generate()` puts it through that same method. The string shown to the
 * participant and the string their answer is folded into therefore cannot drift
 * apart, and the per-period unique index compares canonical forms — so `Blue
 * Anchor 42` cannot slip past it alongside `blue anchor 42`.
 */
class IssueCheckInPhrase
{
    /**
     * The phrase's trailing number is two digits: quick to read off a screen and
     * retype, while still multiplying the vocabulary by ninety. Never
     * zero-padded — a leading zero is a support message, not entropy.
     */
    private const NUMBER_MIN = 10;

    private const NUMBER_MAX = 99;

    /**
     * How many times to re-roll when a draw is already taken in this period.
     *
     * The bundled banks give six figures of combinations, so a collision is
     * already surprising and five in a row means the vocabulary is far too small
     * for the challenge's size. Failing then is more useful than looping.
     */
    private const MINT_ATTEMPTS = 5;

    public function __construct(
        private readonly OpenCheckIn $open,
        private readonly Localization $localization,
    ) {}

    /**
     * Open this participant's obligation for a period and make sure it carries a
     * phrase, if the challenge asks for one.
     *
     * The composition callers actually want: a reminder job, the bot's check-in
     * prompt and the Mini App all need "the row, ready to show", and doing it in
     * one place keeps them from opening a row and then forgetting the phrase.
     */
    public function forParticipant(ChallengeParticipant $participant, ChallengePeriod $period): CheckIn
    {
        return $this->handle($this->open->handle($participant, $period));
    }

    /**
     * Ensure `$checkIn` carries a phrase, and return it.
     *
     * A row that already has one is returned untouched, whatever locale the
     * participant has since switched to — re-rolling would invalidate a phrase
     * they may be mid-way through typing, and the phrase's job is to be stable,
     * not fresh. Rows on challenges that do not ask for typed proof, and rows
     * already settled, are left alone.
     *
     * Read `->expected_phrase` on the result: null means this challenge does not
     * use phrases.
     *
     * @throws PhraseUnavailableException when the locale has no vocabulary, or
     *                                    every draw was already taken in this period
     */
    public function handle(CheckIn $checkIn): CheckIn
    {
        if (! $this->needsPhrase($checkIn)) {
            return $checkIn;
        }

        // Resolved before the transaction opens: it walks two relations, and the
        // participant's locale cannot meaningfully change underneath us.
        $locale = $this->localeFor($checkIn);

        return DB::transaction(function () use ($checkIn, $locale): CheckIn {
            $this->lock($checkIn);
            $checkIn->refresh();

            // Re-read under the lock. Two reminders for the same row can land
            // together, and without this both would see a null phrase, both
            // generate, and the loser would overwrite the phrase the
            // participant is already reading.
            if (! $this->needsPhrase($checkIn)) {
                return $checkIn;
            }

            return $this->mint($checkIn, $locale);
        });
    }

    /**
     * Draw a fresh phrase in `$locale`, without touching the database.
     *
     * Public so a surface can show an example without issuing one, and so tests
     * can hammer the generator cheaply.
     *
     * `random_int` rather than `mt_rand` on principle: it costs nothing here, and
     * the day a phrase becomes worth predicting is the day nobody remembers this
     * choice was available.
     *
     * @throws PhraseUnavailableException when `lang/{locale}/phrases.php` is
     *                                    missing, empty or malformed
     */
    public function generate(string $locale): string
    {
        $phrase = strtr($this->template($locale), [
            ':adjective' => $this->word($locale, 'adjectives'),
            ':noun' => $this->word($locale, 'nouns'),
            ':number' => (string) random_int(self::NUMBER_MIN, self::NUMBER_MAX),
        ]);

        // Store the canonical form, so what the participant is shown is exactly
        // what their answer is compared against.
        return CheckIn::normalisePhrase($phrase);
    }

    /**
     * Whether this row is still waiting for a phrase.
     *
     * Only `null` counts as unissued. `expected_phrase` is written by this class
     * alone and never to a blank, so treating blank as unissued would only ever
     * paper over a corrupt row — and would re-roll a phrase somebody is looking
     * at to do it.
     */
    private function needsPhrase(CheckIn $checkIn): bool
    {
        return $checkIn->expected_phrase === null
            && ! $checkIn->status->isSettled()
            && $checkIn->participant->challenge->proof_type->expectsText();
    }

    /**
     * Write a phrase that is free within this period, re-rolling on rejection.
     *
     * The unique index is the arbiter rather than a pre-flight `whereExists`:
     * two participants can both find a phrase free and then both insert it, so
     * reacting to the rejection is the only version of this that is safe under
     * concurrency. Retrying inside the open transaction is fine on MySQL, where
     * a duplicate-key error rolls back the statement and not the transaction.
     */
    private function mint(CheckIn $checkIn, string $locale): CheckIn
    {
        $attempts = 0;

        while (true) {
            try {
                $checkIn->update(['expected_phrase' => $this->generate($locale)]);

                return $checkIn;
            } catch (UniqueConstraintViolationException $collision) {
                if (++$attempts >= self::MINT_ATTEMPTS) {
                    throw PhraseUnavailableException::noFreePhrase($checkIn, $locale, $attempts, $collision);
                }
            }
        }
    }

    /**
     * Take the per-row mutex.
     *
     * The locked row is deliberately discarded and `$checkIn` refreshed instead:
     * the caller holds a reference to that instance, and it is the one that has
     * to come back carrying the phrase.
     */
    private function lock(CheckIn $checkIn): void
    {
        CheckIn::query()->whereKey($checkIn->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * The locale this participant reads.
     *
     * `locale` is what they chose in the bot or the Mini App; `language_code` is
     * what Telegram told us on `/start`, a decent guess before they have chosen
     * anything. Resolved per user rather than from `app()->getLocale()`, because
     * this runs from queued jobs where one worker serves everybody in turn and
     * the ambient locale belongs to whoever was handled last.
     */
    private function localeFor(CheckIn $checkIn): string
    {
        $user = $checkIn->participant->user;

        return $this->localization->best($user->locale, $user->language_code);
    }

    /**
     * One word drawn uniformly from a bank.
     */
    private function word(string $locale, string $bank): string
    {
        $words = $this->words($locale, $bank);

        return $words[random_int(0, count($words) - 1)];
    }

    /**
     * A locale's vocabulary for one bank, blanks and non-strings dropped.
     *
     * Read through the translator with an **explicit** locale, not `Config` and
     * not the ambient locale, so a phrase is issued in the language its
     * participant reads.
     *
     * @return non-empty-list<string>
     *
     * @throws PhraseUnavailableException when the bank is missing or unusable
     */
    private function words(string $locale, string $bank): array
    {
        $lines = Lang::get("phrases.{$bank}", [], $locale);
        $words = [];

        if (is_array($lines)) {
            foreach ($lines as $line) {
                if (is_string($line) && trim($line) !== '') {
                    $words[] = trim($line);
                }
            }
        }

        if ($words === []) {
            throw PhraseUnavailableException::vocabularyMissing($locale, $bank);
        }

        return $words;
    }

    /**
     * The locale's phrase shape.
     *
     * Word order is not a translation of the English one — Farsi puts the
     * adjective after the noun — so each locale owns its own template. Both
     * placeholders are required: a template missing one silently narrows the
     * space it draws from, which is the failure that quietly voids the mechanic.
     */
    private function template(string $locale): string
    {
        $template = Lang::get('phrases.template', [], $locale);

        if (! is_string($template)
            || ! str_contains($template, ':adjective')
            || ! str_contains($template, ':noun')) {
            throw PhraseUnavailableException::vocabularyMissing($locale, 'template');
        }

        return $template;
    }
}
