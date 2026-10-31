<?php

declare(strict_types=1);

namespace WatchTower15\Tests\Unit\Metrics\Store;

use PHPUnit\Framework\TestCase;
use WatchTower15\Metrics\BucketStore;
use WatchTower15\Metrics\StoreScope;

/**
 * One contract, run against every store (S1–S11). A subclass says how to build a store, a second object over the same backend, one
 * over another prefix, and how to let a claim's TTL pass.
 */
abstract class BucketStoreContract extends TestCase
{
    /** A store over this test's backend and namespace. */
    abstract protected function store(): BucketStore;

    /** A second store object over the same backend and the same prefix or directory. */
    abstract protected function sameBackend(): BucketStore;

    /** A store over the same backend and another prefix or directory. */
    abstract protected function otherPrefix(): BucketStore;

    abstract protected function scope(): StoreScope;

    /** Let the TTL of a claim of this name pass. */
    abstract protected function expireClaim(BucketStore $store, string $name): void;

    /** The claim's TTL to use in S6: short where the test waits for real time. */
    protected function claimTtl(): int
    {
        return 1;
    }

    /** The seconds a bucket key lives, where the backend can say; null where it cannot. */
    protected function bucketTtl(BucketStore $store, int $bucket): ?int
    {
        return null;
    }

    /** A store whose backend is unreachable, or null where there is no such thing. */
    protected function brokenStore(): ?BucketStore
    {
        return null;
    }

    public function testS1CountsAddUpAndNeverReturnFalse(): void
    {
        $store = $this->store();
        $store->increment(10, 'a', 1, 7200);
        self::assertSame(['count' => ['a' => 1], 'level' => []], $store->read(10));
        $store->increment(10, 'a', 2, 7200);
        $store->increment(10, 'a', 2, 7200);

        self::assertSame(5, $store->read(10)['count']['a']);
    }

    public function testS2LevelsAreTheLastWriteAndRoundTripExactly(): void
    {
        $store = $this->store();
        $store->setLevel(10, 'q', 1.5, 7200);
        self::assertSame(1.5, $store->read(10)['level']['q']);
        $store->setLevel(10, 'q', 812, 7200);
        self::assertSame(812.0, $store->read(10)['level']['q']);
        $store->setLevel(10, 'q', 0.1 + 0.2, 7200);

        self::assertSame(0.30000000000000004, $store->read(10)['level']['q']);
    }

    public function testS3BucketsAreIndependentAndForgettable(): void
    {
        $store = $this->store();
        $store->increment(10, 'a', 2, 7200);
        $store->setLevel(10, 'q', 3, 7200);
        $store->increment(11, 'b', 4, 7200);
        $store->setLevel(11, 'r', 5, 7200);

        self::assertSame(['count' => ['a' => 2], 'level' => ['q' => 3.0]], $store->read(10));
        self::assertSame(['count' => ['b' => 4], 'level' => ['r' => 5.0]], $store->read(11));
        $store->forget(10);
        self::assertSame(['count' => [], 'level' => []], $store->read(10));
        self::assertSame(['count' => ['b' => 4], 'level' => ['r' => 5.0]], $store->read(11));
    }

    public function testS4TheLastFlushedBucket(): void
    {
        $store = $this->store();
        self::assertNull($store->lastFlushed());
        $store->markFlushed(42);

        self::assertSame(42, $store->lastFlushed());
    }

    public function testS5Locks(): void
    {
        $store = $this->store();
        $token = $store->acquireLock('flush', 120);
        self::assertIsString($token);
        self::assertNull($store->acquireLock('flush', 120));
        $store->releaseLock('flush', 'wrong-token');
        self::assertNull($store->acquireLock('flush', 120), 'a wrong token releases nothing');
        $store->releaseLock('flush', $token);
        $again = $store->acquireLock('flush', 120);

        self::assertIsString($again);
        self::assertNotSame($token, $again);
        $store->releaseLock('flush', $again);
    }

    public function testS6ClaimsLastTheirTtl(): void
    {
        $store = $this->store();
        self::assertTrue($store->claimOnce('tick:7', $this->claimTtl()));
        self::assertFalse($store->claimOnce('tick:7', $this->claimTtl()));
        $this->expireClaim($store, 'tick:7');

        self::assertTrue($store->claimOnce('tick:7', $this->claimTtl()));
    }

    public function testS7ErrorCounters(): void
    {
        $store = $this->store();
        $store->addError('auth');
        $store->addError('auth');
        $store->addError('auth');
        self::assertSame(['auth' => 3], $store->errors());
        $store->subtractErrors(['auth' => 2]);

        self::assertSame(['auth' => 1], $store->errors());
    }

    public function testS8TheInstanceIdIsOnePerStore(): void
    {
        $store = $this->store();
        $id = $store->instanceId();

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
        self::assertSame($id, $store->instanceId());
        self::assertSame($id, $this->sameBackend()->instanceId());
        self::assertNotSame($id, $this->otherPrefix()->instanceId());
    }

    public function testS10ScopeAndTtl(): void
    {
        $store = $this->store();
        self::assertSame($this->scope(), $store->scope());
        $store->increment(10, 'a', 1, 7200);
        $ttl = $this->bucketTtl($store, 10);
        if ($ttl !== null) {
            self::assertEqualsWithDelta(7200, $ttl, 5);
        }
    }

    public function testS11ABrokenBackendThrowsToTheCallerButConstructionAndScopeWork(): void
    {
        $broken = $this->brokenStore();
        if ($broken === null) {
            self::addToAssertionCount(1);   // an in-memory store has no backend to lose; APCu's case is its own test

            return;
        }
        self::assertSame($this->scope(), $broken->scope());
        $calls = [
            'increment' => static fn () => $broken->increment(10, 'a', 1, 60),
            'setLevel' => static fn () => $broken->setLevel(10, 'a', 1, 60),
            'read' => static fn () => $broken->read(10),
            'forget' => static fn () => $broken->forget(10),
            'lastFlushed' => static fn () => $broken->lastFlushed(),
            'markFlushed' => static fn () => $broken->markFlushed(10),
            'acquireLock' => static fn () => $broken->acquireLock('flush', 60),
            'claimOnce' => static fn () => $broken->claimOnce('tick:1', 60),
            'addError' => static fn () => $broken->addError('auth'),
            'errors' => static fn () => $broken->errors(),
            'instanceId' => static fn () => $broken->instanceId(),
        ];
        foreach ($calls as $name => $call) {
            try {
                $call();
                self::fail("$name should throw");
            } catch (\RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /**
     * The autoloader a child process can require.
     */
    protected static function autoload(): string
    {
        $file = (new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName();
        self::assertIsString($file);

        return dirname($file, 2) . '/autoload.php';
    }

    /**
     * S9: eight processes of 500 increments each on one key.
     *
     * @param array<string, string> $config
     */
    protected function assertConcurrentIncrementsAreExact(array $config, BucketStore $reader): void
    {
        $processes = [];
        $pipes = [];
        for ($i = 0; $i < 8; $i++) {
            $process = proc_open(
                [PHP_BINARY, dirname(__DIR__, 3) . '/Fixtures/store-worker.php', self::autoload(), json_encode($config, JSON_THROW_ON_ERROR)],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes[$i],
            );
            self::assertIsResource($process);
            $processes[$i] = $process;
        }
        foreach ($processes as $i => $process) {
            $out = stream_get_contents($pipes[$i][1]) . stream_get_contents($pipes[$i][2]);
            self::assertSame(0, proc_close($process), $out);
        }

        self::assertSame(4000, $reader->read(1)['count']['k']);
    }
}
