<?php

declare(strict_types=1);

namespace WatchTower15\Tests\Unit\Logs;

use Monolog\Handler\BufferHandler;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use WatchTower15\Logs\Monolog\WatchTower15Handler;
use WatchTower15\Testing\MockTransport;
use WatchTower15\Tests\Support\LogHarness;

/** LG1 to LG4, LG10 and LG11: the Monolog handler's mapping, its batch path and its silence. */
final class HandlerTest extends TestCase
{
    public function testLg1OnePushForABatchWithTheBoundsAndNoCallerKey(): void
    {
        $t = new LogHarness();
        $t->answers($t->ok(3));
        $buffer = new BufferHandler($t->handler(), 100);
        foreach (['one', 'two', 'three'] as $message) {
            $buffer->handle($t->record($message));
        }
        $buffer->close();

        self::assertSame(1, $t->requests());
        $request = $t->h->transport->requests()[0];
        self::assertCount(3, $request->body['lines']);
        self::assertSame('one', $request->body['lines'][0]['message']);
        self::assertSame('2026-10-31T12:00:00.123Z', $request->body['lines'][0]['logged_at']);
        self::assertSame('error', $request->body['lines'][0]['log_level']);
        self::assertSame('billing', $request->body['lines'][0]['stream_name']);
        self::assertSame(3.0, $request->options->timeout);
        self::assertSame(1.0, $request->options->connectTimeout);
        self::assertStringStartsWith('wt15php-', (string) $request->header('Idempotency-Key'));
    }

    public function testLg1TheBatchPathChecksTheHandlersOwnLevelAndRunsItsProcessors(): void
    {
        $t = new LogHarness();
        $t->answers($t->ok(1));
        $inner = $t->handler(null, Level::Warning);
        $inner->pushProcessor(static fn (LogRecord $record): LogRecord => $record->with(extra: ['request' => 'abc']));
        $buffer = new BufferHandler($inner, 100);
        $buffer->handle($t->record('a debug', Level::Debug));
        $buffer->handle($t->record('a warning', Level::Warning));
        $buffer->close();

        self::assertSame(1, $t->requests());
        $lines = $t->h->transport->requests()[0]->body['lines'];
        self::assertCount(1, $lines);
        self::assertStringContainsString('a warning', $lines[0]['message']);
        self::assertStringContainsString('"request":"abc"', $lines[0]['message']);

        $second = new BufferHandler($t->handler(null, Level::Warning), 100);
        $second->handle($t->record('d1', Level::Debug));
        $second->handle($t->record('d2', Level::Debug));
        $second->close();
        self::assertSame(1, $t->requests(), 'no push for two debug records');
    }

    public function testLg2TheEightLevelsAreUnchanged(): void
    {
        $t = new LogHarness();
        $t->answers($t->ok(8));
        $buffer = new BufferHandler($t->handler(), 100);
        foreach (Level::cases() as $level) {
            $buffer->handle($t->record('x', $level));
        }
        $buffer->close();

        $levels = array_column($t->h->transport->requests()[0]->body['lines'], 'log_level');
        sort($levels);
        self::assertSame(['alert', 'critical', 'debug', 'emergency', 'error', 'info', 'notice', 'warning'], $levels);
    }

    public function testLg3TheStreamNameIsTheOptionTheChannelOrNothing(): void
    {
        $t = new LogHarness();
        $t->answers($t->ok(), $t->ok(), $t->ok());
        $t->handler('given')->handle($t->record('a'));
        $t->handler()->handle($t->record('b', Level::Error, 'billing'));
        $t->handler()->handle($t->record('c', Level::Error, 'my app'));

        $requests = $t->h->transport->requests();
        self::assertSame('given', $requests[0]->body['lines'][0]['stream_name']);
        self::assertSame('billing', $requests[1]->body['lines'][0]['stream_name']);
        self::assertArrayNotHasKey('stream_name', $requests[2]->body['lines'][0]);
    }

    public function testLg4MessagesWithExceptionsLineBreaksAndNothing(): void
    {
        $t = new LogHarness();
        $t->answers($t->ok(3));
        $buffer = new BufferHandler($t->handler(), 100);
        $buffer->handle($t->record('failed', Level::Error, 'billing', ['exception' => new \RuntimeException('boom')]));
        $buffer->handle($t->record('first line' . "\n" . 'second line'));
        $buffer->handle($t->record(''));
        $buffer->handle($t->record('plain'));
        $buffer->close();

        $lines = $t->h->transport->requests()[0]->body['lines'];
        self::assertCount(3, $lines);
        self::assertStringContainsString('RuntimeException', $lines[0]['message']);
        self::assertStringContainsString('boom', $lines[0]['message']);
        self::assertStringContainsString('#0', $lines[0]['message']);
        self::assertSame("first line\nsecond line", $lines[1]['message']);
        self::assertSame('plain', $lines[2]['message'], 'no trailing space');
        self::assertSame(['WatchTower15 logs: lines not sent: invalid_payload 1'], $t->reports());
    }

    public function testLg10AReportThroughALoggerHoldingTheHandlerIsNeverSentAndNeverLoops(): void
    {
        $t = new LogHarness();
        $test = new TestHandler();
        $self = null;
        $handler = new WatchTower15Handler($t->client(), null, static function () use (&$self) {
            return $self;
        }, $t->store, $t->shutdown, Level::Debug, true, $t->clock);
        $self = new Logger('self', [$handler, $test]);
        $t->answers(MockTransport::envelope(503, [], 'down'), MockTransport::envelope(503, [], 'down'));

        $handler->handle($t->record());

        self::assertSame(2, $t->requests(), 'one retry, and nothing sent for the report');
        $records = $test->getRecords();
        self::assertCount(1, $records);
        self::assertSame(Level::Warning, $records[0]->level);
        self::assertStringStartsWith('WatchTower15 logs: lines not sent: rejected_503 1', $records[0]->message);
        self::assertTrue($records[0]->context['wt15_report']);
        self::assertSame(['rejected_503' => 1], $records[0]->context['wt15_lines']);
        self::assertMatchesRegularExpression('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z\z/', $records[0]->context['wt15_until']);
    }

    public function testLg10TheSdksOwnRecordsAreDropped(): void
    {
        $t = new LogHarness();
        $t->handler()->handle($t->record('x', Level::Warning, 'app', ['wt15_level' => 'notice']));
        $buffer = new BufferHandler($t->handler(), 100);
        $buffer->handle($t->record('y', Level::Warning, 'app', ['wt15_report' => true]));
        $buffer->close();

        self::assertSame(0, $t->requests());
    }

    public function testLg11AnErrorFromTheTransportIsCountedInternal(): void
    {
        $t = new LogHarness();
        $t->answers(new \Error('the transport broke'));

        $t->handler()->handle($t->record());

        self::assertSame(['WatchTower15 logs: lines not sent: internal 1'], $t->reports());
    }
}
