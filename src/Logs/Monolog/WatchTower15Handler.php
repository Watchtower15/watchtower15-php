<?php

declare(strict_types=1);

namespace WatchTower15\Logs\Monolog;

use Monolog\Formatter\FormatterInterface;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use WatchTower15\Client;
use WatchTower15\Internal\Clock;
use WatchTower15\Lifecycle\PhpShutdownStrategy;
use WatchTower15\Lifecycle\ShutdownStrategy;
use WatchTower15\Logs\Breaker\BreakerStore;
use WatchTower15\Logs\LogShipper;

/**
 * Sends Monolog records to WatchTower15 log ingest: the levels unchanged (Monolog 3's eight are ingest's eight), the channel or
 * `stream` as `stream_name`, one push for a batch. Wrap it in Monolog's `BufferHandler` so a request's lines go in one push after the
 * response; unwrapped it sends each record as it is logged. It never throws and never logs its own failures through itself.
 *
 * Monolog is a suggestion of this package, not a requirement: this is the only class that names it.
 */
class WatchTower15Handler extends AbstractProcessingHandler
{
    /** True once a shutdown function has run: pushes asked for from then on wait for the end of the request. */
    private static bool $shuttingDown = false;

    private static bool $markerRegistered = false;

    private readonly LogShipper $shipper;

    private readonly ShutdownStrategy $strategy;

    /**
     * @param Client|\Closure(): Client                         $client
     * @param LoggerInterface|\Closure(): ?LoggerInterface|null $selfLogger  where the one report of a flush goes (never a logger that holds this handler)
     * @param BreakerStore|\Closure(): BreakerStore|null        $breakerStore a `FileBreakerStore` under the system temporary directory by default
     * @param int|string|Level                                  $level
     *
     * @phpstan-param value-of<Level::VALUES>|value-of<Level::NAMES>|Level|LogLevel::* $level
     */
    public function __construct(
        Client|\Closure $client,
        private readonly ?string $stream = null,
        LoggerInterface|\Closure|null $selfLogger = null,
        BreakerStore|\Closure|null $breakerStore = null,
        ?ShutdownStrategy $shutdownStrategy = null,
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
        ?Clock $clock = null,
    ) {
        parent::__construct(Logger::toMonologLevel($level), $bubble);
        $this->shipper = new LogShipper($client, $selfLogger, $breakerStore, $clock);
        $this->strategy = $shutdownStrategy ?? new PhpShutdownStrategy();
        if (! self::$markerRegistered) {
            self::$markerRegistered = true;
            register_shutdown_function(static function (): void {
                self::$shuttingDown = true;
            });
        }
    }

    /** @internal for tests: forgets the shutdown marker. */
    public static function resetShutdownMarker(): void
    {
        self::$shuttingDown = false;
        self::$markerRegistered = false;
    }

    public function handle(LogRecord $record): bool
    {
        if (self::isOwn($record)) {
            return false === $this->bubble;
        }

        return parent::handle($record);
    }

    /**
     * Does for each record what `handle()` does for one (its level, its processors, its formatter) and pushes only when a record is
     * left: `BufferHandler` hands its whole buffer here without asking.
     *
     * @param array<LogRecord> $records
     */
    public function handleBatch(array $records): void
    {
        $lines = [];
        foreach ($records as $record) {
            if (self::isOwn($record) || ! $this->isHandling($record)) {
                continue;
            }
            if ($this->processors !== []) {
                $record = $this->processRecord($record);
            }
            $record->formatted = $this->getFormatter()->format($record);
            $lines[] = $this->line($record);
        }
        if ($lines !== []) {
            $this->dispatch($lines);
        }
    }

    protected function write(LogRecord $record): void
    {
        $this->dispatch([$this->line($record)]);
    }

    protected function getDefaultFormatter(): FormatterInterface
    {
        return new LineFormatter('%message% %context% %extra%', null, true, true, true);
    }

    /**
     * @return array{message: string, logged_at: \DateTimeInterface, log_level: string, stream_name: string}
     */
    private function line(LogRecord $record): array
    {
        $formatted = $record->formatted;

        return [
            'message' => rtrim(is_string($formatted) ? $formatted : (string) json_encode($formatted)),
            'logged_at' => $record->datetime,
            'log_level' => $record->level->toPsrLogLevel(),
            'stream_name' => $this->stream ?? $record->channel,
        ];
    }

    /**
     * @param list<array{message: string, logged_at: \DateTimeInterface, log_level: string, stream_name: string}> $lines
     */
    private function dispatch(array $lines): void
    {
        if (LogShipper::busy()) {
            return;
        }
        $shipper = $this->shipper;
        if (self::$shuttingDown) {
            $this->strategy->register(static function () use ($shipper, $lines): void {
                $shipper->ship($lines);
            });

            return;
        }
        $shipper->ship($lines);
    }

    /** A record the SDK wrote itself (SDK04's `LevelLogger`, or a flush's own report) is never sent. */
    private static function isOwn(LogRecord $record): bool
    {
        return isset($record->context['wt15_level']) || isset($record->context['wt15_report']);
    }
}
