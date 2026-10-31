<?php

declare(strict_types=1);

namespace WatchTower15\Tests\Unit\Testing;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use WatchTower15\Client;
use WatchTower15\Testing\FakeClient;
use WatchTower15\Testing\MockTransport;
use WatchTower15\Tests\Support\Harness;

final class FakesTest extends TestCase
{
    public function testF1TheMockTransportAnswersInOrderAndRecords(): void
    {
        $h = new Harness();
        $h->transport->append(Harness::meBody(), Harness::meBody());
        $h->client->me();
        $h->client->me();

        self::assertCount(2, $h->transport->requests());
        self::assertStringStartsWith('Bearer wt15ak_…', (string) $h->transport->requests()[0]->header('Authorization'));

        try {
            $h->client->me();
            self::fail('expected a LogicException');
        } catch (\LogicException $e) {
            self::assertStringContainsString('GET', $e->getMessage());
            self::assertStringContainsString('/v1/me', $e->getMessage());
        }
    }

    public function testF1RecordedBodiesAreDecoded(): void
    {
        $h = new Harness();
        $h->transport->append(MockTransport::metricsAccepted(1));
        $h->client->ingest->metrics->push([['metric' => 'a.b', 'metric_type' => 'count', 'value' => 1]]);

        self::assertSame('a.b', $h->transport->requests()[0]->body['points'][0]['metric']);
    }

    public function testF2TheFakeClientAnswersPushesItself(): void
    {
        $fake = new FakeClient();

        $metrics = $fake->ingest->metrics->push([
            ['metric' => 'a.b', 'metric_type' => 'count', 'value' => 1],
            ['metric' => 'a.b', 'metric_type' => 'count', 'value' => 2],
            ['metric' => 'a.c', 'metric_type' => 'level', 'value' => 3],
        ]);
        $logs = $fake->ingest->logs->push([['message' => 'one'], ['message' => 'two']]);

        self::assertTrue($metrics->accepted);
        self::assertSame(3, $metrics->count);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $metrics->batchIds[0]);
        self::assertSame(0, $metrics->rejectedTotal());
        self::assertSame(15, count($metrics->rejected));
        self::assertSame(2, $logs->count);

        $fake->assertPushed(static fn (array $p): bool => ($p['value'] ?? null) === 3);
        $fake->assertPushed(static fn (array $p): bool => ($p['message'] ?? null) === 'two');
        $fake->assertCalled('ingest.metrics.push', null, 1);
        $fake->assertCalled('ingest.logs.push');
        $fake->assertNotCalled('public.me.view');
        self::assertCount(3, $fake->pushedPoints());
        self::assertCount(2, $fake->pushedLines());
        self::assertCount(2, $fake->calls());

        try {
            $fake->me();
            self::fail('expected a LogicException');
        } catch (\LogicException) {
            self::addToAssertionCount(1);
        }

        $this->expectException(AssertionFailedError::class);
        $fake->assertNotCalled('ingest.logs.push');
    }

    public function testF2AFreshFakeHasNothingPushed(): void
    {
        $fake = new FakeClient();
        $fake->assertNothingPushed();
        $fake->ingest->logs->push([['message' => 'x']]);

        $this->expectException(AssertionFailedError::class);
        $fake->assertNothingPushed();
    }

    public function testF3TheFakeIsAClientAndAnswersQueuedOperations(): void
    {
        $fake = new FakeClient();
        $typed = static fn (Client $client): string => $client->me()->keyName;
        $fake->queue('public.me.view', Harness::meBody());

        self::assertSame('CI key', $typed($fake));
        $fake->ingest->metrics->push([['metric' => 'a.b', 'metric_type' => 'count', 'value' => 1]]);
        self::assertSame(['public.me.view', 'ingest.metrics.push'], array_map(static fn ($c): string => $c->operation, $fake->calls()));
    }
}
