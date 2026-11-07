<?php

namespace App\Services\Telegram;

use App\Models\Challenge;
use App\Models\User;
use App\Services\Localization;
use Illuminate\Support\Facades\Lang;

/**
 * A challenge's cadence as a word: a daily one is counted in days, a weekly one
 * in weeks.
 *
 * The bot used to call every one of them a "period", which is our word for the
 * row in `challenge_periods` and not the participant's word for anything. Somebody
 * in a daily challenge checks in *every day*; "check in every period" asks them
 * to work out what a period is before they can work out what the sentence means,
 * and the challenge told them the answer eleven questions ago.
 *
 * **The count is in the unit, not in periods.** "10 days" is what a daily
 * challenge ten periods long is, and a `custom` challenge of six 3-day periods is
 * "18 days" — so `total()`, `opening()` and `closing()` convert, and a sentence
 * that numbers a check-in says which *day* it is rather than which period.
 * Custom's noun is `day`: a 3-day cadence has no other honest name, and inventing
 * one ("round", "stretch") would be a word the product never uses anywhere else.
 *
 * **The noun lives in `enums.period_unit`, not in `enums.period_type`.** Those are
 * picker labels — "Daily", "Weekly" — read back to a creator choosing a cadence,
 * and they are unchanged. This group is the noun those labels imply.
 *
 * **Two forms, because English and Farsi disagree about numerals.** English wants
 * "3 days" and Farsi wants "۳ روز" — the bare singular after a numeral, the rule
 * the rest of the Farsi catalogue already follows. So the catalogue carries
 * `one` and `after_number`, and `spelled()` picks between them; a pluralizer
 * would be a framework feature for two words.
 */
class PeriodUnit
{
    public function __construct(
        private readonly BotMessenger $messenger,
        private readonly Localization $localization,
    ) {}

    /**
     * The noun for a single check-in window: "day", "week", "month".
     *
     * Used where the sentence numbers one — "day 2 of 10" — which is why it stays
     * singular even when the number beside it is not.
     *
     * @param  User|null  $user  the recipient, or null where the sentence is a
     *                           broadcast. See `localeFor()`.
     */
    public function one(?User $user, Challenge $challenge): string
    {
        return $this->noun($this->localeFor($user), $challenge, 'one');
    }

    /**
     * One check-in window's own name, spanning as many days as it does.
     *
     * "day", "week" — or "3 days" for a custom challenge, because "check in every
     * 3 days" is what its creator actually configured. A one-day custom challenge
     * reads as "day" rather than "1 day", the same sentence a daily one gets.
     */
    public function span(?User $user, Challenge $challenge): string
    {
        $days = $this->daysPerPeriod($challenge);

        return $days === 1
            ? $this->one($user, $challenge)
            : $this->spelled($user, $challenge, $days);
    }

    /**
     * The whole challenge's length, in the challenge's own unit: "10 days",
     * "18 days" for a custom one of six 3-day periods.
     */
    public function length(?User $user, Challenge $challenge): string
    {
        return $this->spelled($user, $challenge, $this->total($challenge));
    }

    /**
     * How many of the unit the challenge spans in total.
     *
     * `custom_period_days` is what makes a custom challenge count in days: six
     * periods of three days is eighteen days, not six of anything.
     */
    public function total(Challenge $challenge): int
    {
        return $challenge->total_periods * $this->daysPerPeriod($challenge);
    }

    /**
     * Which day of the challenge a period opens on.
     *
     * `$index` is `ChallengePeriod::$index`, zero-based. A daily challenge's
     * second period opens on day 2; a custom 3-day challenge's second opens on
     * day 4, which is the number its participant is living through.
     */
    public function opening(Challenge $challenge, int $index): int
    {
        return $index * $this->daysPerPeriod($challenge) + 1;
    }

    /**
     * Which day of the challenge a period closes on — its last one, not its
     * first, so a "this closes at …" sentence ends on the day it ends.
     */
    public function closing(Challenge $challenge, int $index): int
    {
        return ($index + 1) * $this->daysPerPeriod($challenge);
    }

    /**
     * A count with its noun: "1 day", "18 days", "۳ روز".
     *
     * Composed rather than translated as a whole, because the number is a plain
     * integer in both locales and every sentence that needs one also needs
     * arithmetic the catalogue cannot do. The catalogue carries only the noun.
     */
    private function spelled(?User $user, Challenge $challenge, int $count): string
    {
        $noun = $this->noun(
            $this->localeFor($user),
            $challenge,
            $count === 1 ? 'one' : 'after_number',
        );

        return "{$count} {$noun}";
    }

    private function noun(string $locale, Challenge $challenge, string $form): string
    {
        $key = "enums.period_unit.{$challenge->period_type->value}.{$form}";

        return $this->line($locale, $key);
    }

    /**
     * How many days one period spans — one for every type but `custom`, which is
     * the only one whose unit is smaller than its period.
     */
    private function daysPerPeriod(Challenge $challenge): int
    {
        // A custom challenge without a day count is one the materialiser refuses
        // to build a timeline for. Reading it as a single day keeps the copy
        // arithmetic total rather than dividing a message by a missing number.
        return max(1, $challenge->customPeriodDays() ?? 1);
    }

    /**
     * The locale a line renders in: the recipient's, or the platform fallback
     * where there is no recipient.
     *
     * A channel announcement and a linked chat's post are read by whoever is
     * passing, in whichever language they read — the same choice
     * `ChannelBroadcaster` and `ChatBroadcaster` make, and for the same reason:
     * there is no one to resolve against.
     */
    private function localeFor(?User $user): string
    {
        return $user === null
            ? $this->localization->fallback()
            : $this->messenger->localeFor($user);
    }

    private function line(string $locale, string $key): string
    {
        $line = Lang::get($key, [], $locale);

        return is_string($line) ? $line : $key;
    }
}
