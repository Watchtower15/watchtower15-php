<?php

declare(strict_types=1);

namespace WatchTower15\Logs;

use Psr\Log\LoggerInterface;
use WatchTower15\Client;
use WatchTower15\ConfigurationException;
use WatchTower15\Exception\ApiConnectionException;
use WatchTower15\Exception\ApiException;
use WatchTower15\Exception\LogVolumeLimitException;
use WatchTower15\Exception\RateLimitException;
use WatchTower15\Exception\UnexpectedResponseException;
use WatchTower15\Ingest\InvalidPayloadException;
use WatchTower15\Internal\Clock;
use WatchTower15\Internal\SystemClock;
use WatchTower15\Logs\Breaker\Breaker;
use WatchTower15\Logs\Breaker\BreakerStore;
use WatchTower15\Logs\Breaker\FileBreakerStore;

/**
 * Everything after the mapping of a record or a call to a line, shared by the Monolog handler and the PSR-3 logger: the
 * re-entrancy flag, the client, the token's hash, the breaker, the bounds, the push, the counts by reason, the stop or hold
 * a failure opens, and the one report. It never throws and never logs through the handler that called it.
 *
 * @internal
 */
final class LogShipper
{
    /** The longest a stop or a hold lasts, and the longest a standing condition goes unreported. */
    private const HOUR_MS = 3_600_000;

    private const HOLD_MS = 60_000;

    private const PROBE_MS = 10_000;

    private const STREAM_PATTERN = '~\A[A-Za-z0-9._/@:+-]{1,255}\z~';

    /** Per push: the bounds that keep a flush from holding a worker (D4). */
    private const OPTIONS = ['timeout' => 3, 'connect_timeout' => 1, 'max_retries' => 1, 'max_retry_wait' => 2];

    private static bool $busy = false;

    private readonly Clock $clock;

    private ?BreakerStore $resolvedStore = null;

    /**
     * @param Client|\Closure(): Client                               $client
     * @param LoggerInterface|\Closure(): ?LoggerInterface|null       $selfLogger
     * @param BreakerStore|\Closure(): BreakerStore|null              $breakerStore
     */
    public function __construct(
        private readonly Client|\Closure $client,
        private readonly LoggerInterface|\Closure|null $selfLogger = null,
        private readonly BreakerStore|\Closure|null $breakerStore = null,
        ?Clock $clock = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
    }

    /** True while a shipper sends or reports: a record that reaches a handler or logger now is dropped. */
    public static function busy(): bool
    {
        return self::$busy;
    }

    /** @internal for tests: clears the flag. */
    public static function reset(): void
    {
        self::$busy = false;
    }

    /**
     * @param list<array{message: string, logged_at?: \DateTimeInterface, log_level?: string, stream_name?: string}> $lines
     */
    public function ship(array $lines): void
    {
        if ($lines === [] || self::$busy) {
            return;
        }
        self::$busy = true;
        $counts = [];
        $standing = [];
        $untilMs = null;
        $key = 'none';
        try {
            try {
                $this->run($lines, $counts, $standing, $untilMs, $key);
            } catch (\Throwable) {
                $this->add($counts, 'internal', count($lines) - array_sum($counts));
            }
            $this->report($counts, $standing, $untilMs, $key);
        } catch (\Throwable) {
            // Nothing reaches the application.
        } finally {
            self::$busy = false;
        }
    }

    /**
     * @param list<array{message: string, logged_at?: \DateTimeInterface, log_level?: string, stream_name?: string}> $lines
     * @param array<string, int>                                                                                     $counts
     * @param array<string, true>                                                                                    $standing
     */
    private function run(array $lines, array &$counts, array &$standing, ?int &$untilMs, string &$key): void
    {
        try {
            $client = $this->client instanceof \Closure ? ($this->client)() : $this->client;
        } catch (ConfigurationException) {
            $counts['config'] = count($lines);
            $standing['config'] = true;

            return;
        }

        $hash = $client->logsTokenHash();
        if ($hash === null) {
            $counts['missing_token'] = count($lines);
            $standing['missing_token'] = true;

            return;
        }
        $key = $hash;
        $store = $this->store();
        $now = $this->nowMs();
        $entry = $store->read($hash);
        if ($entry !== null && $entry->untilMs > $now) {
            $reason = $entry->reason === 'volume_limit' ? 'volume_limit' : 'held';
            $counts[$reason] = count($lines);
            $standing[$reason] = true;

            return;
        }

        $send = [];
        foreach ($lines as $line) {
            if (trim($line['message']) === '') {
                $counts['invalid_payload'] = ($counts['invalid_payload'] ?? 0) + 1;

                continue;
            }
            if (isset($line['stream_name']) && preg_match(self::STREAM_PATTERN, $line['stream_name']) !== 1) {
                unset($line['stream_name']);
            }
            $send[] = $line;
        }
        if ($send === []) {
            return;
        }

        $probeUntil = null;
        if ($entry instanceof Breaker) {
            $probeUntil = $now + self::PROBE_MS;
            $store->open($hash, $probeUntil, $entry->reason);
        }

        $opened = $this->push($client, $send, $store, $hash, $counts, $standing, $untilMs);

        if (! $opened && $probeUntil !== null) {
            $store->clear($hash, $probeUntil);
        }
    }

