<?php

declare(strict_types=1);

namespace WatchTower15\Internal;

use WatchTower15\Credentials\CredentialFormat;

/**
 * The credentials, held outside every object property (D5): a static WeakMap keyed by a handle.
 *
 * @internal
 */
final class Secrets
{
    public const API = 'api';

    public const METRICS = 'metrics';

    public const LOGS = 'logs';

    /** @var \WeakMap<SecretsHandle, array<string, string>>|null */
    private static ?\WeakMap $store = null;

    /**
     * @return \WeakMap<SecretsHandle, array<string, string>>
     */
    private static function map(): \WeakMap
    {
        if (self::$store === null) {
            /** @var \WeakMap<SecretsHandle, array<string, string>> $created */
            $created = new \WeakMap();
            self::$store = $created;
        }

        return self::$store;
    }

    private function __construct()
    {
    }

    public static function store(
        SecretsHandle $handle,
        #[\SensitiveParameter] ?string $apiKey,
        #[\SensitiveParameter] ?string $metricsToken,
        #[\SensitiveParameter] ?string $logsToken,
    ): void {
        $values = [];
        if ($apiKey !== null) {
            $values[self::API] = $apiKey;
        }
        if ($metricsToken !== null) {
            $values[self::METRICS] = $metricsToken;
        }
        if ($logsToken !== null) {
            $values[self::LOGS] = $logsToken;
        }
        $map = self::map();
        $map[$handle] = $values;
    }

    public static function has(SecretsHandle $handle, string $kind): bool
    {
        return isset(self::map()[$handle][$kind]);
    }

    /** In full: only `Internal\Sender` sets `Authorization` with it. */
    public static function get(SecretsHandle $handle, string $kind): ?string
    {
        return self::map()[$handle][$kind] ?? null;
    }

    public static function masked(SecretsHandle $handle, string $kind): ?string
    {
        $value = self::get($handle, $kind);

        return $value === null ? null : CredentialFormat::mask($value);
    }

    /** `sha256` of the token: all a publisher ever holds, for a derived idempotency key. */
    public static function tokenHash(SecretsHandle $handle, string $kind): ?string
    {
        $value = self::get($handle, $kind);

        return $value === null ? null : hash('sha256', $value);
    }
}
