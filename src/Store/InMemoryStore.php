<?php

declare(strict_types=1);

namespace WatchTower15\Store;

use WatchTower15\Internal\Clock;
use WatchTower15\Internal\SystemClock;
use WatchTower15\Metrics\BucketStore;
use WatchTower15\Metrics\StoreScope;

/**
 * Arrays in the object: one process (long-running workers, CLI scripts, tests).
 */
final class InMemoryStore implements BucketStore
{
    /** @var array<int, array{count: array<string, int>, level: array<string, float>}> */
    private array $buckets = [];

    private ?int $last = null;

    /** @var array<string, array{token: string, expires: int}> */
    private array $locks = [];

    /** @var array<string, int> */
    private array $claims = [];

    /** @var array<string, int> */
    private array $errors = [];

    private readonly string $instance;

    private readonly Clock $clock;

    public function __construct(?Clock $clock = null)
    {
        $this->clock = $clock ?? new SystemClock();
        $this->instance = bin2hex(random_bytes(16));
    }

    public function scope(): StoreScope
    {
        return StoreScope::Process;
    }

    public function increment(int $bucket, string $key, int $by, int $ttl): void
    {
        $this->buckets[$bucket] ??= ['count' => [], 'level' => []];
        $this->buckets[$bucket]['count'][$key] = ($this->buckets[$bucket]['count'][$key] ?? 0) + $by;
    }

    public function setLevel(int $bucket, string $key, int|float $value, int $ttl): void
    {
        $this->buckets[$bucket] ??= ['count' => [], 'level' => []];
        $this->buckets[$bucket]['level'][$key] = (float) $value;
    }

    public function read(int $bucket): array
    {
        return $this->buckets[$bucket] ?? ['count' => [], 'level' => []];
    }

    public function forget(int $bucket): void
    {
        unset($this->buckets[$bucket]);
    }

    public function lastFlushed(): ?int
    {
        return $this->last;
    }

    public function markFlushed(int $bucket): void
    {
        $this->last = $bucket;
    }

    public function acquireLock(string $name, int $ttl): ?string
    {
        $now = $this->clock->now()->getTimestamp();
        if (isset($this->locks[$name]) && $this->locks[$name]['expires'] > $now) {
            return null;
        }
        $token = bin2hex(random_bytes(8));
        $this->locks[$name] = ['token' => $token, 'expires' => $now + $ttl];

        return $token;
    }

    public function releaseLock(string $name, string $token): void
    {
        if (isset($this->locks[$name]) && hash_equals($this->locks[$name]['token'], $token)) {
            unset($this->locks[$name]);
        }
    }

    public function claimOnce(string $name, int $ttl): bool
    {
        $now = $this->clock->now()->getTimestamp();
        if (isset($this->claims[$name]) && $this->claims[$name] > $now) {
            return false;
        }
        $this->claims[$name] = $now + $ttl;

        return true;
    }

    public function addError(string $reason, int $by = 1): void
    {
        $this->errors[$reason] = ($this->errors[$reason] ?? 0) + $by;
    }

    public function errors(): array
    {
        return array_filter($this->errors, static fn (int $n): bool => $n > 0);
    }

    public function subtractErrors(array $counts): void
    {
        foreach ($counts as $reason => $n) {
            $this->errors[$reason] = ($this->errors[$reason] ?? 0) - $n;
            if ($this->errors[$reason] <= 0) {
                unset($this->errors[$reason]);
            }
        }
    }

    public function instanceId(): string
    {
        return $this->instance;
    }
}
