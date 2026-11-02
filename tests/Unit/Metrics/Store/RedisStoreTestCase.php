<?php

declare(strict_types=1);

namespace WatchTower15\Tests\Unit\Metrics\Store;

use WatchTower15\ConfigurationException;
use WatchTower15\Metrics\BucketStore;
use WatchTower15\Metrics\StoreScope;
use WatchTower15\Store\RedisStore;

/** The Redis store over one client; the two concrete tests say which. */
abstract class RedisStoreTestCase extends BucketStoreContract
{
    protected string $prefix;

    protected function setUp(): void
    {
        $this->prefix = 'wt15test' . bin2hex(random_bytes(6));
        if ($this->host() === null) {
            if (getenv('WT15_SDK_TEST_REQUIRE_SERVICES') === '1') {
                self::fail('WT15_SDK_TEST_REDIS_HOST is not set, and the services are required.');
            }
            self::markTestSkipped('no Redis service (WT15_SDK_TEST_REDIS_HOST)');
        }
        $this->requireClient();
    }

    protected function tearDown(): void
    {
        if ($this->host() !== null) {
            $this->deleteKeys($this->prefix . '*');
        }
    }

    protected function host(): ?string
    {
        $host = getenv('WT15_SDK_TEST_REDIS_HOST');

        return is_string($host) && $host !== '' ? $host : null;
    }

    /** Skips or fails when the client library is missing. */
    abstract protected function requireClient(): void;

    abstract protected function make(string $prefix, ?string $host = null, int $port = 6379): RedisStore;

    abstract protected function deleteKeys(string $pattern): void;

    abstract protected function clientName(): string;

    protected function store(): BucketStore
    {
        return $this->make($this->prefix);
    }

    protected function sameBackend(): BucketStore
    {
        return $this->make($this->prefix);
    }

    protected function otherPrefix(): BucketStore
    {
        return $this->make($this->prefix . 'other');
    }

    protected function scope(): StoreScope
    {
        return StoreScope::Fleet;
    }

    protected function expireClaim(BucketStore $store, string $name): void
    {
        sleep(2);
    }

    protected function bucketTtl(BucketStore $store, int $bucket): ?int
    {
        return $store instanceof RedisStore ? $store->bucketTtl($bucket) : null;
    }

    protected function brokenStore(): ?BucketStore
    {
        return $this->make($this->prefix, '127.0.0.1', 1);
    }

    public function testS8AnEmptyPrefixIsRefusedAtConstruction(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->make('');
    }

    public function testS9EightProcessesCountExactly(): void
    {
        $this->assertConcurrentIncrementsAreExact(['store' => $this->clientName(), 'host' => (string) $this->host(), 'prefix' => $this->prefix], $this->store());
    }
}
