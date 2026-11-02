<?php

declare(strict_types=1);

namespace WatchTower15\Tests\Unit\Metrics;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use WatchTower15\Exception\ApiConnectionException;
use WatchTower15\Http\Transport;
use WatchTower15\Http\TransportOptions;
use WatchTower15\Metrics\CallableTokenProvider;
use WatchTower15\Store\InMemoryStore;
use WatchTower15\Testing\MockTransport;
use WatchTower15\Tests\Support\FakeClock;
use WatchTower15\Tests\Support\Keys;
use WatchTower15\Tests\Support\PipeHarness;
use WatchTower15\Tests\Support\SpyStore;

final class FlusherTest extends TestCase
{
    private const B = PipeHarness::T0 / 60;

    /** Put one count into each of the given buckets (the clock is moved there and back). */
    private function seed(PipeHarness $h, string $key, int ...$buckets): void
    {
        $back = $h->clock->now;
        foreach ($buckets as $bucket) {
            $h->clock->now = new \DateTimeImmutable('@' . ($bucket * 60 + 10));
            $h->aggregator->increment($key);
        }
        $h->clock->now = $back;
    }

    private function at(PipeHarness $h, int $offset): void
    {
        $h->clock->now = new \DateTimeImmutable('@' . (PipeHarness::T0 + $offset));
    }

    /** @return list<string> */
    private function keys(PipeHarness $h): array
    {
        return array_map(static fn ($r): string => (string) $r->header('Idempotency-Key'), $h->transport->requests());
    }

    public function testP3TwoClosedBucketsAreSentOldestFirstAndTheOpenOneIsLeftAlone(): void
    {
        $h = new PipeHarness();
        $this->seed($h, 'a', self::B - 2);
        $this->seed($h, 'b', self::B - 1);
        $this->seed($h, 'c', self::B);
        $this->at($h, 10);
        $h->accept(2);

        $report = $h->aggregator->flush();

        self::assertSame(2, $report->requests);
        self::assertSame(2, $report->bucketsSent);
        self::assertSame(2, $report->points);
        $points = $h->sentPoints();
        self::assertSame(['a', 'b'], array_column($points, 'metric'));
        self::assertSame([(self::B - 2) * 60000, (self::B - 1) * 60000], array_column($points, 'recorded_at'));
        self::assertSame(['count', 'count'], array_column($points, 'metric_type'));
        self::assertSame(['count' => ['c' => 1], 'level' => []], $h->store->read((int) self::B));
        self::assertSame(['count' => [], 'level' => []], $h->store->read((int) self::B - 2));
        self::assertSame(self::B - 1, $h->store->lastFlushed());
        foreach ($h->transport->requests() as $request) {
            self::assertMatchesRegularExpression('/^wt15agg-[0-9a-f]{64}$/', (string) $request->header('Idempotency-Key'));
        }
    }

    /** @return iterable<string, array{callable(): \Throwable|ResponseInterface}> */
    public static function transportFailures(): iterable
    {
        yield 'connect failure' => [static fn () => new ApiConnectionException('down', false, false, 'connect')];
        yield '500' => [static fn () => MockTransport::envelope(500, ['code' => 'server_error'], 'Oops')];
        yield '429' => [static fn () => MockTransport::envelope(429, ['code' => 'rate_limited'], 'Slow', ['Retry-After' => '1'])];
        yield '408' => [static fn () => MockTransport::problem(408, 'timeout')];
    }

