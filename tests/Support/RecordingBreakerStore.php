<?php

declare(strict_types=1);

namespace WatchTower15\Tests\Support;

use WatchTower15\Logs\Breaker\Breaker;
use WatchTower15\Logs\Breaker\BreakerStore;

/** Wraps a breaker store and records every call as `<method> <key>`. */
final class RecordingBreakerStore implements BreakerStore
{
    /** @var list<string> */
    public array $calls = [];

    public function __construct(public readonly BreakerStore $inner)
    {
    }

    public function count(string $method): int
    {
        return count(array_filter($this->calls, static fn (string $c): bool => str_starts_with($c, $method . ' ')));
    }

    public function read(string $key): ?Breaker
    {
        $this->calls[] = 'read ' . $key;

        return $this->inner->read($key);
    }

    public function open(string $key, int $untilMs, string $reason): void
    {
        $this->calls[] = 'open ' . $key;
        $this->inner->open($key, $untilMs, $reason);
    }

    public function clear(string $key, int $untilMs): void
    {
        $this->calls[] = 'clear ' . $key;
        $this->inner->clear($key, $untilMs);
    }

    public function lastReported(string $key, string $reason): ?int
    {
        $this->calls[] = 'lastReported ' . $key;

        return $this->inner->lastReported($key, $reason);
    }

    public function markReported(string $key, string $reason, int $atMs): void
    {
        $this->calls[] = 'markReported ' . $key;
        $this->inner->markReported($key, $reason, $atMs);
    }
}
