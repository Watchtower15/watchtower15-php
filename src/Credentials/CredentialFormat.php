<?php

declare(strict_types=1);

namespace WatchTower15\Credentials;

/**
 * The shapes of the three credentials, checked locally so a wrong or truncated one never reaches a host.
 *
 * The SDK holds its own copies of the prefixes: the backend mints them, and a change to a minted shape ships an SDK
 * release first.
 */
final class CredentialFormat
{
    public const API_KEY_PREFIX = 'wt15ak_';

    public const METRICS_TOKEN_PREFIX = 'wt15ms_';

    public const LOGS_TOKEN_PREFIX = 'wt15ls_';

    private const BASE62 = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    private function __construct()
    {
    }

    public static function isApiKey(mixed $value): bool
    {
        if (! is_string($value) || strlen($value) !== 77 || ! str_starts_with($value, self::API_KEY_PREFIX)) {
            return false;
        }
        $body = substr($value, 7, 64);
        if (preg_match('/\A[0-9a-f]{64}\z/', $body) !== 1) {
            return false;
        }

        return hash_equals(self::checksum($body), substr($value, 71));
    }

    public static function isMetricsToken(mixed $value): bool
    {
        return self::isToken($value, self::METRICS_TOKEN_PREFIX);
    }

    public static function isLogsToken(mixed $value): bool
    {
        return self::isToken($value, self::LOGS_TOKEN_PREFIX);
    }

    /**
     * What a value is, by its prefix: `api_key`, `metrics_token`, `logs_token`, or null.
     */
    public static function kindOf(string $value): ?string
    {
        return match (true) {
            str_starts_with($value, self::API_KEY_PREFIX) => 'api_key',
            str_starts_with($value, self::METRICS_TOKEN_PREFIX) => 'metrics_token',
            str_starts_with($value, self::LOGS_TOKEN_PREFIX) => 'logs_token',
            default => null,
        };
    }

    /**
     * A masked form: the prefix, `…` and four characters (an API key's are the four before its checksum). A value that
     * does not start with a known prefix shows nothing of itself.
     */
    public static function mask(#[\SensitiveParameter] string $value): string
    {
        $kind = self::kindOf($value);
        if ($kind === null) {
            return '…';
        }
        if ($kind === 'api_key') {
            return self::API_KEY_PREFIX . '…' . (strlen($value) >= 17 ? substr($value, -10, 4) : '');
        }

        return substr($value, 0, 7) . '…' . (strlen($value) >= 11 ? substr($value, -4) : '');
    }

    /**
     * A sentence naming what a value is, for a value given in the wrong slot.
     */
    public static function describe(#[\SensitiveParameter] string $value): string
    {
        return match (self::kindOf($value)) {
            'api_key' => 'an API key (`' . self::mask($value) . '`)',
            'metrics_token' => 'a metric source token (`' . self::mask($value) . '`)',
            'logs_token' => 'a log source token (`' . self::mask($value) . '`)',
            default => 'an unrecognised value (`…`)',
        };
    }

    /** The parameter a kind of value belongs in. */
    public static function parameterFor(string $kind): string
    {
        return match ($kind) {
            'api_key' => 'apiKey',
            'metrics_token' => 'metricsToken',
            default => 'logsToken',
        };
    }

    private static function isToken(mixed $value, string $prefix): bool
    {
        return is_string($value)
            && strlen($value) === 71
            && str_starts_with($value, $prefix)
            && preg_match('/\A[0-9a-f]{64}\z/', substr($value, 7)) === 1;
    }

    private static function checksum(string $body): string
    {
        $number = crc32($body);
        $encoded = '';
        while ($number > 0) {
            $encoded = self::BASE62[$number % 62] . $encoded;
            $number = intdiv($number, 62);
        }

        return str_pad($encoded, 6, '0', STR_PAD_LEFT);
    }
}