    /** @param callable(): (\Throwable|ResponseInterface) $failure */
    #[DataProvider('transportFailures')]
    public function testP4AFailedBucketIsResentUnderTheSameKeyNextRun(callable $failure): void
    {
        $h = new PipeHarness();
        $this->seed($h, 'a', self::B - 1);
        $this->at($h, 10);
        $this->seed($h, 'b', self::B);          // the open bucket, closed by the next run
        $h->transport->append($failure());

        $first = $h->aggregator->flush();

        self::assertSame('transport', $first->stopReason);
        self::assertCount(1, $h->transport->requests(), 'no retry inside the run');
        $this->at($h, 70);
        $h->accept(2);
        $second = $h->aggregator->flush();

        $keys = $this->keys($h);
        self::assertCount(3, $keys);
        self::assertSame($keys[0], $keys[1], 'the failed bucket is resent under its frozen key');
        self::assertNotSame($keys[1], $keys[2], 'the newer bucket has its own key');
        self::assertSame(2, $second->bucketsSent);
        self::assertSame(1, $first->errors['transport']);
    }

    /** @return iterable<string, array{ResponseInterface, string}> */
    public static function stoppingStatuses(): iterable
    {
        yield '401' => [MockTransport::envelope(401, ['code' => 'unauthorized'], 'No'), 'auth'];
        yield '403' => [MockTransport::envelope(403, ['code' => 'forbidden'], 'No'), 'auth'];
        yield '302' => [new Response(302, ['Location' => 'https://elsewhere.example/x']), 'redirect'];
    }

    #[DataProvider('stoppingStatuses')]
    public function testP5AuthAndRedirectKeepTheBucketAndStopTheRun(ResponseInterface $answer, string $reason): void
    {
        $h = new PipeHarness();
        $this->seed($h, 'a', self::B - 2);
        $this->seed($h, 'b', self::B - 1);
        $this->at($h, 10);
        $h->transport->append($answer, MockTransport::metricsAccepted(1));

        $report = $h->aggregator->flush();

        self::assertSame($reason, $report->stopReason);
        self::assertSame($reason === 'auth' ? 1 : 1, $report->errors[$reason]);
        self::assertCount(1, $h->transport->requests(), 'not followed, and the newer bucket waits');
        self::assertSame(1, $h->store->read((int) self::B - 2)['count']['a'], 'kept');
        self::assertCount(1, $h->logger->level('warning'));
    }

    /** @return iterable<string, array{int}> */
    public static function droppingStatuses(): iterable
    {
        foreach ([400, 402, 404, 405, 413, 422] as $status) {
            yield (string) $status => [$status];
        }
    }

    #[DataProvider('droppingStatuses')]
    public function testP5OtherClientErrorsDropTheChunkAndGoOn(int $status): void
    {
        $h = new PipeHarness();
        $this->seed($h, 'a', self::B - 2);
        $this->seed($h, 'b', self::B - 1);
        $this->at($h, 10);
        $h->transport->append(MockTransport::envelope($status, ['code' => 'rejected'], 'No'), MockTransport::metricsAccepted(1));

        $report = $h->aggregator->flush();

        self::assertNull($report->stopReason);
        self::assertSame(1, $report->chunksDropped);
        self::assertSame(1, $report->errors['dropped_rejected']);
        self::assertSame(1, $report->bucketsSent);
        self::assertCount(2, $h->transport->requests());
        self::assertSame(self::B - 1, $h->store->lastFlushed());
    }

    public function testP5AChunkWithANanLevelHasNoKeyAndIsDroppedWithoutARequest(): void
    {
        $h = new PipeHarness();
        $h->store->setLevel((int) self::B - 2, 'q', NAN, 7200);
        $this->seed($h, 'b', self::B - 1);
        $this->at($h, 10);
        $h->accept();

        $report = $h->aggregator->flush();

        self::assertSame(1, $report->requests);
        self::assertSame(1, $report->chunksDropped);
        self::assertSame(1, $report->errors['dropped_rejected']);
        self::assertSame(['b'], array_column($h->sentPoints(), 'metric'));
    }

