<?php

declare(strict_types=1);

namespace WatchTower15\Internal\Redis;

use Predis\ClientInterface;

/** @internal */
final class PredisAdapter implements RedisAdapter
{
    private const INCREMENT_AND_EXPIRE = "redis.call('HINCRBY', KEYS[1], ARGV[1], ARGV[2]); redis.call('EXPIRE', KEYS[1], ARGV[3]); return 1";

    private const SET_AND_EXPIRE = "redis.call('HSET', KEYS[1], ARGV[1], ARGV[2]); redis.call('EXPIRE', KEYS[1], ARGV[3]); return 1";

    private const COMPARE_AND_DELETE = "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end";

    public function __construct(private readonly ClientInterface $redis)
    {
    }

    public function hashIncrement(string $key, string $field, int $by, int $ttl): void
    {
        // One round trip, atomic: the increment and the expiry in a script.
        $this->run(fn () => $this->redis->eval(self::INCREMENT_AND_EXPIRE, 1, $key, $field, (string) $by, (string) $ttl));
    }

    public function hashSet(string $key, string $field, string $value, int $ttl): void
    {
        $this->run(fn () => $this->redis->eval(self::SET_AND_EXPIRE, 1, $key, $field, $value, (string) $ttl));
    }

    public function hashAdd(string $key, string $field, int $by): void
    {
        $this->run(fn () => $this->redis->hincrby($key, $field, $by));
    }

    public function hashAll(string $key): array
    {
        $all = $this->run(fn () => $this->redis->hgetall($key));
        $out = [];
        foreach ($all as $field => $value) {
            $out[(string) $field] = is_scalar($value) ? (string) $value : '';
        }

        return $out;
    }

    public function delete(string ...$keys): void
    {
        if ($keys !== []) {
            $this->run(fn () => $this->redis->del($keys));
        }
    }

    public function get(string $key): ?string
    {
        $value = $this->run(fn () => $this->redis->get($key));

        return is_string($value) ? $value : null;
    }

    public function set(string $key, string $value): void
    {
        $this->run(fn () => $this->redis->set($key, $value));
    }

    public function setIfAbsent(string $key, string $value, int $ttl): bool
    {
        $result = $this->run(fn () => $this->redis->set($key, $value, 'EX', $ttl, 'NX'));

        return (string) $result === 'OK';
    }

    public function setForever(string $key, string $value): bool
    {
        $result = $this->run(fn () => $this->redis->set($key, $value, 'NX'));

        return (string) $result === 'OK';
    }

    public function deleteIfEquals(string $key, string $value): void
    {
        $this->run(fn () => $this->redis->eval(self::COMPARE_AND_DELETE, 1, $key, $value));
    }

    public function ttl(string $key): int
    {
        $ttl = $this->run(fn () => $this->redis->ttl($key));

        return (int) $ttl;
    }

    /**
     * @template T
     *
     * @param callable(): T $call
     *
     * @return T
     */
    private function run(callable $call): mixed
    {
        try {
            return $call();
        } catch (\Throwable $e) {
            throw new \RuntimeException('Redis failed: ' . $e->getMessage());
        }
    }
}
