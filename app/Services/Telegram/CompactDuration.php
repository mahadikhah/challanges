<?php

namespace App\Services\Telegram;

/**
 * A length of time as a compact, locale-neutral string: "2d 3h", "5m", "45s".
 *
 * The bot shows waits in two places — the wizard's design summary and a
 * session's step instructions — and both must mean the same thing by the same
 * numbers, which is why the format lives here rather than twice. Deliberately
 * not Carbon's `forHumans()`: that renders in whatever locale Carbon last used,
 * which in a queue worker is not necessarily the recipient's, and these unit
 * symbols read the same in both of the platform's languages.
 */
class CompactDuration
{
    /**
     * @param  array{0: string, 1: int}[]  $units
     */
    private const array UNITS = [
        ['d', 86_400],
        ['h', 3_600],
        ['m', 60],
    ];

    public static function format(int $seconds): string
    {
        $parts = [];

        foreach (self::UNITS as [$unit, $size]) {
            if ($seconds >= $size) {
                $parts[] = intdiv($seconds, $size).$unit;
                $seconds %= $size;
            }
        }

        if ($parts === [] || $seconds > 0) {
            $parts[] = "{$seconds}s";
        }

        return implode(' ', $parts);
    }
}
