<?php

declare(strict_types=1);

namespace WatchTower15\Internal\Redis;

/**
 * The few Redis commands the store needs, over phpredis or Predis. Every method throws \RuntimeException when Redis fails.
 *
 * @internal
 */
interface RedisAdapter
{
    /** `HINCRBY` and `EXPIRE` in one pipeline. */
    public function hashIncrement(string $key, string $field, int $by, int $ttl): void;

    /** `HSET` and `EXPIRE` in one pipeline. */
    public function hashSet(string $key, string $field, string $value, int $ttl): void;

    /** `HINCRBY` with no expiry (the error counters). */
    public function hashAdd(string $key, string $field, int $by): void;

    /**
     * @return array<string, string>
     */
    public function hashAll(string $key): array;

    public function delete(string ...$keys): void;

    public function get(string $key): ?string;

    public function set(string $key, string $value): void;

    /** `SET … NX EX`: true for the caller that set it. */
    public function setIfAbsent(string $key, string $value, int $ttl): bool;

    /** `SET … NX` with no expiry: true for the caller that set it. */
    public function setForever(string $key, string $value): bool;

    /** Deletes the key only when it still holds `$value` (a compare-and-delete script). */
    public function deleteIfEquals(string $key, string $value): void;

    /** Seconds to live, -1 without expiry, -2 absent (for tests). */
    public function ttl(string $key): int;
}
