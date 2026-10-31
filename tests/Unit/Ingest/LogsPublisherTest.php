<?php

declare(strict_types=1);

namespace WatchTower15\Tests\Unit\Ingest;

use PHPUnit\Framework\TestCase;
use WatchTower15\Exception\LogVolumeLimitException;
use WatchTower15\Exception\RateLimitException;
use WatchTower15\Ingest\InvalidPayloadException;
use WatchTower15\Internal\Utf8;
use WatchTower15\Testing\MockTransport;
use WatchTower15\Tests\Support\Harness;

final class LogsPublisherTest extends TestCase
{
    private function accepted(Harness $h, int $chunks): void
    {
        for ($i = 0; $i < $chunks; $i++) {
            $h->transport->append(MockTransport::logsAccepted(1, 'batch-' . $i));
        }
    }

    public function testI8ALogPushIsGzippedAndKeyed(): void
    {
        $h = new Harness();
        $this->accepted($h, 1);

        $result = $h->client->ingest->logs->push([
            ['message' => 'one', 'log_level' => 'info'],
            ['message' => 'two'],
            ['message' => 'three', 'logged_at' => new \DateTimeImmutable('2026-10-31T12:00:00.123+02:00')],
        ]);

        $request = $h->transport->requests()[0];
        self::assertSame('https://api.watchtower15.com/api/log-sources/ingest', $request->uri);
        self::assertStringStartsWith("\x1f\x8b", $request->rawBody);
        self::assertCount(3, $request->body['lines']);
        self::assertArrayNotHasKey('logged_at', $request->body['lines'][1]);
        self::assertSame('2026-10-31T10:00:00.123Z', $request->body['lines'][2]['logged_at']);
        self::assertMatchesRegularExpression('/^wt15php-[0-9a-f]{64}$/', (string) $request->header('Idempotency-Key'));
        self::assertSame('logs', $result->kind);
        self::assertSame([], $result->rejected);
    }

    public function testI8LongMessagesAreCutOnACharacterBoundary(): void
    {
        $h = new Harness();
        $this->accepted($h, 1);
        $long = str_repeat('a', 70_000);
        // Byte 65,536 falls inside a 4-byte character: 65,534 ASCII bytes, then U+1F600 (4 bytes).
        $emoji = str_repeat('a', 65_534) . "\u{1F600}" . 'tail';
        $h->client->ingest->logs->push([['message' => $long], ['message' => $emoji, 'raw_line' => $long]]);

        $lines = $h->transport->requests()[0]->body['lines'];
        self::assertSame(65_536, strlen($lines[0]['message']));
        self::assertSame(65_534, strlen($lines[1]['message']));
        self::assertSame(65_536, strlen($lines[1]['raw_line']));
        if (function_exists('mb_strcut')) {
            self::assertSame(mb_strcut($emoji, 0, 65_536), $lines[1]['message']);
        }
    }

    public function testUtf8CutNeverSplitsACharacter(): void
    {
        self::assertSame('ab', Utf8::cut('ab', 5));
        self::assertSame('a', Utf8::cut("a\u{00E9}", 2), 'é is two bytes: only 1 byte of it fits');
        self::assertSame("a\u{00E9}", Utf8::cut("a\u{00E9}b", 3));
        self::assertSame('', Utf8::cut("\u{20AC}", 2));
        self::assertSame("\u{20AC}", Utf8::cut("\u{20AC}x", 3));
    }

    public function testI8BlankAndMissingMessagesAreRefused(): void
    {
        foreach ([[['level' => 'x']], [['message' => "  \n"]], ['not an array']] as $lines) {
            $h = new Harness();
            try {
                /** @phpstan-ignore argument.type */
                $h->client->ingest->logs->push($lines);
                self::fail('expected a refusal');
            } catch (InvalidPayloadException $e) {
                self::assertStringContainsString('0', $e->getMessage());
                self::assertSame([], $h->transport->requests());
            }
        }
    }

    public function testI8ManyLinesAreChunkedByCountAndBytes(): void
    {
        $h = new Harness();
        $this->accepted($h, 3);
        $lines = [];
        for ($i = 0; $i < 2500; $i++) {
            $lines[] = ['message' => 'line ' . $i];
        }
        $h->client->ingest->logs->push($lines);
        self::assertSame([1000, 1000, 500], array_map(static fn ($r): int => count($r->body['lines']), $h->transport->requests()));

        $h = new Harness();
        $this->accepted($h, 5);
        $big = [];
        for ($i = 0; $i < 200; $i++) {
            $big[] = ['message' => str_repeat('m', 60_000)];
        }
        $h->client->ingest->logs->push($big);
        self::assertGreaterThanOrEqual(3, count($h->transport->requests()));
        foreach ($h->transport->requests() as $request) {
            self::assertLessThanOrEqual(5_000_000, strlen((string) json_encode($request->body, JSON_UNESCAPED_SLASHES)));
        }
        self::assertSame(200, array_sum(array_map(static fn ($r): int => count($r->body['lines']), $h->transport->requests())));
    }