    public function testP6ABucketOfManyPointsIsChunkedAndKeyedPerChunk(): void
    {
        $h = new PipeHarness();
        $this->at($h, -50);   // inside bucket B-1
        for ($i = 0; $i < 1500; $i++) {
            $h->aggregator->increment(sprintf('k.%04d', $i));
        }
        $this->at($h, 10);
        $h->transport->append(MockTransport::metricsAccepted(1000), MockTransport::envelope(503, [], 'busy'));

        $first = $h->aggregator->flush();

        self::assertSame('transport', $first->stopReason);
        self::assertSame(1000, count($h->transport->requests()[0]->body['points']));
        self::assertCount(2, $h->transport->requests(), 'the second chunk failed: the bucket is kept whole');
        self::assertSame(1500, count($h->store->read((int) self::B - 1)['count']));
        $this->at($h, 20);
        $h->accept(2);
        $second = $h->aggregator->flush();

        $keys = $this->keys($h);
        self::assertCount(4, $keys);
        self::assertSame([$keys[0], $keys[1]], [$keys[2], $keys[3]], 'both chunks resent under the same two keys');
        self::assertNotSame($keys[0], $keys[1]);
        self::assertSame(1, $second->bucketsSent);
        self::assertSame(['count' => [], 'level' => []], $h->store->read((int) self::B - 1));
        $sizes = array_map(static fn ($r): int => count($r->body['points']), $h->transport->requests());
        self::assertSame([1000, 500, 1000, 500], $sizes);
    }

    public function testP6KeysDifferByAggregationAndNeverHoldTheTokenOrTheInstance(): void
    {
        $keysOf = function (string $token, int $by, ?InMemoryStore $store = null): string {
            $h = new PipeHarness(clientArguments: ['metricsToken' => $token], store: $store);
            $this->at($h, -50);
            $h->aggregator->increment('k', $by);
            $this->at($h, 10);
            $h->accept();
            $h->aggregator->flush();

            return $this->keys($h)[0];
        };
        $a = $keysOf(Keys::metrics(), 1);
        $b = $keysOf(Keys::metrics(), 2);
        $c = $keysOf(Keys::metrics(), 1);   // a second store, so a second instance id: identical body, another key
        $d = $keysOf(Keys::metrics('d'), 1);

        self::assertCount(4, array_unique([$a, $b, $c, $d]));
        foreach ([$a, $b, $c, $d] as $key) {
            self::assertMatchesRegularExpression('/^wt15agg-[0-9a-f]{64}$/', $key);
            self::assertStringNotContainsString(substr(Keys::metrics(), 7, 30), $key);
        }
    }

    public function testP7BucketsOlderThanMaxBucketsAreDroppedNeverSent(): void
    {
        $spy = new SpyStore(new InMemoryStore());
        $h = new PipeHarness(store: $spy);
        $current = (int) self::B;
        $this->seed($h, 'old', $current - 100, $current - 70);
        $this->seed($h, 'edge', $current - 60);
        $this->at($h, 10);
        $h->transport->append(new ApiConnectionException('down', false, false, 'connect'));

        $first = $h->aggregator->flush();

        self::assertSame(2, $first->bucketsDropped);
        self::assertSame(2, $first->errors['dropped_age']);
        self::assertCount(1, $h->transport->requests(), 'only the window bucket was tried');
        self::assertSame(['edge'], array_column($h->transport->requests()[0]->body['points'], 'metric'));
        self::assertSame($current - 61, $spy->inner->lastFlushed());

        // A minute later the edge bucket has aged out: one bucket is read below the window, not the range again.
        $this->at($h, 70);
        $spy->readBuckets = [];
        $second = $h->aggregator->flush();

        $below = array_filter($spy->readBuckets, static fn (int $b): bool => $b < ($current + 1) - 60);
        self::assertSame([$current - 60], array_values($below));
        self::assertSame(1, $second->bucketsDropped);
        self::assertSame(['count' => [], 'level' => []], $spy->inner->read($current - 60));
    }

    public function testP7AMaxBucketsOfOneIsClampedToTwoSoTheNewestClosedBucketIsSent(): void
    {
        $h = new PipeHarness(['maxBuckets' => 1]);
        $this->seed($h, 'a', (int) self::B - 2);
        $h->accept();

        $report = $h->aggregator->flush();

        self::assertSame(0, $report->bucketsDropped);
        self::assertSame(['a'], array_column($h->sentPoints(), 'metric'));
    }

