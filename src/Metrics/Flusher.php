<?php

declare(strict_types=1);

namespace WatchTower15\Metrics;

use Psr\Log\LoggerInterface;
use WatchTower15\Client;
use WatchTower15\ConfigurationException;
use WatchTower15\Exception\ApiConnectionException;
use WatchTower15\Exception\ApiException;
use WatchTower15\Exception\AuthenticationException;
use WatchTower15\Exception\RateLimitException;
use WatchTower15\Exception\ServerException;
use WatchTower15\Exception\ServiceUnavailableException;
use WatchTower15\Exception\UnexpectedResponseException;
use WatchTower15\Http\RequestOptions;
use WatchTower15\Ingest\InvalidPayloadException;
use WatchTower15\Internal\Clock;
use WatchTower15\Internal\ErrorCounts;
use WatchTower15\Internal\StoreFailure;

/**
 * Sends the closed buckets: one request per chunk of each, oldest first, with a frozen `Idempotency-Key`, no retry, inside the
 * flush's own lock. Built only by the {@see Aggregator}, which exposes it through `flusher()`.
 */
final class Flusher
{
    /** No request starts once this many seconds have passed since the lock was taken. */
    public const DEADLINE_SECONDS = 90;

    private const LOCK_SECONDS = 120;

    private const CHUNK_POINTS = 1000;

