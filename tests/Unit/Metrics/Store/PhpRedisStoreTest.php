<?php

declare(strict_types=1);

namespace WatchTower15\Tests\Unit\Metrics\Store;

use WatchTower15\Store\RedisStore;

final class PhpRedisStoreTest extends RedisStoreTestCase
{
    protected function requireClient(): void
    {
        if (! class_exists(\Redis::class)) {
            getenv('WT15_SDK_TEST_REQUIRE_SERVICES') === '1' ? self::fail('ext-redis is not loaded, and the services are required.') : self::markTestSkipped('ext-redis is not loaded');
        }
    }

    protected function make(string $prefix, ?string $host = null, int $port = 6379): RedisStore
    {
        $redis = new \Redis();
        if ($prefix !== '' && $host === null) {
            $redis->connect((string) $this->host(), $port);
        }

        return new RedisStore($redis, $prefix);
    }

    protected function deleteKeys(string $pattern): void
    {
        $redis = new \Redis();
        $redis->connect((string) $this->host());
        $keys = $redis->keys($pattern);
        if (is_array($keys) && $keys !== []) {
            $redis->del($keys);
        }
    }

    protected function clientName(): string
    {
        return 'phpredis';
    }
}