    public function testP10TheHeartbeatMakesEveryFlushSendSomething(): void
    {
        $h = new PipeHarness(['heartbeat' => 'app.flushes']);
        $this->at($h, 10);

        $first = $h->aggregator->flush();
        self::assertTrue($first->heartbeatAdded);
        self::assertSame([], $h->transport->requests());
        self::assertSame(1, $h->store->read((int) self::B)['count']['app.flushes']);

        $this->at($h, 75);
        $h->accept();
        $h->aggregator->flush();

        self::assertCount(1, $h->transport->requests());
        self::assertSame([['metric' => 'app.flushes', 'value' => 1, 'recorded_at' => (int) self::B * 60000, 'metric_type' => 'count']], $h->sentPoints());
    }

    public function testP12TheRequestCapStopsTheRunAndTheRestWaits(): void
    {
        $h = new PipeHarness();
        for ($i = 15; $i >= 1; $i--) {
            $this->seed($h, 'k', (int) self::B - $i);
        }
        $this->at($h, 10);
        $h->accept(15);

        $first = $h->aggregator->flush();

        self::assertSame(10, $first->requests);
        self::assertSame('max_requests', $first->stopReason);
        self::assertSame([(int) self::B - 15, (int) self::B - 6], [intdiv($h->sentPoints()[0]['recorded_at'], 60000), intdiv($h->sentPoints()[9]['recorded_at'], 60000)]);
        $this->at($h, 11);
        $second = $h->aggregator->flush();
        self::assertSame(5, $second->requests);
        self::assertNull($second->stopReason);
    }

    public function testP12TheCapNeverSplitsABucket(): void
    {
        $h = new PipeHarness(['maxRequests' => 1]);
        $this->at($h, -50);
        for ($i = 0; $i < 1500; $i++) {
            $h->aggregator->increment(sprintf('k.%04d', $i));
        }
        $this->at($h, 10);
        $h->accept(2);

        $report = $h->aggregator->flush();

        self::assertSame(2, $report->requests);
        self::assertSame(1, $report->bucketsSent);
        self::assertSame(1500, $report->points === 2 ? 1500 : 0, 'the mocked answers count 1 each');
    }

