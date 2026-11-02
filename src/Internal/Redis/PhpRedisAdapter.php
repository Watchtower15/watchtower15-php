<?php

declare(strict_types=1);

namespace WatchTower15\Internal\Redis;

/** @internal */
final class PhpRedisAdapter implements RedisAdapter
{
    private const COMPARE_AND_DELETE = "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end";

    public function __construct(private readonly \Redis $redis)
    {
    }

    public function hashIncrement(string $key, string $field, int $by, int $ttl): void
    {
        try {
            $pipe = $this->redis->pipeline();
            $pipe->hIncrBy($key, $field, $by);
            $pipe->expire($key, $ttl);
            $results = $pipe->exec();
        } catch (\RedisException $e) {
            throw new \RuntimeException('Redis failed: ' . $e->getMessage());
        }
        if (! is_array($results) || in_array(false, $results, true)) {
            throw new \RuntimeException('Redis did not accept the increment.');
        }
    }

    public function hashSet(string $key, string $field, string $value, int $ttl): void
    {
        try {
            $pipe = $this->redis->pipeline();
            $pipe->hSet($key, $field, $value);
            $pipe->expire($key, $ttl);
            $results = $pipe->exec();
        } catch (\RedisException $e) {
            throw new \RuntimeException('Redis failed: ' . $e->getMessage());
        }
        if (! is_array($results) || in_array(false, $results, true)) {
            throw new \RuntimeException('Redis did not accept the level.');
        }
    }

    public function hashAdd(string $key, string $field, int $by): void
    {
        $this->check(fn () => $this->redis->hIncrBy($key, $field, $by));
    }

    public function hashAll(string $key): array
    {
        $all = $this->check(fn () => $this->redis->hGetAll($key));
        $out = [];
        foreach (is_array($all) ? $all : [] as $field => $value) {
            $out[(string) $field] = (string) $value;
        }

        return $out;
    }

    public function delete(string ...$keys): void
    {
        if ($keys !== []) {
            $this->check(fn () => $this->redis->del(...$keys));
        }
    }

    public function get(string $key): ?string
    {
        $value = $this->check(fn () => $this->redis->get($key));

        return is_string($value) ? $value : null;
    }

    public function set(string $key, string $value): void
    {
        $this->check(fn () => $this->redis->set($key, $value));
    }

    public function setIfAbsent(string $key, string $value, int $ttl): bool
    {
        return $this->check(fn () => $this->redis->set($key, $value, ['nx', 'ex' => $ttl])) === true;
    }

    public function setForever(string $key, string $value): bool
    {
        return $this->check(fn () => $this->redis->set($key, $value, ['nx'])) === true;
    }

    public function deleteIfEquals(string $key, string $value): void
    {
        $this->check(fn () => $this->redis->eval(self::COMPARE_AND_DELETE, [$key, $value], 1));
    }

    public function ttl(string $key): int
    {
        $ttl = $this->check(fn () => $this->redis->ttl($key));

        return is_int($ttl) ? $ttl : -2;
    }

    /**
     * @template T
     *
     * @param callable(): T $call
     *
     * @return T
     */
    private function check(callable $call): mixed
    {
        try {
            $result = $call();
        } catch (\RedisException $e) {
            throw new \RuntimeException('Redis failed: ' . $e->getMessage());
        }
        if ($result === false && $this->redis->getLastError() !== null) {
            throw new \RuntimeException('Redis failed: ' . (string) $this->redis->getLastError());
        }

        return $result;
    }
}
