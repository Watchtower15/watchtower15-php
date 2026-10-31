<?php

declare(strict_types=1);

namespace WatchTower15\Tests\Support;

/** Valid credentials, built independently of the SDK's own check. */
final class Keys
{
    public static function apiKey(string $hexSeed = 'a'): string
    {
        $body = substr(str_repeat($hexSeed, 64), 0, 64);
        $alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $n = crc32($body);
        $out = '';
        while ($n > 0) {
            $out = $alphabet[$n % 62] . $out;
            $n = intdiv($n, 62);
        }

        return 'wt15ak_' . $body . str_pad($out, 6, '0', STR_PAD_LEFT);
    }

    public static function metrics(string $hex = 'b'): string
    {
        return 'wt15ms_' . substr(str_repeat($hex, 64), 0, 64);
    }

    public static function logs(string $hex = 'c'): string
    {
        return 'wt15ls_' . substr(str_repeat($hex, 64), 0, 64);
    }
}