    public function testP12TheNinetySecondDeadlineStopsNewRequests(): void
    {
        $inner = new MockTransport();
        $slow = null;
        $transport = new class ($inner) implements Transport {
            public ?PipeHarness $harness = null;

            public function __construct(private readonly MockTransport $inner)
            {
            }

            public function send(#[\SensitiveParameter] RequestInterface $request, TransportOptions $options): ResponseInterface
            {
                $response = $this->inner->send($request, $options);
                $this->harness?->advance(20);

                return $response;
            }
        };
        $h = new PipeHarness(['maxRequests' => 60], ['httpClient' => $transport]);
        $transport->harness = $h;
        for ($i = 10; $i >= 1; $i--) {
            $this->seed($h, 'k', (int) self::B - $i);
        }
        $this->at($h, 10);
        for ($i = 0; $i < 10; $i++) {
            $inner->append(MockTransport::metricsAccepted(1));
        }

        $first = $h->aggregator->flush();

        self::assertSame(5, $first->requests, 'requests at 0, 20, 40, 60 and 80 s; none at 100 s: ' . json_encode($first->toArray()));
        self::assertSame('max_requests', $first->stopReason);
        $token = $h->store->acquireLock('flush', 120);
        self::assertNotNull($token, 'the lock was released');
        $h->store->releaseLock('flush', (string) $token);
        $h->advance(1);
        $second = $h->aggregator->flush();
        self::assertSame(5, $second->requests);
        unset($slow);
    }

    public function testP13EachPointCarriesItsTypeAndTheHeartbeatIsACount(): void
    {
        $h = new PipeHarness(['allowList' => ['orders' => 'count', 'depth' => 'level'], 'heartbeat' => 'app.flushes']);
        $this->at($h, -50);
        $h->aggregator->increment('orders', 3);
        $h->aggregator->gauge('depth', 12.5);
        $this->at($h, 10);
        $h->aggregator->flush();   // adds the heartbeat to the open bucket and sends the closed one
        $this->at($h, 75);
        $h->accept(2);
        $h->aggregator->flush();

        $byMetric = [];
        foreach ($h->sentPoints() as $p) {
            $byMetric[$p['metric']] = $p;
        }
        self::assertSame('count', $byMetric['orders']['metric_type']);
        self::assertSame(3, $byMetric['orders']['value']);
        self::assertSame('level', $byMetric['depth']['metric_type']);
        self::assertSame(12.5, $byMetric['depth']['value']);
        self::assertSame('count', $byMetric['app.flushes']['metric_type']);
    }

    public function testP15AStoreThatAlwaysFailsNeverThrows(): void
    {
        $spy = new SpyStore(new InMemoryStore());
        $spy->throwOn['*'] = true;
        $h = new PipeHarness(store: $spy);

        $h->aggregator->increment('a');
        $h->aggregator->gauge('q', 1);
        $report = $h->aggregator->flush();

        self::assertSame('flushed', $report->status);
        self::assertSame('store', $report->stopReason);
        self::assertSame(3, $report->errors['store']);
        self::assertSame([], $h->transport->requests());
        self::assertCount(1, $h->logger->level('warning'));
    }

    public function testP15AReadThatThrowsStopsTheRunAndTheLockIsReleased(): void
    {
        $spy = new SpyStore(new InMemoryStore());
        $h = new PipeHarness(store: $spy);
        $this->seed($h, 'a', (int) self::B - 1);
        $this->at($h, 10);
        $spy->throwOn['read'] = true;
        $spy->calls = [];

        $report = $h->aggregator->flush();

        self::assertSame('store', $report->stopReason);
        self::assertSame(1, $spy->count('releaseLock'));
        $afterRead = array_slice($spy->calls, (int) array_search('read', $spy->calls, true) + 1);
        self::assertSame(['releaseLock'], $afterRead, 'no forget, markFlushed or error-counter call after the failure');
    }

    public function testP15AReleaseThatThrowsIsIgnored(): void
    {
        $spy = new SpyStore(new InMemoryStore());
        $h = new PipeHarness(store: $spy);
        $this->seed($h, 'a', (int) self::B - 1);
        $this->at($h, 10);
        $spy->throwOn['read'] = true;
        $spy->throwOn['releaseLock'] = true;

        $report = $h->aggregator->flush();

        self::assertSame('store', $report->stopReason);
    }

    public function testP15AnythingElseThrownIsInternalAndTheLockIsReleased(): void
    {
        $spy = new SpyStore(new InMemoryStore());
        $h = new PipeHarness(store: $spy);
        $this->seed($h, 'a', (int) self::B - 1);
        $this->at($h, 10);
        $clock = new class () extends FakeClock {
            public bool $throwNext = false;

            public function now(): \DateTimeImmutable
            {
                if ($this->throwNext) {
                    throw new \RuntimeException('clock failed');
                }

                return parent::now();
            }
        };
        $clock->now = $h->clock->now;
        $client = $h->client;
        $store = $spy;
        $aggregator = new \WatchTower15\Metrics\Aggregator($client, $store, clock: $clock, logger: $h->logger);
        $spy->before['acquireLock'] = static function () use ($clock): void {
            $clock->throwNext = true;
        };
        $spy->before['releaseLock'] = static function () use ($clock): void {
            $clock->throwNext = false;
        };

        $report = $aggregator->flush();

        self::assertSame('internal', $report->stopReason);
        self::assertSame(1, $report->errors['internal'] ?? 0);
        self::assertSame([], $h->transport->requests());
        self::assertSame(1, $h->store->read((int) self::B - 1)['count']['a'] ?? 0, 'the bucket is kept');
        self::assertCount(1, $h->logger->level('warning'));
        self::assertNotNull($spy->inner->acquireLock('flush', 10), 'the lock was released');
    }

    public function testP15ALoggerThatThrowsIsIgnored(): void
    {
        $logger = new class () extends \Psr\Log\AbstractLogger {
            public function log($level, $message, array $context = []): void
            {
                throw new \RuntimeException('logger failed');
            }
        };
        $h = new PipeHarness(['logger' => $logger]);
        $this->seed($h, 'a', (int) self::B - 1);
        $this->at($h, 10);
        $h->transport->append(MockTransport::envelope(403, ['code' => 'forbidden'], 'No'));

        $report = $h->aggregator->flush();

        self::assertSame('auth', $report->stopReason);
        self::assertNotNull($h->store->acquireLock('flush', 10));
    }

    public function testP16BadConfigurationIsDisabledAndNamed(): void
    {
        $h = new PipeHarness(['allowList' => ['bad key!' => 'count', 'x' => 'gauge', 'good' => 'count'], 'heartbeat' => 'bad key!']);
        $this->at($h, -50);
        $h->aggregator->increment('good');
        $this->at($h, 10);
        $h->accept();

        $report = $h->aggregator->flush();

        self::assertSame(['good'], array_column($h->sentPoints(), 'metric'));
        self::assertCount(3, $report->configProblems);
        self::assertStringContainsString('bad key!', $report->configProblems[0]);
        self::assertStringContainsString('`x`', $report->configProblems[1]);
        self::assertStringContainsString('heartbeat', $report->configProblems[2]);
        self::assertFalse($report->heartbeatAdded);
        self::assertCount(1, $h->logger->level('warning'));
    }

    public function testP17AFlushWhileTheLockIsHeldIsLockedAndSendsNothing(): void
    {
        $h = new PipeHarness();
        $this->seed($h, 'a', (int) self::B - 1);
        $this->at($h, 10);
        $token = $h->store->acquireLock('flush', 120);
        self::assertIsString($token);

        $locked = $h->aggregator->flush();
        self::assertSame('locked', $locked->status);
        self::assertSame([], $h->transport->requests());

        $h->store->releaseLock('flush', $token);
        $h->accept();
        $again = $h->aggregator->flush();
        self::assertSame(1, $again->bucketsSent);
        self::assertCount(1, $h->transport->requests());
    }

    public function testP18AMalformedTokenKeepsEveryBucketAndStillAddsTheHeartbeat(): void
    {
        $h = new PipeHarness(['heartbeat' => 'app.flushes'], ['metricsToken' => new CallableTokenProvider(static fn (): string => 'wt15ms_short')]);
        $this->seed($h, 'a', (int) self::B - 1);
        $this->at($h, 10);

        $report = $h->aggregator->flush();

        self::assertSame([], $h->transport->requests());
        self::assertSame('config', $report->stopReason);
        self::assertSame(1, $report->errors['config']);
        self::assertTrue($report->heartbeatAdded);
        self::assertSame(1, $h->store->read((int) self::B)['count']['app.flushes']);
        self::assertSame(1, $h->store->read((int) self::B - 1)['count']['a']);
    }

    public function testP24RejectionsAreReportedAndWarnedWithoutAValue(): void
    {
        $h = new PipeHarness();
        $this->seed($h, 'secret.metric.name', (int) self::B - 1);
        $this->at($h, 10);
        $h->transport->append(MockTransport::metricsAccepted(0, ['metric_awaiting_approval' => 2]));

        $report = $h->aggregator->flush();

        self::assertSame(['metric_awaiting_approval' => 2], $report->rejected);
        $warnings = $h->logger->level('warning');
        self::assertCount(1, $warnings);
        self::assertSame(['metric_awaiting_approval' => 2], $warnings[0]['context']['rejected']);
        self::assertStringNotContainsString('secret.metric.name', (string) json_encode($warnings));
    }
}
