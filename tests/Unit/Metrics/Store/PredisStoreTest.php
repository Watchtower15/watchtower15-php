<?php

declare(strict_types=1);

namespace WatchTower15\Tests\Unit\Metrics\Store;

use Predis\Client;
use WatchTower15\Store\RedisStore;

final class PredisStoreTest extends RedisStoreTestCase
{
    protected function requireClient(): void
    {
        if (! class_exists(Client::class)) {
            self::fail('predis/predis is a require-dev of the monorepo root and must be installed.');
        }
    }

    protected function make(string $prefix, ?string $host = null, int $port = 6379): RedisStore
    {
        return new RedisStore(new Client(['host' => $host ?? (string) $this->host(), 'port' => $port]), $prefix);
    }

    protected function deleteKeys(string $pattern): void
    {
        $redis = new Client(['host' => (string) $this->host()]);
        $keys = $redis->keys($pattern);
        if ($keys !== []) {
            $redis->del($keys);
        }
    }

    protected function clientName(): string
    {
        return 'predis';
    }
}
