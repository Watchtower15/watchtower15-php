<?php

declare(strict_types=1);

namespace WatchTower15\Testing;

use WatchTower15\Internal\Clock;
use WatchTower15\Internal\Sleeper;

/**
 * A clock and a sleeper in one: sleeping only advances the clock, so the waiter's polls and `autoPaging()`'s pacing run in a test
 * without waiting (plan WT15-SDK-00007 D26).
 */
final class FakeTime implements Clock, Sleeper
{
    /** @var list<float> every wait asked for, in order */
    public array $waits = [];

    public function __construct(private \DateTimeImmutable $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
    {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function sleep(float $seconds): void
    {
        $this->waits[] = $seconds;
        $this->now = $this->now->modify(sprintf('+%d microseconds', (int) round($seconds * 1_000_000)));
    }
}
