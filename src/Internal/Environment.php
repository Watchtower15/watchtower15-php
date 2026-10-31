<?php

declare(strict_types=1);

namespace WatchTower15\Internal;

/** @internal */
final class Environment
{
    public const VARIABLES = [
        'apiKey' => 'WT15_API_KEY',
        'metricsToken' => 'WT15_METRICS_TOKEN',
        'logsToken' => 'WT15_LOGS_TOKEN',
        'apiUrl' => 'WT15_API_URL',
        'ingestUrl' => 'WT15_INGEST_URL',
    ];

    private function __construct()
    {
    }

    /**
     * Each variable from `$_SERVER`, then `$_ENV`, then `getenv()`: the first non-empty string.
     *
     * @return array<string, string> Keyed by variable name; only the ones set.
     */
    public static function read(): array
    {
        $values = [];
        foreach (self::VARIABLES as $variable) {
            foreach ([$_SERVER[$variable] ?? null, $_ENV[$variable] ?? null, getenv($variable)] as $candidate) {
                if (is_string($candidate) && trim($candidate) !== '') {
                    $values[$variable] = $candidate;

                    break;
                }
            }
        }

        return $values;
    }
}
