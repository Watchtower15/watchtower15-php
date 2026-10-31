<?php

declare(strict_types=1);

namespace WatchTower15\Internal;

/** @internal */
final class SystemClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
