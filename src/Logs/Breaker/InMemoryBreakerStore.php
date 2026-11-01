<?php

declare(strict_types=1);

namespace WatchTower15\Logs\Breaker;

use WatchTower15\Internal\Clock;
use WatchTower15\Internal\SystemClock;

/**
 * A breaker store for one process, and for tests.
 */
final class InMemoryBreakerStore implements BreakerStore
{
    private const HOUR_MS = 3_600_000;

    /** @var array<string, Breaker> */
    private array $entries = [];

    /** @var array<string, int> */
    private array $reported = [];

    private readonly Clock $clock;

    public function __construct(?Clock $clock = null)
    {
        $this->clock = $clock ?? new SystemClock();
    }

    public function read(string $key): ?Breaker
    {
        $entry = $this->entries[$key] ?? null;
        if ($entry === null) {
            return null;
        }
        $limit = $this->nowMs() + self::HOUR_MS;
        if ($entry->untilMs > $limit) {
            $entry = new Breaker($limit, $entry->reason);
            $this->entries[$key] = $entry;
        }

        return $entry;
    }

    public function open(string $key, int $untilMs, string $reason): void
    {
        $untilMs = min($untilMs, $this->nowMs() + self::HOUR_MS);
        $current = $this->entries[$key] ?? null;
        if ($current !== null && $current->untilMs > $untilMs) {
            return;
        }
        $this->entries[$key] = new Breaker($untilMs, $reason);
    }

    public function clear(string $key, int $untilMs): void
    {
        if (($this->entries[$key]->untilMs ?? null) === $untilMs) {
            unset($this->entries[$key]);
        }
    }

    public function lastReported(string $key, string $reason): ?int
    {
        return $this->reported[$key . ' ' . $reason] ?? null;
    }

    public function markReported(string $key, string $reason, int $atMs): void
    {
        $this->reported[$key . ' ' . $reason] = $atMs;
    }

    private function nowMs(): int
    {
        return (int) floor((float) $this->clock->now()->format('U.u') * 1000);
    }
}
