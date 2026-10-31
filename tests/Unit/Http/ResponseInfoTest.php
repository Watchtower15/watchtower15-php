<?php

declare(strict_types=1);

namespace WatchTower15\Tests\Unit\Http;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use WatchTower15\Exception\NotFoundException;
use WatchTower15\Testing\MockTransport;
use WatchTower15\Tests\Support\Harness;

final class ResponseInfoTest extends TestCase
{
    public function testR6LastResponse(): void
    {
        $h = new Harness();
        self::assertNull($h->client->lastResponse());

        $me = Harness::meBody()->withHeader('X-RateLimit-Limit', '60')->withHeader('X-RateLimit-Remaining', '59')->withHeader('X-RateLimit-Reset', '1798761660')->withHeader('X-Request-Id', 'req-1');
        $h->transport->append($me);
        $h->client->me();
        $info = $h->client->lastResponse();
        self::assertNotNull($info);
        self::assertSame(200, $info->statusCode);
        self::assertSame('req-1', $info->requestId);
        self::assertSame('public.me.view', $info->operation);
        self::assertFalse($info->replayed);
        self::assertNotNull($info->rateLimit);
        self::assertSame(60, $info->rateLimit->limit);
        self::assertSame(59, $info->rateLimit->remaining);
        self::assertSame(1798761660, $info->rateLimit->resetAt?->getTimestamp());

        $h->transport->append(MockTransport::problem(404, 'not_found'));
        try {
            $h->client->me();
            self::fail('expected 404');
        } catch (NotFoundException) {
            self::assertSame(404, $h->client->lastResponse()?->statusCode);
        }

        $h->transport->append(Harness::meBody()->withHeader('Idempotent-Replayed', 'true'));
        $h->client->me();
        self::assertTrue($h->client->lastResponse()?->replayed);

        $h->transport->append(MockTransport::metricsAccepted(1)->withHeader('X-RateLimit-Limit', '60')->withHeader('X-RateLimit-Remaining', '58'));
        $h->client->ingest->metrics->push([['metric' => 'a.b', 'metric_type' => 'count', 'value' => 1]]);
        $rate = $h->client->lastResponse()?->rateLimit;
        self::assertSame(60, $rate?->limit);
        self::assertSame(58, $rate?->remaining);
        self::assertNull($rate?->resetAt);

        $h->transport->append(new Response(200, ['Content-Type' => 'application/json'], '{"data":{}}'));
        $h->client->me();
        self::assertNull($h->client->lastResponse()?->rateLimit);
    }

    public function testR7DeprecationAndSunsetAreLoggedOncePerOperation(): void
    {
        \WatchTower15\Internal\DeprecationNotices::reset();
        $h = new Harness();
        $deprecated = Harness::meBody()->withHeader('Deprecation', '@1798761600')->withHeader('Sunset', 'Wed, 30 Jun 2027 00:00:00 GMT');
        $h->transport->append($deprecated, $deprecated);
        $h->client->me();
        $h->client->me();

        $warnings = $h->logger->level('warning');
        self::assertCount(1, $warnings);
        self::assertStringContainsString('public.me.view', $warnings[0]['message']);
        self::assertSame('@1798761600', $warnings[0]['context']['deprecation']);
        self::assertSame('Wed, 30 Jun 2027 00:00:00 GMT', $warnings[0]['context']['sunset']);

        $h->transport->append(MockTransport::envelope(200, ['metrics' => [], 'limits' => []], 'ok', ['Deprecation' => '@1798761600']));
        $h->client->ingest->metrics->list();
        self::assertCount(2, $h->logger->level('warning'));
        \WatchTower15\Internal\DeprecationNotices::reset();
    }

    public function testR8RetriesAreLoggedAsNoticesWithoutSecrets(): void
    {
        $h = new Harness();
        $h->transport->append(MockTransport::problem(503, 'unavailable'), MockTransport::problem(503, 'unavailable'), Harness::meBody());
        $h->client->me();

        $notices = $h->logger->level('notice');
        self::assertCount(2, $notices);
        foreach ($notices as $i => $record) {
            self::assertSame('public.me.view', $record['context']['operation']);
            self::assertSame($i + 1, $record['context']['attempt']);
            self::assertStringContainsString('HTTP 503', $record['context']['reason']);
            self::assertStringNotContainsString('wt15', json_encode($record) ?: '');
            self::assertStringNotContainsString('Bearer', json_encode($record) ?: '');
        }
    }
}
