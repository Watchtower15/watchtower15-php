<?php

declare(strict_types=1);

namespace WatchTower15\Metrics;

use Psr\Log\LoggerInterface;
use WatchTower15\Client;
use WatchTower15\Internal\Clock;
use WatchTower15\Internal\ErrorCounts;
use WatchTower15\Internal\SystemClock;
use WatchTower15\Lifecycle\ShutdownStrategy;

/**
 * Counts and levels on the request path, in microseconds: `increment()` and `gauge()` write into a minute bucket in a store every
 * process shares, never throw and never send. A flush, once a minute, sends one request per closed bucket.
 *
 * Construction makes no store call, no request, no log write and no token resolution.
 */
class Aggregator
{
    /** A bucket is closed once now is at least its end plus this many seconds. */
    public const CLOSE_GRACE_SECONDS = 5;

    private const KEY_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/';

    private readonly Clock $clock;

    private readonly bool $enabled;

    private readonly int $ttl;

    private readonly ErrorCounts $errors;

    private readonly Flusher $flusher;

    /** @var array<string, 'count'|'level'>|null */
    private readonly ?array $allowList;

    private readonly ?string $heartbeat;

    /**
     * @param array<string, string>|null $allowList Metric keys and their types (`count` or `level`); null = off.
     * @param ?string                    $heartbeat A count key incremented in every flush, so a stopped pipe shows as silence.
     */
    public function __construct(
        private readonly Client $client,
        private readonly BucketStore $store,
        ?array $allowList = null,
        ?string $heartbeat = null,
        bool $enabled = true,
        int $maxBuckets = 60,
        int $maxRequests = 10,
        float $timeout = 3.0,
        float $connectTimeout = 1.0,
        ?LoggerInterface $logger = null,
        ?Clock $clock = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
        $this->enabled = $enabled;
        $maxBuckets = max(2, min(1440, $maxBuckets));
        $maxRequests = max(1, min(60, $maxRequests));
        $timeout = max(0.5, min(10.0, $timeout));
        $connectTimeout = max(0.1, min($timeout, $connectTimeout));
        $this->ttl = ($maxBuckets + 60) * 60;
        $this->errors = new ErrorCounts($store);

        $problems = [];
        $clean = null;
        if ($allowList !== null) {
            $clean = [];
            foreach ($allowList as $key => $type) {
                $key = (string) $key;
                if (! $this->validKey($key)) {
                    $problems[] = sprintf('allow-list entry `%s` has an invalid key and is disabled', $key);

                    continue;
                }
                if ($type !== 'count' && $type !== 'level') {
                    $problems[] = sprintf('allow-list entry `%s` has a type that is not count or level and is disabled', $key);

                    continue;
                }
                $clean[$key] = $type;
            }
        }
        $this->allowList = $clean;
        if ($heartbeat !== null && ! $this->validKey($heartbeat)) {
            $problems[] = sprintf('the heartbeat key `%s` is invalid and the heartbeat is disabled', $heartbeat);
            $heartbeat = null;
        }
        $this->heartbeat = $heartbeat;

        $this->flusher = new Flusher(
            $client,
            $store,
            $this->clock,
            $this->errors,
            $this->allowList,
            $this->heartbeat,
            $problems,
            $enabled,
            $maxBuckets,
            $maxRequests,
            $timeout,
            $connectTimeout,
            $logger ?? $client->logger(),
        );
    }

    public function increment(string $key, int $by = 1): void
    {
        if (! $this->enabled) {
            return;
        }
        try {
            if (! $this->validKey($key)) {
                $this->errors->count('invalid_key');

                return;
            }
            if ($by < 0) {
                $this->errors->count('negative_count');

                return;
            }
            if ($by === 0) {
                return;
            }
            if (! $this->allowed($key, 'count')) {
                $this->errors->count('not_allowed');

                return;
            }
            $bucket = intdiv($this->clock->now()->getTimestamp(), 60);
            $this->store->increment($bucket, $key, $by, $this->ttl);
            $this->checkLate($bucket);
        } catch (\Throwable) {
            $this->errors->storeFailure();
        }
    }

    public function gauge(string $key, int|float $value): void
    {
        if (! $this->enabled) {
            return;
        }
        try {
            if (! $this->validKey($key)) {
                $this->errors->count('invalid_key');

                return;
            }
            if (is_nan((float) $value) || is_infinite((float) $value)) {
                $this->errors->count('invalid_value');

                return;
            }
            if (! $this->allowed($key, 'level')) {
                $this->errors->count('not_allowed');

                return;
            }
            $bucket = intdiv($this->clock->now()->getTimestamp(), 60);
            $this->store->setLevel($bucket, $key, $value, $this->ttl);
            $this->checkLate($bucket);
        } catch (\Throwable) {
            $this->errors->storeFailure();
        }
    }

    public function flush(bool $final = false): FlushReport
    {
        try {
            return $this->flusher->flush($final);
        } catch (\Throwable) {
            return new FlushReport('flushed', stopReason: 'internal');
        }
    }

    /**
     * Flushes at most once a minute between all the processes sharing a store: the first to claim the minute flushes, the rest get null.
     */
    public function flushIfDue(): ?FlushReport
    {
        if (! $this->enabled) {
            return $this->flusher->flush();
        }
        try {
            $current = intdiv($this->clock->now()->getTimestamp(), 60);
            try {
                $claimed = $this->store->claimOnce('tick:' . $current, 120);
            } catch (\Throwable) {
                $this->errors->storeFailure();

                return $this->flusher->storeFailureReport();
            }

            return $claimed ? $this->flush() : null;
        } catch (\Throwable) {
            return new FlushReport('flushed', stopReason: 'internal');
        }
    }

    /**
     * Flushes when the runtime says: a final flush for a one-process store, `flushIfDue()` for any other.
     */
    public function registerShutdown(ShutdownStrategy $strategy): void
    {
        try {
            if (! $this->enabled) {
                return;
            }
            $process = $this->store->scope() === StoreScope::Process;
            $strategy->register(function () use ($process): void {
                try {
                    $process ? $this->flush(true) : $this->flushIfDue();
                } catch (\Throwable) {
                    // Nothing reaches the application.
                }
            });
        } catch (\Throwable) {
            // Nothing reaches the application.
        }
    }

    public function flusher(): Flusher
    {
        return $this->flusher;
    }

    public function store(): BucketStore
    {
        return $this->store;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    protected function client(): Client
    {
        return $this->client;
    }

    private function validKey(string $key): bool
    {
        return strlen($key) <= 100 && preg_match(self::KEY_PATTERN, $key) === 1;
    }

    private function allowed(string $key, string $type): bool
    {
        if ($this->allowList === null) {
            return true;
        }
        if ($type === 'count' && $key === $this->heartbeat) {
            return true;
        }

        return ($this->allowList[$key] ?? null) === $type;
    }

    /** A write that finished after its bucket's grace is still stored, and counted: the count is lost or stored twice. */
    private function checkLate(int $bucket): void
    {
        if ($this->clock->now()->getTimestamp() >= ($bucket + 1) * 60 + self::CLOSE_GRACE_SECONDS) {
            $this->errors->count('late_write');
        }
    }
}
