<?php

declare(strict_types=1);

namespace WatchTower15\Internal;

use WatchTower15\ConfigurationException;

/** @internal */
final class Requirement
{
    private const NAMES = [
        Secrets::API => ['apiKey', 'WT15_API_KEY'],
        Secrets::METRICS => ['metricsToken', 'WT15_METRICS_TOKEN'],
        Secrets::LOGS => ['logsToken', 'WT15_LOGS_TOKEN'],
    ];

    private function __construct()
    {
    }

    /**
     * @throws ConfigurationException when the credential is not configured
     */
    public static function credential(SecretsHandle $handle, string $kind): void
    {
        if (! Secrets::has($handle, $kind)) {
            [$parameter, $variable] = self::NAMES[$kind];

            throw new ConfigurationException(sprintf('This call needs `%s` (the %s environment variable), which is not configured.', $parameter, $variable));
        }
    }
}
