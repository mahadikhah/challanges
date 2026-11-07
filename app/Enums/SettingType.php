<?php

namespace App\Enums;

/**
 * The value shape of a tunable setting.
 *
 * Values are stored JSON-encoded, so the PHP type round-trips on its own. This
 * enum exists so a bad write can be *rejected* before it lands — a price
 * silently stored as the string "50" instead of the integer 50 is the kind of
 * bug that only surfaces once someone's coin balance is wrong.
 */
enum SettingType: string
{
    case Text = 'text';
    case Integer = 'integer';
    case Boolean = 'boolean';
    case Json = 'json';

    /**
     * Whether a value is acceptable for this type.
     */
    public function matches(mixed $value): bool
    {
        return match ($this) {
            self::Text => is_string($value),
            // is_int() deliberately rejects booleans and numeric strings.
            self::Integer => is_int($value),
            self::Boolean => is_bool($value),
            self::Json => is_array($value),
        };
    }

    /**
     * How this type reads in an error message.
     */
    public function describe(): string
    {
        return match ($this) {
            self::Text => 'a string',
            self::Integer => 'an integer',
            self::Boolean => 'a boolean',
            self::Json => 'an array',
        };
    }
}
