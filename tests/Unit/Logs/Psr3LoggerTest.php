<?php

declare(strict_types=1);

namespace WatchTower15\Tests\Unit\Logs;

use PHPUnit\Framework\TestCase;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;
use WatchTower15\Testing\MockTransport;
use WatchTower15\Tests\Support\LogHarness;

/** LG12, LG13 and LG29: the PSR-3 logger. */
final class Psr3LoggerTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private function lines(LogHarness $t, int $request = 0): array
    {
        return $t->h->transport->requests()[$request]->body['lines'];
    }

    public function testLg12MessagesAreInterpolatedAndKeepTheirContextAsJson(): void
    {
        $t = new LogHarness();
        $t->answers($t->ok(1));
        $logger = $t->logger('app');
        $logger->error('User {user} ordered {order}', ['user' => 'ann', 'order' => 42]);
        $logger->flush();

        $line = $this->lines($t)[0];
        self::assertSame('User ann ordered 42 {"user":"ann","order":42}', $line['message']);
        self::assertSame('error', $line['log_level']);
        self::assertSame('app', $line['stream_name']);
        self::assertSame('2026-10-31T12:00:00.000Z', $line['logged_at']);
    }

    public function testLg12AnExceptionIsRenderedWithItsTraceAfterTheJson(): void
    {
        $t = new LogHarness();
        $t->answers($t->ok(1));
        $logger = $t->logger();
        $logger->critical('failed', ['order' => 7, 'exception' => new \RuntimeException('boom')]);
        $logger->flush();

        $message = $this->lines($t)[0]['message'];
        self::assertStringStartsWith('failed {"order":7}' . "\n" . 'RuntimeException: boom in ', $message);
        self::assertStringContainsString('#0', $message);
    }

    public function testLg12EveryLevelIsMappedAndAnUnknownOneIsRefused(): void
    {
        $t = new LogHarness();
        $t->answers($t->ok(8));
        $logger = $t->logger();
        foreach ([LogLevel::EMERGENCY, LogLevel::ALERT, LogLevel::CRITICAL, LogLevel::ERROR, LogLevel::WARNING, LogLevel::NOTICE, LogLevel::INFO, LogLevel::DEBUG] as $level) {
            $logger->log($level, 'x');
        }
        $logger->flush();
        self::assertSame(
            ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'],
            array_column($this->lines($t), 'log_level'),
        );

        $this->expectException(InvalidArgumentException::class);
        $logger->log('loud', 'x');
    }

    public function testLg12ABufferOfOneHundredIsSentDuringTheRequestAndTheRestOnFlushAndDestruct(): void
    {
        $t = new LogHarness();
        $t->answers($t->ok(100), $t->ok(1), $t->ok(1));
        $logger = $t->logger();
        for ($i = 0; $i < 101; $i++) {
            $logger->info('line ' . $i);
        }
        self::assertSame(1, $t->requests());
        self::assertCount(100, $this->lines($t));
        $logger->flush();
        self::assertSame(2, $t->requests());
        self::assertCount(1, $this->lines($t, 1));

        $logger->info('last');
        unset($logger);
        self::assertSame(3, $t->requests(), 'sent on destruct');
    }

    public function testLg12ABufferLimitOfZeroHoldsEverythingUntilFlush(): void
    {
        $t = new LogHarness();
        $t->answers($t->ok(150));
        $logger = $t->logger(null, 0);
        for ($i = 0; $i < 150; $i++) {
            $logger->info('line ' . $i);
        }
        self::assertSame(0, $t->requests());
        $logger->flush();
        self::assertSame(1, $t->requests());
        self::assertCount(150, $this->lines($t));
    }

    public function testLg12TheSdksOwnCallsAreDroppedAndTheSignatureIsUntyped(): void
    {
        $t = new LogHarness();
        $logger = $t->logger();
        $logger->warning('x', ['wt15_report' => true]);
        $logger->warning('y', ['wt15_level' => 'notice']);
        $logger->flush();
        self::assertSame(0, $t->requests());

        $method = new \ReflectionMethod($logger, 'log');
        self::assertNull($method->getParameters()[1]->getType(), '$message declares no type');
        self::assertSame('void', (string) $method->getReturnType());
    }

    public function testLg12AwkwardContextValuesAreNormalised(): void
    {
        $t = new LogHarness();
        $t->answers($t->ok(1));
        $logger = $t->logger();
        $resource = fopen('php://memory', 'r');
        $logger->info('values', [
            'resource' => $resource,
            'nan' => NAN,
            'object' => new \stdClass(),
            'date' => new \DateTimeImmutable('2026-10-31T12:00:00.250+00:00'),
            'bytes' => "bad \xB1 byte",
            'exception' => 'not a throwable',
            'nested' => ['inf' => INF],
        ]);
        $logger->flush();

        $message = $this->lines($t)[0]['message'];
        self::assertStringContainsString('"resource":"[resource]"', $message);
        self::assertStringContainsString('"nan":0', $message);
        self::assertStringContainsString('"object":"[object stdClass]"', $message);
        self::assertStringContainsString('"date":"2026-10-31T12:00:00.250+00:00"', $message);
        self::assertStringContainsString("bad \u{FFFD} byte", $message);
        self::assertStringContainsString('"exception":"not a throwable"', $message);
        self::assertStringContainsString('"inf":0', $message);
    }

    /** @return iterable<string, array{list<\Psr\Http\Message\ResponseInterface|\Throwable>, string}> */
    public static function failures(): iterable
    {
        yield '503 twice' => [[MockTransport::envelope(503, [], 'down'), MockTransport::envelope(503, [], 'down')], 'rejected_503 1'];
        yield '403' => [[MockTransport::envelope(403, [], 'no')], 'rejected_403 1'];
        yield 'the throttle' => [[MockTransport::envelope(429, [], 'slow', ['Retry-After' => '30'])], 'rate_limited 1'];
        yield 'the volume stop' => [[MockTransport::envelope(429, ['code' => 'log_volume_limit_reached'], 'Stop', ['Retry-After' => '600'])], 'volume_limit 1'];
        yield 'a 400' => [[MockTransport::envelope(400, [], 'bad')], 'rejected_400 1'];
        yield 'an error from the transport' => [[new \Error('broken')], 'internal 1'];
    }

    /**
     * @param list<\Psr\Http\Message\ResponseInterface|\Throwable> $answers
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function testLg13NoFailureEverThrowsAndEachIsCountedByReason(array $answers, string $report): void
    {
        $t = new LogHarness();
        $t->answers(...$answers);
        $logger = $t->logger();
        $logger->error('x');
        $logger->flush();

        self::assertSame(['WatchTower15 logs: lines not sent: ' . $report], $t->reports());
    }

    public function testLg29TheEndOfRequestFlushGoesThroughTheStrategyOnce(): void
    {
        $t = new LogHarness();
        $t->answers($t->ok(2));
        $logger = $t->logger();
        $logger->info('a');
        $logger->info('b');
        self::assertCount(1, $t->shutdown->callbacks, 'registered once, at the first line');
        self::assertSame(0, $t->requests());
        $t->shutdown->runAll();
        self::assertSame(1, $t->requests());
        self::assertCount(2, $this->lines($t));

        $t = new LogHarness();
        $t->answers($t->ok(100), $t->ok(1));
        $second = $t->logger();
        for ($i = 0; $i < 101; $i++) {
            $second->info('line');
        }
        self::assertSame(1, $t->requests(), 'the push at 100 is made at once');
        self::assertCount(1, $t->shutdown->callbacks);
        $t->shutdown->runAll();
        self::assertSame(2, $t->requests());
        self::assertCount(1, $this->lines($t, 1));

        $t = new LogHarness();
        $t->answers($t->ok(2));
        $third = $t->logger();
        $third->info('a');
        $third->info('b');
        unset($third);
        self::assertSame(1, $t->requests(), 'the destructor sent the lines');
        $t->shutdown->runAll();
        self::assertSame(1, $t->requests(), 'the callback then sends nothing and throws nothing');
    }
}
