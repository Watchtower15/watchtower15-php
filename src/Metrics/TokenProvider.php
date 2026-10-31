<?php

declare(strict_types=1);

namespace WatchTower15\Metrics;

/**
 * Resolves the metric source token when something is about to be sent, never at construction.
 */
interface TokenProvider
{
    public function token(): string;
}
