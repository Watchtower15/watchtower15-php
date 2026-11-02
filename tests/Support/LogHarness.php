<?php

declare(strict_types=1);

namespace WatchTower15\Tests\Support;

use Monolog\Level;
use Monolog\LogRecord;
use WatchTower15\Client;
use WatchTower15\Logs\Breaker\BreakerStore;
use WatchTower15\Logs\Breaker\InMemoryBreakerStore;
use WatchTower15\Logs\LogShipper;
use WatchTower15\Logs\Monolog\WatchTower15Handler;
use WatchTower15\Logs\Psr3Logger;
use WatchTower15\Testing\MockTransport;

/** A handler (or a PSR-3 logger) over a mock transport, one fake clock shared by the client, the store and the shipper. */
final class LogHarness
{
    public Harness $h;

    public FakeClock $clock;

    public BreakerStore $store;

    public RecordingLogger $self;

    public RecordingShutdown $shutdown;

    /** @param array<string, mixed> $clientArguments */
    public function __construct(array $clientArguments = [], ?BreakerStore $store = null)
    {
        LogShipper::reset();
        WatchTower15Handler::resetShutdownMarker();
        $this->h = new Harness($clientArguments);
        $this->clock = $this->h->clock;
        $this->store = $store ?? new InMemoryBreakerStore($this->clock);
        $this->self = new RecordingLogger();
        $this->shutdown = new RecordingShutdown();
    }

    public function client(): Client
    {
        return $this->h->client;
    }

    public function handler(?string $stream = null, Level $level = Level::Debug): WatchTower15Handler
    {
        return new WatchTower15Handler($this->h->client, $stream, $this->self, $this->store, $this->shutdown, $level, true, $this->clock);
    }

    public function logger(?string $stream = null, int $bufferLimit = 100): Psr3Logger
    {
        return new Psr3Logger($this->h->client, $stream, $this->self, $this->store, $bufferLimit, $this->shutdown, $this->clock);
    }

    public function record(string $message = 'hello', Level $level = Level::Error, string $channel = 'billing', array $context = [], array $extra = []): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable('2026-10-31T12:00:00.123+00:00'), $channel, $level, $message, $context, $extra);
    }

    /** Sends one record straight through a fresh handler. */
    public function write(?LogRecord $record = null): void
    {
        $this->handler()->handle($record ?? $this->record());
    }

    public function answers(\Psr\Http\Message\ResponseInterface|\Throwable ...$responses): void
    {
        $this->h->transport->append(...$responses);
    }

    public function ok(int $lines = 1): \Psr\Http\Message\ResponseInterface
    {
        return MockTransport::logsAccepted($lines);
    }

    public function requests(): int
    {
        return count($this->h->transport->requests());
    }

    public function advance(int $seconds): void
    {
        $this->clock->now = $this->clock->now->modify(sprintf('%+d seconds', $seconds));
    }

    public function nowMs(): int
    {
        return (int) floor((float) $this->clock->now->format('U.u') * 1000);
    }

    /** @return list<string> the messages of the self logger's records */
    public function reports(): array
    {
        return array_map(static fn (array $r): string => $r['message'], $this->self->records);
    }
}