    /**
     * @param array<string, 'count'|'level'>|null $allowList  Valid entries only.
     * @param list<string>                        $configProblems
     *
     * @internal
     */
    public function __construct(
        private readonly Client $client,
        private readonly BucketStore $store,
        private readonly Clock $clock,
        private readonly ErrorCounts $errors,
        private readonly ?array $allowList,
        private readonly ?string $heartbeat,
        private readonly array $configProblems,
        private readonly bool $enabled,
        private readonly int $maxBuckets,
        private readonly int $maxRequests,
        private readonly float $timeout,
        private readonly float $connectTimeout,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function flush(bool $final = false): FlushReport
    {
        if (! $this->enabled) {
            return new FlushReport('disabled', configProblems: $this->configProblems);
        }
        $run = new FlushRun();
        $token = null;
        $locked = false;
        try {
            $token = $this->call(fn (): ?string => $this->store->acquireLock('flush', self::LOCK_SECONDS));
            if ($token === null) {
                $locked = true;
            } else {
                $this->work($run, $final);
            }
        } catch (StoreFailure) {
            $run->stop = 'store';
        } catch (\Throwable) {
            $run->stop = 'internal';
            $this->errors->count('internal');
        }
        if ($locked) {
            return new FlushReport('locked', errors: $this->errors->takeProcess(), configProblems: $this->configProblems);
        }

        // Step 5: read the error counters, subtract what was read (no store call after a store failure).
        $read = [];
        if ($run->stop !== 'store') {
            try {
                $read = $this->call(fn (): array => $this->store->errors());
                if ($read !== []) {
                    $this->call(function () use ($read): void {
                        $this->store->subtractErrors($read);
                    });
                }
            } catch (StoreFailure) {
                $run->stop = 'store';
            } catch (\Throwable) {
                $run->stop ??= 'internal';
            }
        }
        foreach ($this->errors->takeProcess() as $reason => $n) {
            $read[$reason] = ($read[$reason] ?? 0) + $n;
        }
        $report = $this->report($run, $read);
        $this->warn($report);

        if ($token !== null) {
            try {
                $this->store->releaseLock('flush', $token);
            } catch (\Throwable) {
                // A release that throws is ignored.
            }
        }

        return $report;
    }

    /**
     * The report of a run that could not even claim its minute (the `flushIfDue()` claim threw): no lock was taken.
     *
     * @internal
     */
    public function storeFailureReport(): FlushReport
    {
        $report = new FlushReport('flushed', stopReason: 'store', errors: $this->errors->takeProcess(), configProblems: $this->configProblems);
        $this->warn($report);

        return $report;
    }

    /**
     * @throws StoreFailure
     */
    private function work(FlushRun $run, bool $final): void
    {
        $startedAt = $this->clock->now()->getTimestamp();
        $current = intdiv($startedAt, 60);
        $ttl = ($this->maxBuckets + 60) * 60;

        if ($this->heartbeat !== null) {
            $this->call(function () use ($current, $ttl): void {
                $this->store->increment($current, (string) $this->heartbeat, 1, $ttl);
            });
            $run->heartbeatAdded = true;
        }
        $last = $this->call(fn (): ?int => $this->store->lastFlushed());
        $newest = ($final && $this->store->scope() === StoreScope::Process) ? $current : intdiv($startedAt - 65, 60);

        // Below the window: buckets older than maxBuckets are dropped, never sent.
        $lowTo = $current - $this->maxBuckets - 1;
        $lowFrom = $last === null ? $current - $this->maxBuckets - 60 : max($last + 1, $current - $this->maxBuckets - 60);
        if ($lowFrom <= $lowTo) {
            for ($b = $lowFrom; $b <= $lowTo; $b++) {
                $data = $this->call(fn (): array => $this->store->read($b));
                if ($data['count'] !== [] || $data['level'] !== []) {
                    $this->call(function () use ($b): void {
                        $this->store->forget($b);
                    });
                    $this->errors->count('dropped_age');
                    $run->bucketsDropped++;
                }
            }
            $this->call(function () use ($lowTo): void {
                $this->store->markFlushed($lowTo);
            });
            $last = $lowTo;
        }
        $from = $last === null ? $current - $this->maxBuckets : max($last + 1, $current - $this->maxBuckets);

        $instance = null;
        for ($b = $from; $b <= $newest; $b++) {
            $data = $this->call(fn (): array => $this->store->read($b));
            $points = $this->points($b, $data);
            if ($points === []) {
                $this->call(function () use ($b): void {
                    $this->store->forget($b);
                    $this->store->markFlushed($b);
                });
                $run->bucketsEmpty++;

                continue;
            }
            if ($run->requests >= $this->maxRequests || $this->elapsed($startedAt) >= self::DEADLINE_SECONDS) {
                $run->stop = 'max_requests';

                return;
            }
            $instance ??= $this->call(fn (): string => $this->store->instanceId());

            $sent = false;
            foreach (array_chunk($points, self::CHUNK_POINTS) as $index => $chunk) {
                if ($this->elapsed($startedAt) >= self::DEADLINE_SECONDS) {
                    $run->stop = 'max_requests';

                    return;
                }
                $outcome = $this->send($run, $b, $index, $chunk, $instance);
                if ($outcome === 'done') {
                    $sent = true;
                } elseif ($outcome !== 'dropped') {
                    $run->stop = $outcome;

                    return;
                }
            }
            $this->call(function () use ($b): void {
                $this->store->forget($b);
                $this->store->markFlushed($b);
            });
            if ($sent) {
                $run->bucketsSent++;
            }
        }
    }

    /** @phpstan-impure */
    private function elapsed(int $startedAt): int
    {
        return $this->clock->now()->getTimestamp() - $startedAt;
    }

    /**
     * @param list<array{metric: string, value: int|float, recorded_at: int, metric_type: string}> $chunk
     *
     * @return string `done`, `dropped`, or a stop reason
     */
    private function send(FlushRun $run, int $bucket, int $index, array $chunk, string $instance): string
    {
        try {
            $json = json_encode($chunk, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            $hash = $this->client->metricsTokenHash();
            $key = 'wt15agg-' . hash('sha256', $hash . '|' . hash('sha256', $instance) . '|60|' . $bucket . '|' . $index . '|' . hash('sha256', $json));
            $run->requests++;
            $result = $this->client->ingest->metrics->push(
                $chunk,
                $key,
                new RequestOptions(timeout: $this->timeout, connectTimeout: $this->connectTimeout, maxRetries: 0),
            );
            $run->points += $result->count;
            foreach ($result->rejected as $reason => $n) {
                if ($n > 0) {
                    $run->rejected[$reason] = ($run->rejected[$reason] ?? 0) + $n;
                }
            }

            return 'done';
        } catch (\Throwable $e) {
            return $this->outcome($run, $e);
        }
    }

    /** D13's status table, first match winning. */
    private function outcome(FlushRun $run, \Throwable $e): string
    {
        if ($e instanceof \JsonException || $e instanceof InvalidPayloadException) {
            return $this->dropChunk($run);
        }
        if ($e instanceof AuthenticationException) {
            $this->errors->count('auth');

            return 'auth';
        }
        if ($e instanceof ConfigurationException) {
            $this->errors->count('config');

            return 'config';
        }
        if ($e instanceof UnexpectedResponseException) {
            $status = $e->getStatusCode();
            if ($status !== null && $status >= 300 && $status <= 399) {
                $this->errors->count('redirect');

                return 'redirect';
            }
            $this->errors->count('transport');

            return 'transport';
        }
        if ($e instanceof ApiConnectionException || $e instanceof RateLimitException || $e instanceof ServerException || $e instanceof ServiceUnavailableException) {
            $this->errors->count('transport');

            return 'transport';
        }
        if ($e instanceof ApiException) {
            $status = $e->getStatusCode();
            if ($status === 408) {
                $this->errors->count('transport');

                return 'transport';
            }
            if ($status !== null && $status >= 400 && $status <= 499) {
                return $this->dropChunk($run);
            }
        }
        $this->errors->count('internal');

        return 'internal';
    }

    private function dropChunk(FlushRun $run): string
    {
        $this->errors->count('dropped_rejected');
        $run->chunksDropped++;

        return 'dropped';
    }

    /**
     * @param array{count: array<string, int>, level: array<string, float>} $data
     *
     * @return list<array{metric: string, value: int|float, recorded_at: int, metric_type: string}>
     */
    private function points(int $bucket, array $data): array
    {
        $at = $bucket * 60 * 1000;
        $points = [];
        foreach ($data['count'] as $key => $sum) {
            if ($sum !== 0) {
                $points[$key] = ['metric' => (string) $key, 'value' => $sum, 'recorded_at' => $at, 'metric_type' => $this->allowList[$key] ?? 'count'];
            }
        }
        foreach ($data['level'] as $key => $value) {
            if (isset($data['count'][$key])) {
                $this->errors->count('type_conflict');

                continue;
            }
            $points[$key] = ['metric' => (string) $key, 'value' => $value, 'recorded_at' => $at, 'metric_type' => $this->allowList[$key] ?? 'level'];
        }
        ksort($points, SORT_STRING);

        return array_values($points);
    }

    /**
     * A store call: a throw is counted in the process and unwinds the run.
     *
     * @template T
     *
     * @param callable(): T $call
     *
     * @return T
     *
     * @throws StoreFailure
     */
    private function call(callable $call): mixed
    {
        try {
            return $call();
        } catch (\Throwable) {
            $this->errors->storeFailure();

            throw new StoreFailure();
        }
    }

    /**
     * @param array<string, int> $errors
     */
    private function report(FlushRun $run, array $errors): FlushReport
    {
        return new FlushReport(
            'flushed',
            $run->bucketsSent,
            $run->bucketsEmpty,
            $run->bucketsDropped,
            $run->chunksDropped,
            $run->requests,
            $run->points,
            $run->rejected,
            $run->stop,
            $errors,
            $this->configProblems,
            $run->heartbeatAdded,
        );
    }

    /** One warning when anything is not zero: counts and reasons only, never a value, a token or a body. */
    private function warn(FlushReport $report): void
    {
        if ($report->errors === [] && $report->stopReason === null && $report->rejected === [] && $report->configProblems === []) {
            return;
        }
        try {
            $this->logger->warning('WatchTower15 metrics flush needs attention.', [
                'stop_reason' => $report->stopReason,
                'errors' => $report->errors,
                'rejected' => $report->rejected,
                'config_problems' => $report->configProblems,
                'requests' => $report->requests,
                'points' => $report->points,
            ]);
        } catch (\Throwable) {
            // A logger that throws is ignored.
        }
    }
}
