<?php

declare(strict_types=1);

namespace WatchTower15\Tests\Unit\Metrics\Store;

use WatchTower15\Metrics\BucketStore;
use WatchTower15\Metrics\StoreScope;
use WatchTower15\Store\InMemoryStore;
use WatchTower15\Tests\Support\FakeClock;

final class InMemoryStoreTest extends BucketStoreContract
{
    private FakeClock $clock;

    private InMemoryStore $store;

    protected function setUp(): void
    {
        $this->clock = new FakeClock();
        $this->store = new InMemoryStore($this->clock);
    }

    protected function store(): BucketStore
    {
        return $this->store;
    }

    protected function sameBackend(): BucketStore
    {
        return $this->store;   // one object is the only "backend" of an in-memory store
    }

    protected function otherPrefix(): BucketStore
    {
        return new InMemoryStore($this->clock);
    }

    protected function scope(): StoreScope
    {
        return StoreScope::Process;
    }

    protected function expireClaim(BucketStore $store, string $name): void
    {
        $this->clock->now = $this->clock->now->modify('+5 seconds');
    }
}
