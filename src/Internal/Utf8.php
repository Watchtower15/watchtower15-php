<?php

declare(strict_types=1);

namespace WatchTower15\Internal;

/** @internal */
final class Utf8
{
    private function __construct()
    {
    }

    /**
     * At most `$max` bytes, never ending inside a multi-byte character: `mb_strcut($text, 0, $max)` on valid UTF-8, with no
     * `ext-mbstring`.
     */
    public static function cut(string $text, int $max): string
    {
        if (strlen($text) <= $max) {
            return $text;
        }
        $cut = substr($text, 0, $max);
        $next = ord($text[$max]);
        $back = 0;
        while ($next >= 0x80 && $next <= 0xBF && $back < 3 && $cut !== '') {
            $next = ord($cut[strlen($cut) - 1]);
            $cut = substr($cut, 0, -1);
            $back++;
        }

        return $cut;
    }
}
