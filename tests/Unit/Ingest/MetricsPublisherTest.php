<?php

declare(strict_types=1);

namespace WatchTower15\Tests\Unit\Ingest;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WatchTower15\Exception\AuthenticationException;
use WatchTower15\Exception\InvalidRequestException;
use WatchTower15\Exception\RateLimitException;
use WatchTower15\Ingest\IngestResult;
use WatchTower15\Ingest\InvalidPayloadException;
use WatchTower15\Internal\ChunkPusher;
use WatchTower15\Testing\MockTransport;
use WatchTower15\Testing\RecordedRequest;
use WatchTower15\Tests\Support\Harness;
use WatchTower15\Tests\Support\Keys;

final class MetricsPublisherTest extends TestCase
{
    protected function tearDown(): void
    {
        ChunkPusher::$gzip = true;
    }

    /** @return array<string, mixed> */
    private static function point(int $i = 0, mixed $recordedAt = null): array
    {
        $point = ['metric' => 'orders.total', 'metric_type' => 'count', 'value' => $i];
        if ($recordedAt !== null) {
            $point['recorded_at'] = $recordedAt;
        }

        return $point;
    }

    /**
     * @param int $count
     *
     * @return list<MockTransport|\GuzzleHttp\Psr7\Response>
     */
    private static function accepted(int $count): array
    {
        return array_fill(0, $count, MockTransport::metricsAccepted(1));
    }

    /** @return list<string> */
    private static function keys(Harness $h): array
    {
        return array_map(static fn (RecordedRequest $r): string => (string) $r->header('Idempotency-Key'), $h->transport->requests());
    }

    public function testI1APushIsOneGzippedChunkWithAKey(): void
    {
        $h = new Harness();
        $h->transport->append(MockTransport::metricsAccepted(3));

        $result = $h->client->ingest->metrics->push([self::point(1, 1_798_761_600), self::point(2), self::point(3)]);

        $request = $h->transport->requests()[0];
        self::assertSame('POST', $request->method);
        self::assertSame('https://api.watchtower15.com/api/metric-sources/ingest', $request->uri);
        self::assertStringStartsWith("\x1f\x8b", $request->rawBody);
        self::assertNull($request->header('Content-Encoding'));
        self::assertSame('application/json', $request->header('Content-Type'));
        self::assertIsArray($request->body);
        self::assertCount(3, $request->body['points']);
        self::assertSame('count', $request->body['points'][0]['metric_type']);
        self::assertArrayNotHasKey('recorded_at', $request->body['points'][1]);
        self::assertMatchesRegularExpression('/^wt15php-[0-9a-f]{64}$/', (string) $request->header('Idempotency-Key'));
        self::assertTrue($result->accepted);
        self::assertSame(3, $result->count);
        self::assertSame(3, $result->sent);
        self::assertSame(1, $result->chunks);
        self::assertSame(15, count($result->rejected));
    }

    public function testI1AProxyPathPrefixIsKeptAndAnEmptyPushSendsNothing(): void
    {
        $h = new Harness(['ingestUrl' => 'https://proxy.example/wt15']);
        $h->transport->append(MockTransport::metricsAccepted(1));

        $h->client->ingest->metrics->push([self::point()]);
        self::assertSame('https://proxy.example/wt15/api/metric-sources/ingest', $h->transport->requests()[0]->uri);

        $empty = $h->client->ingest->metrics->push([]);
        self::assertCount(1, $h->transport->requests());
        self::assertFalse($empty->accepted);
        self::assertSame(0, $empty->count);
        self::assertSame(0, $empty->sent);
        self::assertSame(0, $empty->chunks);
        self::assertSame(array_fill_keys(IngestResult::METRIC_REJECTION_REASONS, 0), $empty->rejected);
    }

