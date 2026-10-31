<?php

declare(strict_types=1);

namespace WatchTower15\Metrics;

/**
 * Where minute buckets wait for the flush. Every data, lock, claim, error-counter and instance-id call throws when the backend fails;
 * `scope()` is a constant of the class and no constructor touches the backend.
 */
interface BucketStore
{
    public function scope(): StoreScope;

    /** Atomic: the key is added at 0 when absent, then `$by` is added. */
    public function increment(int $bucket, string $key, int $by, int $ttl): void;

    /** Last write wins. */
    public function setLevel(int $bucket, string $key, int|float $value, int $ttl): void;

    /**
     * @return array{count: array<string, int>, level: array<string, float>}
     */
    public function read(int $bucket): array;

    public function forget(int $bucket): void;

    public function lastFlushed(): ?int;

    public function markFlushed(int $bucket): void;

    /** Non-blocking: a token, or null when the lock is held. */
    public function acquireLock(string $name, int $ttl): ?string;

    public function releaseLock(string $name, string $token): void;

    /** Add-if-absent with a TTL: true for the one caller that made the claim. */
    public function claimOnce(string $name, int $ttl): bool;

    public function addError(string $reason, int $by = 1): void;

    /**
     * @return array<string, int>
     */
    public function errors(): array;

    /**
     * @param array<string, int> $counts
     */
    public function subtractErrors(array $counts): void;

    /** 32 hex characters, made once and kept in the store. */
    public function instanceId(): string;
}
