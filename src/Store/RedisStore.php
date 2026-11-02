<?php

declare(strict_types=1);

namespace WatchTower15\Store;

use Predis\ClientInterface as PredisClient;
use WatchTower15\ConfigurationException;
use WatchTower15\Internal\Redis\PhpRedisAdapter;
use WatchTower15\Internal\Redis\PredisAdapter;
use WatchTower15\Internal\Redis\RedisAdapter;
use WatchTower15\Metrics\BucketStore;
use WatchTower15\Metrics\StoreScope;

/**
 * Minute buckets in Redis (or Valkey), through phpredis or Predis: the fleet's store. A single primary; Redis Cluster is not
 * supported. The prefix has no default: two applications on one Redis database must each name their own.
 */
final class RedisStore implements BucketStore
{
    private readonly RedisAdapter $redis;

    /**
     * @throws ConfigurationException when the prefix is empty
     */
    public function __construct(\Redis|PredisClient $redis, private readonly string $prefix)
    {
        if (trim($prefix) === '') {
            throw new ConfigurationException('RedisStore needs a prefix, one per application: two applications on one Redis database would share buckets.');
        }
        $this->redis = $redis instanceof \Redis ? new PhpRedisAdapter($redis) : new PredisAdapter($redis);
    }

    public function scope(): StoreScope
    {
        return StoreScope::Fleet;
    }

    public function increment(int $bucket, string $key, int $by, int $ttl): void
    {
        $this->redis->hashIncrement($this->bucketKey($bucket, 'c'), $key, $by, $ttl);
    }

    public function setLevel(int $bucket, string $key, int|float $value, int $ttl): void
    {
        $this->redis->hashSet($this->bucketKey($bucket, 'l'), $key, json_encode((float) $value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), $ttl);
    }

    public function read(int $bucket): array
    {
        $count = [];
        foreach ($this->redis->hashAll($this->bucketKey($bucket, 'c')) as $key => $value) {
            $count[$key] = (int) $value;
        }
        $level = [];
        foreach ($this->redis->hashAll($this->bucketKey($bucket, 'l')) as $key => $value) {
            $decoded = json_decode($value);
            $level[$key] = is_numeric($decoded) ? (float) $decoded : 0.0;
        }

        return ['count' => $count, 'level' => $level];
    }

    public function forget(int $bucket): void
    {
        $this->redis->delete($this->bucketKey($bucket, 'c'), $this->bucketKey($bucket, 'l'));
    }

    public function lastFlushed(): ?int
    {
        $last = $this->redis->get($this->prefix . ':last');

        return $last === null ? null : (int) $last;
    }

    public function markFlushed(int $bucket): void
    {
        $this->redis->set($this->prefix . ':last', (string) $bucket);
    }

    public function acquireLock(string $name, int $ttl): ?string
    {
        $token = bin2hex(random_bytes(16));

        return $this->redis->setIfAbsent($this->prefix . ':lock:' . $name, $token, $ttl) ? $token : null;
    }

    public function releaseLock(string $name, string $token): void
    {
        $this->redis->deleteIfEquals($this->prefix . ':lock:' . $name, $token);
    }

    public function claimOnce(string $name, int $ttl): bool
    {
        return $this->redis->setIfAbsent($this->prefix . ':claim:' . $name, '1', $ttl);
    }

    public function addError(string $reason, int $by = 1): void
    {
        $this->redis->hashAdd($this->prefix . ':errors', $reason, $by);
    }

    public function errors(): array
    {
        $errors = [];
        foreach ($this->redis->hashAll($this->prefix . ':errors') as $reason => $count) {
            if ((int) $count > 0) {
                $errors[$reason] = (int) $count;
            }
        }

        return $errors;
    }

    public function subtractErrors(array $counts): void
    {
        foreach ($counts as $reason => $count) {
            $this->redis->hashAdd($this->prefix . ':errors', $reason, -$count);
        }
    }

    public function instanceId(): string
    {
        $key = $this->prefix . ':instance';
        $this->redis->setForever($key, bin2hex(random_bytes(16)));

        return (string) $this->redis->get($key);
    }

    /** @internal for tests: the seconds to live of a bucket's count hash. */
    public function bucketTtl(int $bucket): int
    {
        return $this->redis->ttl($this->bucketKey($bucket, 'c'));
    }

    /** @internal for tests: deletes every key of this store's prefix is the test's own cleanup, not the store's. */
    public function prefix(): string
    {
        return $this->prefix;
    }

    private function bucketKey(int $bucket, string $kind): string
    {
        return $this->prefix . ':b:60:' . $bucket . ':' . $kind;
    }
}