    public function testI2ChunkingByCountAndByBytes(): void
    {
        $h = new Harness();
        $h->transport->append(...self::accepted(3));
        $points = [];
        for ($i = 0; $i < 2500; $i++) {
            $points[] = self::point($i);
        }
        $h->client->ingest->metrics->push($points);
        $sizes = array_map(static fn (RecordedRequest $r): int => count($r->body['points']), $h->transport->requests());
        self::assertSame([1000, 1000, 500], $sizes);
        self::assertSame(0, $h->transport->requests()[0]->body['points'][0]['value']);
        self::assertSame(2499, $h->transport->requests()[2]->body['points'][499]['value']);

        $h = new Harness();
        $h->transport->append(...self::accepted(2));
        $big = [];
        for ($i = 0; $i < 900; $i++) {
            $point = self::point($i);
            $point['labels'] = ['pad' => str_repeat('x', 1300)];
            $big[] = $point;
        }
        $h->client->ingest->metrics->push($big);
        self::assertCount(2, $h->transport->requests());
        foreach ($h->transport->requests() as $request) {
            self::assertLessThanOrEqual(1_000_000, strlen((string) json_encode($request->body, JSON_UNESCAPED_SLASHES)));
        }

        $h = new Harness();
        $huge = self::point();
        $huge['labels'] = ['pad' => str_repeat('x', 1_100_000)];
        $this->expectException(InvalidPayloadException::class);
        try {
            $h->client->ingest->metrics->push([self::point(), $huge]);
        } finally {
            self::assertSame([], $h->transport->requests());
        }
    }

    public function testI3KeysForRecentPointsCarryTheCallNonce(): void
    {
        $h = new Harness();
        $h->transport->append(...self::accepted(4));
        $recent = [self::point(1, 1_798_761_600), self::point(2, 1_798_761_601)];
        $h->client->ingest->metrics->push($recent);
        $h->client->ingest->metrics->push($recent);
        [$a, $b] = self::keys($h);
        self::assertNotSame($a, $b);

        $untimed = [self::point(1), self::point(2)];
        $h->client->ingest->metrics->push($untimed);
        $h->client->ingest->metrics->push($untimed);
        [, , $c, $d] = self::keys($h);
        self::assertNotSame($c, $d);
        self::assertSame($h->transport->requests()[2]->rawBody === $h->transport->requests()[3]->rawBody, true, 'the bytes are identical, only the key differs');
    }

    public function testI3BackfillChunksUseTheContentKey(): void
    {
        $old = $this->daysAgo(40);
        $h = new Harness();
        $h->transport->append(...self::accepted(6));
        $points = [self::point(1, $old), self::point(2, $old)];
        $h->client->ingest->metrics->push($points);
        $h->client->ingest->metrics->push($points);
        [$a, $b] = self::keys($h);
        self::assertSame($a, $b, 'the same old points are the same key');

        $h->client->ingest->metrics->push([self::point(1, $old), self::point(3, $old)]);
        self::assertNotSame($a, self::keys($h)[2], 'a changed value is another key');

        $other = new Harness(['metricsToken' => Keys::metrics('d')]);
        $other->transport->append(...self::accepted(1));
        $other->client->ingest->metrics->push($points);
        self::assertNotSame($a, self::keys($other)[0], 'another token is another key');

        // A chunk of recent points holding one point 31 days old is a backfill chunk; untimed points ride along.
        $mixed = [self::point(1, $this->daysAgo(31)), self::point(2), self::point(3)];
        $h2 = new Harness();
        $h2->transport->append(...self::accepted(2));
        $h2->client->ingest->metrics->push($mixed);
        $h2->client->ingest->metrics->push($mixed);
        self::assertSame(self::keys($h2)[0], self::keys($h2)[1]);
        self::assertSame(1, count(array_unique(self::keys($h2))));
    }

