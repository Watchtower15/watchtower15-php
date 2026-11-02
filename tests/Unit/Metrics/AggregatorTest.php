<?php

declare(strict_types=1);

namespace WatchTower15\Tests\Unit\Metrics;

use PHPUnit\Framework\TestCase;
use WatchTower15\Metrics\CallableTokenProvider;
use WatchTower15\Store\InMemoryStore;
use WatchTower15\Tests\Support\PipeHarness;
use WatchTower15\Tests\Support\SpyStore;

final class AggregatorTest extends TestCase
{
    public function testP1TwoIncrementsInOneMinuteAddUp(): void
    {
        $h = new PipeHarness();
        $h->aggregator->increment('a');
        $h->aggregator->increment('a');

        self::assertSame(2, $h->store->read($h->bucket())['count']['a']);
    }

    public function testP2AGaugeIsTheLastValue(): void
    {
        $h = new PipeHarness();
        $h->aggregator->gauge('q', 3);
        $h->aggregator->gauge('q', 7);

        self::assertSame(7.0, $h->store->read($h->bucket())['level']['q']);
    }

    public function testP9ConstructionTouchesNothing(): void
    {
        $spy = new SpyStore(new InMemoryStore());
        $calls = 0;
        $h = new PipeHarness(clientArguments: ['metricsToken' => new CallableTokenProvider(function () use (&$calls): string {
            $calls++;

            return 'never';
        })], store: $spy);

        self::assertSame([], $spy->calls);
        self::assertSame([], $h->transport->requests());
        self::assertSame([], $h->logger->records);
        self::assertSame(0, $calls);
    }

    public function testP14ADisabledAggregatorDoesNothingAtAll(): void
    {
        $spy = new SpyStore(new InMemoryStore());
        $calls = 0;
        $h = new PipeHarness(['enabled' => false], ['metricsToken' => new CallableTokenProvider(function () use (&$calls): string {
            $calls++;

            return 'never';
        })], $spy);

        $h->aggregator->increment('a');
        $h->aggregator->gauge('q', 1);
        $flush = $h->aggregator->flush();
        $due = $h->aggregator->flushIfDue();

        self::assertFalse($h->aggregator->isEnabled());
        self::assertSame([], $spy->calls);
        self::assertSame('disabled', $flush->status);
        self::assertNotNull($due);
        self::assertSame('disabled', $due->status);
        self::assertSame([], $h->transport->requests());
        self::assertSame(0, $calls);
    }

    public function testP21ACountAndALevelOnOneKeySendTheCountOnly(): void
    {
        $h = new PipeHarness();
        $h->aggregator->increment('k');
        $h->aggregator->gauge('k', 2);
        $h->advance(70);
        $h->accept();

        $report = $h->aggregator->flush();

        self::assertSame([['metric' => 'k', 'value' => 1, 'recorded_at' => PipeHarness::T0 * 1000, 'metric_type' => 'count']], $h->sentPoints());
        self::assertSame(1, $report->errors['type_conflict']);
    }

    public function testP22InvalidInputIsCountedAndNothingIsStored(): void
    {
        $h = new PipeHarness();
        $a = $h->aggregator;
        $a->increment('k', -1);
        $a->increment('k', 0);
        $a->increment('Bad Key');
        $a->increment(str_repeat('k', 101));
        $a->gauge('q', NAN);
        $a->gauge('q', INF);

        self::assertSame(['count' => [], 'level' => []], $h->store->read($h->bucket()));
        self::assertSame(['negative_count' => 1, 'invalid_key' => 2, 'invalid_value' => 2], $h->store->errors());
    }

    public function testP23TheAllowListRefusesWhatItDoesNotNameOrNamesAsTheOtherType(): void
    {
        $h = new PipeHarness(['allowList' => ['a' => 'count']]);
        $h->aggregator->increment('b');
        $h->aggregator->gauge('a', 1);
        $h->aggregator->increment('a');

        self::assertSame(['count' => ['a' => 1], 'level' => []], $h->store->read($h->bucket()));
        self::assertSame(['not_allowed' => 2], $h->store->errors());
    }

    public function testTheTtlOfABucketIsMaxBucketsPlusAnHourInSeconds(): void
    {
        $spy = new SpyStore(new InMemoryStore());
        $ttls = [];
        $h = new PipeHarness(['maxBuckets' => 60], store: $spy);
        $spy->before['increment'] = static function () use (&$ttls): void {
            $ttls[] = 'seen';
        };
        $h->aggregator->increment('a');

        self::assertSame(['seen'], $ttls);
        // maxBuckets is clamped to 2..1,440, so the extremes give (2 + 60) * 60 and (1440 + 60) * 60.
        $low = new PipeHarness(['maxBuckets' => 0]);
        $low->aggregator->increment('a');
        self::assertSame(1, $low->store->read($low->bucket())['count']['a']);
    }

    public function testP11ALateWriteIsStoredAndCountedOnlyPastTheGrace(): void
    {
        foreach ([2 => 0, 6 => 1] as $landsAt => $late) {
            $spy = new SpyStore(new InMemoryStore());
            $h = new PipeHarness(store: $spy, at: PipeHarness::T0 - 1);   // one second before the end of bucket N-1
            $end = PipeHarness::T0;
            $n1 = intdiv(PipeHarness::T0, 60) - 1;
            $spy->before['increment'] = function () use ($h, $end, $landsAt, $spy): void {
                $spy->before = [];
                $h->clock->now = new \DateTimeImmutable('@' . ($end + 1));
                $h->aggregator->flush();                       // not closed yet: the bucket is left alone
                $h->clock->now = new \DateTimeImmutable('@' . ($end + $landsAt));
            };
            $h->aggregator->increment('k');

            self::assertSame(1, $h->store->read($n1)['count']['k'], "the write is stored (lands at +$landsAt)");
            self::assertSame($late, $h->store->errors()['late_write'] ?? 0, "late_write at +$landsAt");
            $h->accept();
            $h->clock->now = new \DateTimeImmutable('@' . ($end + 61));
            $report = $h->aggregator->flush();

            self::assertSame(1, $report->points === 1 ? 1 : 0, 'the bucket is sent with the increment');
            self::assertSame([['metric' => 'k', 'value' => 1, 'recorded_at' => $n1 * 60000, 'metric_type' => 'count']], $h->sentPoints());
        }
    }
}
