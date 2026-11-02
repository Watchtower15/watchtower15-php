<?php

declare(strict_types=1);

namespace WatchTower15\Tests\Support;

use WatchTower15\Internal\Clock;

class FakeClock implements Clock
{
    public function __construct(public \DateTimeImmutable $now = new \DateTimeImmutable('2026-10-31T12:00:00Z'))
    {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }
}
