<?php

declare(strict_types=1);

namespace WatchTower15\Logs\Breaker;

/**
 * One breaker entry: the time sends resume (epoch milliseconds) and why (`volume_limit` or `held`).
 */
final readonly class Breaker
{
    public function __construct(public int $untilMs, public string $reason)
    {
    }
}