    /**
     * Pushes the lines and counts what fails; returns whether a stop or a hold was written.
     *
     * @param list<array{message: string, logged_at?: \DateTimeInterface, log_level?: string, stream_name?: string}> $send
     * @param array<string, int>                                                                                     $counts
     * @param array<string, true>                                                                                    $standing
     */
    private function push(Client $client, array $send, BreakerStore $store, string $hash, array &$counts, array &$standing, ?int &$untilMs): bool
    {
        $total = count($send);
        try {
            $client->ingest->logs->push($send, null, self::OPTIONS);

            return false;
        } catch (\Throwable $e) {
            return $this->failed($e, $total, $store, $hash, $counts, $standing, $untilMs);
        }
    }

    /**
     * Counts a failed push by reason (§3.4) and writes the stop or hold it asks for; returns whether one was written.
     *
     * @param array<string, int>  $counts
     * @param array<string, true> $standing
     */
    private function failed(\Throwable $e, int $total, BreakerStore $store, string $hash, array &$counts, array &$standing, ?int &$untilMs): bool
    {
        if ($e instanceof InvalidPayloadException) {
            $this->add($counts, 'invalid_payload', $total);

            return false;
        }
        if ($e instanceof ConfigurationException) {
            $this->add($counts, 'config', $total);
            $standing['config'] = true;

            return false;
        }
        if ($e instanceof LogVolumeLimitException) {
            $this->add($counts, 'volume_limit', $this->unsent($e, $total));
            $retry = $e->getRetryAfter();
            $now = $this->nowMs();
            if ($retry !== null) {
                $until = $now + $retry * 1000;
            } else {
                $resumes = $e->resumesAt();
                $until = $resumes !== null ? (int) floor((float) $resumes->format('U.u') * 1000) : $now + self::HOUR_MS;
            }
            $untilMs = $this->open($store, $hash, min($until, $now + self::HOUR_MS), 'volume_limit');

            return true;
        }
        if ($e instanceof RateLimitException) {
            $this->add($counts, 'rate_limited', $this->unsent($e, $total));
            $retry = $e->getRetryAfter();
            $untilMs = $this->open($store, $hash, $this->nowMs() + ($retry !== null ? $retry * 1000 : self::HOLD_MS), 'held');

            return true;
        }
        if ($e instanceof ApiConnectionException) {
            $this->add($counts, 'connection', $this->unsent($e, $total));
            $untilMs = $this->open($store, $hash, $this->nowMs() + self::HOLD_MS, 'held');

            return true;
        }
        if ($e instanceof ApiException) {
            $status = $e->getStatusCode();
            $reason = $status === null ? 'internal' : 'rejected_' . $status;
            $this->add($counts, $reason, $this->unsent($e, $total));
            if ($status !== null && ($status === 403 || $status >= 500)) {
                $untilMs = $this->open($store, $hash, $this->nowMs() + self::HOLD_MS, 'held');

                return true;
            }
            if ($status !== null && ($e instanceof UnexpectedResponseException || $status < 400 || $status === 404)) {
                // A misconfigured ingest URL or path prefix, or an answer the SDK cannot read: no hold, reported once an hour.
                $standing[$reason] = true;
            }

            return false;
        }
        $this->add($counts, 'internal', $total);

        return false;
    }

    private function unsent(ApiException $e, int $total): int
    {
        $accepted = $e->partialResult()->sent ?? 0;

        return max(0, $total - $accepted);
    }

    /**
     * @param array<string, int> $counts
     */
    private function add(array &$counts, string $reason, int $lines): void
    {
        if ($lines > 0) {
            $counts[$reason] = ($counts[$reason] ?? 0) + $lines;
        }
    }

    private function open(BreakerStore $store, string $hash, int $untilMs, string $reason): int
    {
        $store->open($hash, $untilMs, $reason);

        return min($untilMs, $this->nowMs() + self::HOUR_MS);
    }

    /**
     * At most one record per flush: the delivery failures, and the standing conditions not reported within the hour.
     *
     * @param array<string, int>  $counts
     * @param array<string, true> $standing
     */
    private function report(array $counts, array $standing, ?int $untilMs, string $key): void
    {
        if ($counts === []) {
            return;
        }
        $store = $this->store();
        $now = $this->nowMs();
        $due = [];
        foreach ($counts as $reason => $lines) {
            if (! isset($standing[$reason])) {
                $due[$reason] = $lines;

                continue;
            }
            $last = $store->lastReported($key, $reason);
            if ($last === null || $now - $last >= self::HOUR_MS) {
                $due[$reason] = $lines;
                $store->markReported($key, $reason, $now);
            }
        }
        if ($due === []) {
            return;
        }
        $logger = $this->selfLogger instanceof \Closure ? ($this->selfLogger)() : $this->selfLogger;
        if (! $logger instanceof LoggerInterface) {
            return;
        }
        $pairs = [];
        foreach ($due as $reason => $lines) {
            $pairs[] = $reason . ' ' . $lines;
        }
        $context = ['wt15_report' => true, 'wt15_lines' => $due];
        if ($untilMs !== null) {
            $context['wt15_until'] = gmdate('Y-m-d\TH:i:s', intdiv($untilMs, 1000)) . sprintf('.%03dZ', $untilMs % 1000);
        }
        $logger->warning('WatchTower15 logs: lines not sent: ' . implode(', ', $pairs), $context);
    }

    private function store(): BreakerStore
    {
        if ($this->resolvedStore !== null) {
            return $this->resolvedStore;
        }
        $store = $this->breakerStore instanceof \Closure ? ($this->breakerStore)() : $this->breakerStore;

        return $this->resolvedStore = $store ?? new FileBreakerStore(null, $this->clock);
    }

    private function nowMs(): int
    {
        return (int) floor((float) $this->clock->now()->format('U.u') * 1000);
    }
}
