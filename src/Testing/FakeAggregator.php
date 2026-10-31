<?php

declare(strict_types=1);

namespace WatchTower15\Testing;

use WatchTower15\Metrics\Aggregator;
use WatchTower15\Metrics\FlushReport;
use WatchTower15\Store\InMemoryStore;

/**
 * An {@see Aggregator} for tests: built with no arguments over a {@see FakeClient} and an in-memory store, it records every call,
 * and its flush pushes every bucket, the open one included, into the fake client, never a network.
 */
class FakeAggregator extends Aggregator
{
    /** @var array<string, int> */
    private array $increments = [];

    /** @var array<string, int|float> */
    private array $gauges = [];

    private readonly FakeClient $fake;

    public function __construct()
    {
        $fake = new FakeClient();
        $this->fake = $fake;
        parent::__construct(client: $fake, store: new InMemoryStore());
    }

    public function increment(string $key, int $by = 1): void
    {
        $this->increments[$key] = ($this->increments[$key] ?? 0) + $by;
        parent::increment($key, $by);
    }

    public function gauge(string $key, int|float $value): void
    {
        $this->gauges[$key] = $value;
        parent::gauge($key, $value);
    }

    /** Always a final flush: the open bucket is sent too. */
    public function flush(bool $final = false): FlushReport
    {
        return parent::flush(true);
    }

    /** @return array<string, int> */
    public function increments(): array
    {
        return $this->increments;
    }

    /** @return array<string, int|float> */
    public function gauges(): array
    {
        return $this->gauges;
    }

    public function client(): FakeClient
    {
        return $this->fake;
    }

    public function assertIncremented(string $key, ?int $by = null): void
    {
        if (! isset($this->increments[$key]) || ($by !== null && $this->increments[$key] !== $by)) {
            self::fail(sprintf('Expected %s to be incremented%s; it was %s.', $key, $by !== null ? " by $by" : '', isset($this->increments[$key]) ? (string) $this->increments[$key] : 'not'));
        }
    }

    public function assertNotIncremented(string $key): void
    {
        if (isset($this->increments[$key])) {
            self::fail(sprintf('Expected %s not to be incremented; it was by %d.', $key, $this->increments[$key]));
        }
    }

    public function assertGaugeSet(string $key, int|float|null $value = null): void
    {
        if (! array_key_exists($key, $this->gauges) || ($value !== null && $this->gauges[$key] != $value)) {
            self::fail(sprintf('Expected gauge %s to be set%s.', $key, $value !== null ? " to $value" : ''));
        }
    }

    public function assertNothingRecorded(): void
    {
        if ($this->increments !== [] || $this->gauges !== []) {
            self::fail('Expected nothing to be recorded.');
        }
    }

    private static function fail(string $message): never
    {
        if (class_exists(\PHPUnit\Framework\Assert::class)) {
            \PHPUnit\Framework\Assert::fail($message);
        }

        throw new \LogicException($message);
    }
}
