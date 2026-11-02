<?php

declare(strict_types=1);

namespace WatchTower15\Tests\Support;

use WatchTower15\Metrics\BucketStore;
use WatchTower15\Metrics\StoreScope;

/** Wraps a store: records every call, throws on chosen methods, and runs hooks before chosen methods. */
final class SpyStore implements BucketStore
{
    /** @var list<string> */
    public array $calls = [];

    /** @var list<int> */
    public array $readBuckets = [];

    /** @var array<string, true> */
    public array $throwOn = [];

    /** @var array<string, callable(): void> */
    public array $before = [];

    public function __construct(public readonly BucketStore $inner)
    {
    }

    public function count(string $method): int
    {
        return count(array_filter($this->calls, static fn (string $c): bool => $c === $method));
    }

    private function enter(string $method): void
    {
        $this->calls[] = $method;
        if (isset($this->before[$method])) {
            ($this->before[$method])();
        }
        if (isset($this->throwOn[$method]) || isset($this->throwOn['*'])) {
            throw new \RuntimeException('spy: ' . $method . ' fails');
        }
    }

    public function scope(): StoreScope
    {
        return $this->inner->scope();
    }

    public function increment(int $bucket, string $key, int $by, int $ttl): void
    {
        $this->enter('increment');
        $this->inner->increment($bucket, $key, $by, $ttl);
    }

    public function setLevel(int $bucket, string $key, int|float $value, int $ttl): void
    {
        $this->enter('setLevel');
        $this->inner->setLevel($bucket, $key, $value, $ttl);
    }

    public function read(int $bucket): array
    {
        $this->readBuckets[] = $bucket;
        $this->enter('read');

        return $this->inner->read($bucket);
    }

    public function forget(int $bucket): void
    {
        $this->enter('forget');
        $this->inner->forget($bucket);
    }

    public function lastFlushed(): ?int
    {
        $this->enter('lastFlushed');

        return $this->inner->lastFlushed();
    }

    public function markFlushed(int $bucket): void
    {
        $this->enter('markFlushed');
        $this->inner->markFlushed($bucket);
    }

    public function acquireLock(string $name, int $ttl): ?string
    {
        $this->enter('acquireLock');

        return $this->inner->acquireLock($name, $ttl);
    }

    public function releaseLock(string $name, string $token): void
    {
        $this->enter('releaseLock');
        $this->inner->releaseLock($name, $token);
    }

    public function claimOnce(string $name, int $ttl): bool
    {
        $this->enter('claimOnce');

        return $this->inner->claimOnce($name, $ttl);
    }

    public function addError(string $reason, int $by = 1): void
    {
        $this->enter('addError');
        $this->inner->addError($reason, $by);
    }

    public function errors(): array
    {
        $this->enter('errors');

        return $this->inner->errors();
    }

    public function subtractErrors(array $counts): void
    {
        $this->enter('subtractErrors');
        $this->inner->subtractErrors($counts);
    }

    public function instanceId(): string
    {
        $this->enter('instanceId');

        return $this->inner->instanceId();
    }
}
