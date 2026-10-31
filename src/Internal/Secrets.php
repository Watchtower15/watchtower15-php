<?php

declare(strict_types=1);

namespace WatchTower15\Internal;

use WatchTower15\ConfigurationException;
use WatchTower15\Credentials\CredentialFormat;
use WatchTower15\Metrics\TokenProvider;

/**
 * The credentials, held outside every object property (D5): a static WeakMap keyed by a handle. A metrics token may be a
 * {@see TokenProvider}: the provider and its memo live here too, never in a property of the client or the provider.
 *
 * @internal
 */
final class Secrets
{
    public const API = 'api';

    public const METRICS = 'metrics';

    public const LOGS = 'logs';

    /** How long a token a provider returned is kept, at most. */
    public const MEMO_SECONDS = 300;

    /** @var \WeakMap<SecretsHandle, array<string, string>>|null */
    private static ?\WeakMap $store = null;

    /** @var \WeakMap<SecretsHandle, array{provider: TokenProvider, memo: ?string, at: int}>|null */
    private static ?\WeakMap $providers = null;

    private function __construct()
    {
    }

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

    /**
     * @return \WeakMap<SecretsHandle, array{provider: TokenProvider, memo: ?string, at: int}>
     */
    private static function providers(): \WeakMap
    {
        if (self::$providers === null) {
            /** @var \WeakMap<SecretsHandle, array{provider: TokenProvider, memo: ?string, at: int}> $created */
            $created = new \WeakMap();
            self::$providers = $created;
        }

        return self::$providers;
    }

    public static function store(
        SecretsHandle $handle,
        #[\SensitiveParameter] ?string $apiKey,
        #[\SensitiveParameter] string|TokenProvider|null $metricsToken,
        #[\SensitiveParameter] ?string $logsToken,
    ): void {
        $values = [];
        if ($apiKey !== null) {
            $values[self::API] = $apiKey;
        }
        if (is_string($metricsToken)) {
            $values[self::METRICS] = $metricsToken;
        }
        if ($logsToken !== null) {
            $values[self::LOGS] = $logsToken;
        }
        $map = self::map();
        $map[$handle] = $values;
        if ($metricsToken instanceof TokenProvider) {
            $providers = self::providers();
            $providers[$handle] = ['provider' => $metricsToken, 'memo' => null, 'at' => 0];
        }
    }

    public static function has(SecretsHandle $handle, string $kind): bool
    {
        return isset(self::map()[$handle][$kind]) || ($kind === self::METRICS && isset(self::providers()[$handle]));
    }

    /**
     * In full: only `Internal\Sender` sets `Authorization` with it. A provider's token is resolved here, when something is about
     * to be sent, and kept for at most {@see self::MEMO_SECONDS} when it is a well-formed metric source token.
     *
     * @throws ConfigurationException when a provider returns an empty or malformed token
     */
    public static function get(SecretsHandle $handle, string $kind, ?int $now = null): ?string
    {
        $fixed = self::map()[$handle][$kind] ?? null;
        if ($fixed !== null || $kind !== self::METRICS) {
            return $fixed;
        }
        $providers = self::providers();
        if (! isset($providers[$handle])) {
            return null;
        }
        $entry = $providers[$handle];
        $now ??= time();
        if ($entry['memo'] !== null && $now - $entry['at'] >= 0 && $now - $entry['at'] <= self::MEMO_SECONDS) {
            return $entry['memo'];
        }
        $token = $entry['provider']->token();
        if ($token === '') {
            throw new ConfigurationException('The metrics token provider returned an empty token.');
        }
        if (! CredentialFormat::isMetricsToken($token)) {
            throw new ConfigurationException(sprintf('The metrics token provider returned a value (`%s`) that is not a metric source token.', CredentialFormat::mask($token)));
        }
        $providers[$handle] = ['provider' => $entry['provider'], 'memo' => $token, 'at' => $now];

        return $token;
    }

    /** A refused provider token is never kept: the next resolution calls the provider again. */
    public static function forgetMemo(SecretsHandle $handle): void
    {
        $providers = self::providers();
        if (isset($providers[$handle])) {
            $entry = $providers[$handle];
            $providers[$handle] = ['provider' => $entry['provider'], 'memo' => null, 'at' => 0];
        }
    }

    /** The masked form; for a provider not yet resolved, `provider (<class>), unresolved`. */
    public static function masked(SecretsHandle $handle, string $kind): ?string
    {
        $fixed = self::map()[$handle][$kind] ?? null;
        if ($fixed !== null) {
            return CredentialFormat::mask($fixed);
        }
        $providers = self::providers();
        if ($kind === self::METRICS && isset($providers[$handle])) {
            $entry = $providers[$handle];

            return $entry['memo'] === null ? 'provider (' . $entry['provider']::class . '), unresolved' : CredentialFormat::mask($entry['memo']);
        }

        return null;
    }

    /** `sha256` of the token: all a publisher ever holds, for a derived idempotency key. */
    public static function tokenHash(SecretsHandle $handle, string $kind, ?int $now = null): ?string
    {
        $value = self::get($handle, $kind, $now);

        return $value === null ? null : hash('sha256', $value);
    }
}