    public function testI3ACallerKeyIsUsedForOneChunkAndSuffixedForMany(): void
    {
        $h = new Harness();
        $h->transport->append(...self::accepted(4));
        $h->client->ingest->metrics->push([self::point(1)], 'import/2026-10');
        self::assertSame(['import/2026-10'], self::keys($h));

        $points = [];
        for ($i = 0; $i < 2500; $i++) {
            $points[] = self::point($i, $this->daysAgo($i % 2 === 0 ? 40 : 1));
        }
        $h->client->ingest->metrics->push($points, 'import/2026-10');
        self::assertSame(['import/2026-10', 'import/2026-10/0', 'import/2026-10/1', 'import/2026-10/2'], self::keys($h));
    }

    /** @return iterable<string, array{string}> */
    public static function badCallerKeys(): iterable
    {
        yield '101 characters' => [str_repeat('k', 101)];
        yield 'a space' => ['has space'];
        yield 'empty' => [''];
    }

    #[DataProvider('badCallerKeys')]
    public function testI3ABadCallerKeyIsRefusedBeforeSending(string $key): void
    {
        $h = new Harness();
        try {
            $h->client->ingest->metrics->push([self::point()], $key);
            self::fail('expected a refusal');
        } catch (InvalidPayloadException) {
            self::assertSame([], $h->transport->requests());
        }
    }

