<?php

namespace App\Enums\Concerns;

use Illuminate\Support\Str;

/**
 * Gives a backed enum a translated, user-facing name.
 *
 * The translation key is derived from the enum's own class name, so
 * `CheckInStatus::Approved` reads `enums.check_in_status.approved` in
 * `lang/{locale}/enums.php`. Deriving it rather than declaring it per enum
 * keeps the language files mechanically predictable: there is exactly one
 * place a key can come from, and a new case needs no wiring beyond its line
 * in the catalogue.
 *
 * @phpstan-require-implements \BackedEnum
 */
trait HasTranslatedLabel
{
    /**
     * Translated name, for the bot, the Mini App and the admin panel alike.
     */
    public function label(): string
    {
        return __(self::translationGroup().'.'.$this->value);
    }

    /**
     * Every case as `value => translated label`, for a picker or a bot keyboard.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * `App\Enums\CheckInStatus` becomes `enums.check_in_status`.
     */
    private static function translationGroup(): string
    {
        return 'enums.'.Str::snake(class_basename(self::class));
    }
}
