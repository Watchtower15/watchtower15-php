<?php

declare(strict_types=1);

namespace WatchTower15\Metrics;

/**
 * The counters of one flush run.
 *
 * @internal
 */
final class FlushRun
{
    public int $bucketsSent = 0;

    public int $bucketsEmpty = 0;

    public int $bucketsDropped = 0;

    public int $chunksDropped = 0;

    public int $requests = 0;

    public int $points = 0;

    /** @var array<string, int> */
    public array $rejected = [];

    public ?string $stop = null;

    public bool $heartbeatAdded = false;
}