    private function daysAgo(int $days): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify("-$days days");
    }

    public function testI4PointsAreCheckedBeforeAnythingIsSent(): void
    {
        $bad = [
            'no metric_type' => [['metric' => 'a.b', 'value' => 1]],
            'gauge' => [['metric' => 'a.b', 'metric_type' => 'gauge', 'value' => 1]],
            'string point' => ['not a point'],
            'INF' => [['metric' => 'a.b', 'metric_type' => 'count', 'value' => INF]],
            'NAN' => [['metric' => 'a.b', 'metric_type' => 'count', 'value' => NAN]],
            'invalid utf-8' => [['metric' => "a.\xE9", 'metric_type' => 'count', 'value' => 1]],
        ];
        foreach ($bad as $label => $points) {
            $h = new Harness();
            try {
                /** @phpstan-ignore argument.type */
                $h->client->ingest->metrics->push([self::point(0), ...$points]);
                self::fail("$label should be refused");
            } catch (InvalidPayloadException $e) {
                self::assertStringContainsString('1', $e->getMessage(), (string) $label);
                self::assertSame([], $h->transport->requests(), (string) $label);
            }
        }

        $h = new Harness();
        $h->transport->append(MockTransport::metricsAccepted(1));
        $h->client->ingest->metrics->push([['metric_uuid' => 'a1b2c3d4-0000-4000-8000-000000000000', 'value' => 1]]);
        self::assertCount(1, $h->transport->requests());
    }

    public function testI4ADateObjectBecomesEpochMilliseconds(): void
    {
        $h = new Harness();
        $h->transport->append(MockTransport::metricsAccepted(1));
        $h->client->ingest->metrics->push([self::point(1, new \DateTimeImmutable('@1798761600.250'))]);

        self::assertSame(1_798_761_600_250, $h->transport->requests()[0]->body['points'][0]['recorded_at']);
    }

    public function testI5TheResultSumsChunksAndAlwaysListsEveryReason(): void
    {
        $h = new Harness();
        $reasons = array_fill_keys(IngestResult::METRIC_REJECTION_REASONS, 0);
        $withoutNew = $reasons;
        unset($withoutNew['bad_metric_type']);
        $h->transport->append(
            MockTransport::envelope(200, ['accepted' => true, 'batch_id' => 'b1', 'points' => 1000, 'rejected' => ['future_timestamp' => 2] + $withoutNew]),
            MockTransport::envelope(200, ['accepted' => true, 'batch_id' => 'b2', 'points' => 998, 'rejected' => ['future_reason' => 2, 'too_old' => 1] + $reasons]),
            MockTransport::envelope(200, ['accepted' => false, 'batch_id' => 'b3', 'points' => 0, 'rejected' => $reasons]),
        );
        $points = [];
        for ($i = 0; $i < 2500; $i++) {
            $points[] = self::point($i);
        }

        $result = $h->client->ingest->metrics->push($points);

        self::assertTrue($result->accepted);
        self::assertSame(1998, $result->count);
        self::assertSame(2500, $result->sent);
        self::assertSame(['b1', 'b2', 'b3'], $result->batchIds);
        self::assertCount(3, $result->idempotencyKeys);
        self::assertCount(3, $result->raw);
        self::assertSame(2, $result->rejected['future_timestamp']);
        self::assertSame(1, $result->rejected['too_old']);
        self::assertSame(2, $result->rejected['future_reason']);
        self::assertSame(0, $result->rejected['bad_metric_type']);
        self::assertSame(5, $result->rejectedTotal());
    }

    public function testI6AFailureKeepsThePartialResultAndStopsThere(): void
    {
        $h = new Harness();
        $h->transport->append(MockTransport::metricsAccepted(1000), MockTransport::envelope(400, ['code' => 'bad_request'], 'Bad.'), MockTransport::metricsAccepted(500));
        $points = [];
        for ($i = 0; $i < 2500; $i++) {
            $points[] = self::point($i);
        }

        try {
            $h->client->ingest->metrics->push($points);
            self::fail('expected a 400');
        } catch (InvalidRequestException $e) {
            $partial = $e->partialResult();
            self::assertNotNull($partial);
            self::assertSame(1, $partial->chunks);
            self::assertSame(1000, $partial->count);
        }
        self::assertCount(2, $h->transport->requests());
    }

    public function testI7ListReadsTheTokensSource(): void
    {
        $h = new Harness();
        $h->transport->append(MockTransport::envelope(200, [
            'metrics' => [
                ['uuid' => 'm-1', 'key' => 'orders.total', 'type' => 'count', 'status' => 'active'],
                ['uuid' => 'm-2', 'key' => 'queue.depth', 'type' => 'level', 'status' => 'archived'],
            ],
            'limits' => ['max_metrics' => 25, 'used' => 12],
        ]));

        $listing = $h->client->ingest->metrics->list();

        $request = $h->transport->requests()[0];
        self::assertSame('GET', $request->method);
        self::assertSame('https://api.watchtower15.com/api/metric-sources/ingest', $request->uri);
        self::assertSame('', $request->rawBody);
        self::assertNull($request->header('Idempotency-Key'));
        self::assertCount(2, $listing->metrics);
        self::assertSame('archived', $listing->metrics[1]->status);
        self::assertSame(25, $listing->maxMetrics);
        self::assertSame(12, $listing->used);
        self::assertSame('orders.total', $listing->metrics[0]->toArray()['key']);

        $h->transport->append(MockTransport::envelope(403, ['code' => 'forbidden'], 'No.'));
        $this->expectException(AuthenticationException::class);
        $h->client->ingest->metrics->list();
    }

    public function testR3RetriesResendTheSameBytesAndKey(): void
    {
        $answers = [
            'timeout' => new \WatchTower15\Exception\ApiConnectionException('slow', true, false, 'timeout'),
            '408' => MockTransport::problem(408, 'timeout'),
            '500' => MockTransport::envelope(500, ['code' => 'server_error'], 'Oops'),
            '502' => MockTransport::envelope(502, [], 'bad gateway'),
            '503' => MockTransport::envelope(503, [], 'busy'),
        ];
        foreach ($answers as $label => $first) {
            $h = new Harness();
            $h->transport->append($first, MockTransport::metricsAccepted(1));
            $h->client->ingest->metrics->push([self::point()]);
            [$one, $two] = $h->transport->requests();
            self::assertSame($one->rawBody, $two->rawBody, (string) $label);
            self::assertSame($one->header('Idempotency-Key'), $two->header('Idempotency-Key'), (string) $label);
        }
    }

    public function testR3ThrottleWaitsAreExactAndCappedByMaxRetryWait(): void
    {
        $h = new Harness();
        $h->transport->append(MockTransport::envelope(429, ['code' => 'rate_limited'], 'Slow', ['Retry-After' => '3']), MockTransport::metricsAccepted(1));
        $h->client->ingest->metrics->push([self::point()]);
        self::assertSame([3.0], $h->sleeper->waits);

        $h = new Harness();
        $h->transport->append(MockTransport::envelope(429, ['code' => 'rate_limited'], 'Slow'), MockTransport::metricsAccepted(1));
        $h->client->ingest->metrics->push([self::point()]);
        self::assertSame([60.0], $h->sleeper->waits, 'a 429 with no Retry-After waits the throttle window');

        $h = new Harness();
        $h->transport->append(MockTransport::envelope(429, ['code' => 'rate_limited'], 'Slow'), MockTransport::metricsAccepted(1));
        try {
            $h->client->ingest->metrics->push([self::point()], null, ['max_retry_wait' => 2.0]);
            self::fail('expected the 429');
        } catch (RateLimitException $e) {
            self::assertNull($e->getRetryAfter());
        }
        self::assertCount(1, $h->transport->requests());
    }

    public function testR3NeverRetriedAnswers(): void
    {
        foreach ([400, 403, 413] as $status) {
            $h = new Harness();
            $h->transport->append(MockTransport::envelope($status, ['code' => 'x'], 'No.'), MockTransport::metricsAccepted(1));
            try {
                $h->client->ingest->metrics->push([self::point()]);
                self::fail('expected an exception');
            } catch (\WatchTower15\Exception\ApiException) {
                self::assertCount(1, $h->transport->requests(), (string) $status);
            }
        }
        $h = new Harness();
        $h->transport->append(new \WatchTower15\Exception\ApiConnectionException('big', true, true, 'too_large'), MockTransport::metricsAccepted(1));
        try {
            $h->client->ingest->metrics->push([self::point()]);
            self::fail('expected an exception');
        } catch (\WatchTower15\Exception\ApiConnectionException) {
            self::assertCount(1, $h->transport->requests());
        }
    }

    public function testI9WithoutZlibTheBodiesArePlainJson(): void
    {
        ChunkPusher::$gzip = false;
        $h = new Harness();
        $h->transport->append(MockTransport::metricsAccepted(1));
        $h->client->ingest->metrics->push([self::point()]);

        $request = $h->transport->requests()[0];
        self::assertStringStartsWith('{"points":[', $request->rawBody);
        self::assertMatchesRegularExpression('/^wt15php-[0-9a-f]{64}$/', (string) $request->header('Idempotency-Key'));
    }

    /** @return iterable<string, array{callable(): mixed, bool}> */
    public static function ages(): iterable
    {
        $thirtyOne = time() - 31 * 86400;
        yield 'date object' => [static fn () => new \DateTimeImmutable('@' . $thirtyOne), true];
        yield 'epoch seconds' => [static fn () => $thirtyOne, true];
        yield 'epoch ms' => [static fn () => $thirtyOne * 1000, true];
        yield '10-digit string' => [static fn () => (string) $thirtyOne, true];
        yield 'rfc 3339 string' => [static fn () => gmdate('Y-m-d\TH:i:s\Z', $thirtyOne), true];
        yield '29 days' => [static fn () => time() - 29 * 86400, false];
        yield 'unreadable' => [static fn () => 'not a time', false];
    }

    /** @param callable(): mixed $recordedAt */
    #[DataProvider('ages')]
    public function testI10TheBackfillAgeIsReadAsTheBackendReadsIt(callable $recordedAt, bool $contentKey): void
    {
        $h = new Harness();
        $h->transport->append(...self::accepted(2));
        $points = [self::point(1, $recordedAt())];
        $h->client->ingest->metrics->push($points);
        $h->client->ingest->metrics->push($points);
        [$a, $b] = self::keys($h);

        self::assertMatchesRegularExpression('/^wt15php-[0-9a-f]{64}$/', $a);
        self::assertSame($contentKey, $a === $b);
    }
}
