<?php

declare(strict_types=1);

namespace WatchTower15\Internal;

/** @internal */
final class UserAgent
{
    private function __construct()
    {
    }

    public static function build(string $sdkVersion, string $phpVersion, ?string $framework, ?string $appInfo): string
    {
        $version = ltrim($sdkVersion, 'v');
        $version = (string) preg_replace('/[^0-9A-Za-z.+-]/', '-', $version);
        $version = substr($version, 0, 47);
        if ($version === '') {
            $version = 'dev';
        }
        $header = 'watchtower15-php/' . $version . ' php/' . $phpVersion;
        if ($framework !== null && $framework !== '') {
            $header .= ' ' . $framework;
        }
        if ($appInfo !== null && $appInfo !== '') {
            $header .= ' ' . $appInfo;
        }

        return $header;
    }
}