    public function testI8ALineWhoseBulkSitsInADroppedFieldIsRefused(): void
    {
        $h = new Harness();
        try {
            $h->client->ingest->logs->push([['message' => 'ok'], ['message' => 'ok', 'context' => str_repeat('x', 5_100_000)]]);
            self::fail('expected a refusal');
        } catch (InvalidPayloadException $e) {
            self::assertStringContainsString('1', $e->getMessage());
            self::assertSame([], $h->transport->requests());
        }
    }

    public function testI8InvalidUtf8IsSubstitutedAndInfIsRefused(): void
    {
        $h = new Harness();
        $this->accepted($h, 1);
        $h->client->ingest->logs->push([['message' => "bad \xE9 byte"]]);
        self::assertSame("bad \u{FFFD} byte", $h->transport->requests()[0]->body['lines'][0]['message']);

        $this->expectException(InvalidPayloadException::class);
        $h->client->ingest->logs->push([['message' => 'x'], ['message' => 'y', 'context' => INF]]);
    }

    public function testI8AnEmptyPushSendsNothing(): void
    {
        $h = new Harness();
        $result = $h->client->ingest->logs->push([]);

        self::assertSame([], $h->transport->requests());
        self::assertFalse($result->accepted);
        self::assertSame(0, $result->chunks);
    }

    public function testR3ALogPushRetriesWithTheSameKeyButNotTheLogStop(): void
    {
        $h = new Harness();
        $h->transport->append(MockTransport::envelope(503, [], 'busy'), MockTransport::logsAccepted(1));
        $h->client->ingest->logs->push([['message' => 'x']]);
        [$one, $two] = $h->transport->requests();
        self::assertSame($one->header('Idempotency-Key'), $two->header('Idempotency-Key'));
        self::assertSame($one->rawBody, $two->rawBody);

        $h = new Harness();
        $h->transport->append(MockTransport::envelope(429, ['code' => 'log_volume_limit_reached', 'resumes_at' => 1_798_848_000_000], 'Stop', ['Retry-After' => '3000']), MockTransport::logsAccepted(1));
        try {
            $h->client->ingest->logs->push([['message' => 'x']]);
            self::fail('expected the log stop');
        } catch (LogVolumeLimitException $e) {
            self::assertSame(3000, $e->getRetryAfter());
            self::assertNotNull($e->resumesAt());
        }
        self::assertCount(1, $h->transport->requests());
    }

    public function testR5ALogPushWithAShortMaxRetryWaitThrowsTheThrottleAtOnce(): void
    {
        $h = new Harness();
        $h->transport->append(MockTransport::envelope(429, ['code' => 'rate_limited'], 'Slow', ['Retry-After' => '3']), MockTransport::logsAccepted(1));

        try {
            $h->client->ingest->logs->push([['message' => 'x']], null, ['max_retry_wait' => 2.0]);
            self::fail('expected the 429');
        } catch (RateLimitException $e) {
            self::assertSame(3, $e->getRetryAfter());
        }
        self::assertCount(1, $h->transport->requests());
    }

    public function testLogKeysUseTheNonceUnlessTheCallerGivesOne(): void
    {
        $h = new Harness();
        $this->accepted($h, 4);
        $lines = [['message' => 'a', 'logged_at' => '2026-10-31T12:00:00Z'], ['message' => 'b']];
        $h->client->ingest->logs->push($lines);
        $h->client->ingest->logs->push($lines);
        $keys = array_map(static fn ($r): string => (string) $r->header('Idempotency-Key'), $h->transport->requests());
        self::assertNotSame($keys[0], $keys[1]);

        $h->client->ingest->logs->push($lines, 'logs/1');
        $h->client->ingest->logs->push($lines, 'logs/1');
        [, , $c, $d] = $h->transport->requests();
        self::assertSame('logs/1', $c->header('Idempotency-Key'));
        self::assertSame('logs/1', $d->header('Idempotency-Key'));
        self::assertSame($c->rawBody, $d->rawBody, 'a re-run of untimed lines sends the same bytes');
    }
}
