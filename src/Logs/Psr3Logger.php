<?php

declare(strict_types=1);

namespace WatchTower15\Logs;

use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use WatchTower15\Client;
use WatchTower15\Internal\Clock;
use WatchTower15\Internal\SystemClock;
use WatchTower15\Lifecycle\PhpShutdownStrategy;
use WatchTower15\Lifecycle\ShutdownStrategy;
use WatchTower15\Logs\Breaker\BreakerStore;

/**
 * A PSR-3 logger that sends its lines to WatchTower15 log ingest, for applications without Monolog. Lines are buffered and sent when
 * the buffer holds `bufferLimit` lines (during the request), on `flush()`, at the end of the request after the response, and on
 * destruct. It never throws, but for PSR-3's own `InvalidArgumentException` on an unknown level.
 */
final class Psr3Logger extends AbstractLogger
{
    private const LEVELS = [
        LogLevel::EMERGENCY, LogLevel::ALERT, LogLevel::CRITICAL, LogLevel::ERROR,
        LogLevel::WARNING, LogLevel::NOTICE, LogLevel::INFO, LogLevel::DEBUG,
    ];

    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE;

    private readonly LogShipper $shipper;

    private readonly ShutdownStrategy $strategy;

    private readonly Clock $clock;

    /** @var list<array{message: string, logged_at: \DateTimeInterface, log_level: string, stream_name?: string}> */
    private array $buffer = [];

    private bool $registered = false;

    /**
     * @param Client|\Closure(): Client                         $client
     * @param LoggerInterface|\Closure(): ?LoggerInterface|null $selfLogger
     * @param BreakerStore|\Closure(): BreakerStore|null        $breakerStore
     */
    public function __construct(
        Client|\Closure $client,
        private readonly ?string $stream = null,
        LoggerInterface|\Closure|null $selfLogger = null,
        BreakerStore|\Closure|null $breakerStore = null,
        private readonly int $bufferLimit = 100,
        ?ShutdownStrategy $shutdownStrategy = null,
        ?Clock $clock = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
        $this->shipper = new LogShipper($client, $selfLogger, $breakerStore, $this->clock);
        $this->strategy = $shutdownStrategy ?? new PhpShutdownStrategy();
    }

    public function __destruct()
    {
        $this->flush();
    }

    /**
     * `$message` declares no type so the class loads under every psr/log the core allows (psr/log 3's own signature is a fatal
     * error under 1.1).
     *
     * @param mixed                   $level
     * @param string|\Stringable      $message
     * @param array<array-key, mixed> $context
     *
     * @throws InvalidArgumentException for an unknown level
     */
    public function log($level, $message, array $context = []): void
    {
        if (! is_string($level) || ! in_array($level, self::LEVELS, true)) {
            throw new InvalidArgumentException('Unknown log level.');
        }
        try {
            if (isset($context['wt15_level']) || isset($context['wt15_report']) || LogShipper::busy()) {
                return;
            }
            $line = ['message' => $this->compose($message, $context), 'logged_at' => $this->clock->now(), 'log_level' => $level];
            if ($this->stream !== null) {
                $line['stream_name'] = $this->stream;
            }
            $this->buffer[] = $line;
            if (! $this->registered) {
                $this->registered = true;
                $reference = \WeakReference::create($this);
                $this->strategy->register(static function () use ($reference): void {
                    $reference->get()?->flush();
                });
            }
            if ($this->bufferLimit > 0 && count($this->buffer) >= $this->bufferLimit) {
                $this->flush();
            }
        } catch (\Throwable) {
            // Nothing but PSR-3's own exception reaches the application.
        }
    }

    /** Sends what is buffered now. */
    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }
        $lines = $this->buffer;
        $this->buffer = [];
        $this->shipper->ship($lines);
    }

    /**
     * @param array<array-key, mixed> $context
     */
    private function compose(mixed $message, array $context): string
    {
        $text = is_string($message) ? $message : (is_scalar($message) || $message instanceof \Stringable ? (string) $message : '');
        $replace = [];
        foreach ($context as $key => $value) {
            if (is_float($value) && (is_nan($value) || is_infinite($value))) {
                $replace['{' . $key . '}'] = '0';
            } elseif (is_scalar($value)) {
                $replace['{' . $key . '}'] = (string) $value;
            } elseif ($value instanceof \Stringable) {
                // A __toString() that throws reads as the class name, as in the JSON.
                $read = $this->normalise($value, 0);
                $replace['{' . $key . '}'] = is_string($read) ? $read : '';
            }
        }
        $line = strtr($text, $replace);

        $exception = null;
        if (($context['exception'] ?? null) instanceof \Throwable) {
            $exception = $context['exception'];
            unset($context['exception']);
        }
        if ($context !== []) {
            $json = json_encode($this->normalise($context, 0), self::JSON_FLAGS);
            if (is_string($json)) {
                $line .= ' ' . $json;
            }
        }
        if ($exception !== null) {
            $line .= "\n" . $exception::class . ': ' . $exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine()
                . "\n" . $exception->getTraceAsString();
        }

        return rtrim($line);
    }

    private function normalise(mixed $value, int $depth): mixed
    {
        if ($depth > 8) {
            return '...';
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = $this->normalise($item, $depth + 1);
            }

            return $out;
        }
        if (is_float($value)) {
            return is_nan($value) || is_infinite($value) ? 0 : $value;
        }
        if (is_resource($value)) {
            return '[resource]';
        }
        if (! is_object($value)) {
            return $value;
        }
        try {
            if ($value instanceof \DateTimeInterface) {
                return $value->format('Y-m-d\TH:i:s.vP');
            }
            if ($value instanceof \JsonSerializable) {
                return $this->normalise($value->jsonSerialize(), $depth + 1);
            }
            if ($value instanceof \Stringable) {
                return (string) $value;
            }
        } catch (\Throwable) {
            // Falls through to the class name.
        }

        return '[object ' . $value::class . ']';
    }
}
