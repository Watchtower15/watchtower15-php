<?php

declare(strict_types=1);

namespace WatchTower15\Testing;

use Psr\Http\Message\ResponseInterface;
use WatchTower15\Client;
use WatchTower15\Internal\Json;

/**
 * A {@see Client} that never reaches a network: it answers pushes itself and records every call.
 *
 * Drop it in wherever code is typed against `Client`; then assert on what was sent.
 */
class FakeClient extends Client
{
    private readonly MockTransport $mock;

    /** @var list<string> */
    private array $queuedOperations = [];

    public function __construct()
    {
        $mock = new MockTransport();
        $mock->fallback(static function ($request) {
            $path = $request->getUri()->getPath();
            if ($request->getMethod() !== 'POST' || ! (str_ends_with($path, '/api/metric-sources/ingest') || str_ends_with($path, '/api/log-sources/ingest'))) {
                return null;
            }
            $raw = (string) $request->getBody();
            $text = str_starts_with($raw, "\x1f\x8b") ? (string) gzdecode($raw) : $raw;
            $body = Json::decodeObject($text) ?? [];
            if (str_ends_with($path, '/api/metric-sources/ingest')) {
                $points = is_array($body['points'] ?? null) ? count($body['points']) : 0;

                return MockTransport::metricsAccepted($points, [], self::uuid());
            }
            $lines = is_array($body['lines'] ?? null) ? count($body['lines']) : 0;

            return MockTransport::logsAccepted($lines, self::uuid());
        });
        $this->mock = $mock;
        parent::__construct(
            apiKey: self::placeholderApiKey(),
            metricsToken: 'wt15ms_' . str_repeat('0', 64),
            logsToken: 'wt15ls_' . str_repeat('0', 64),
            httpClient: $mock,
        );
    }

    /** Queues an answer for an operation the fake does not answer itself (`public.me.view`, `ingest.metrics.list`). */
    public function queue(string $operation, ResponseInterface|\Throwable $response): self
    {
        $this->queuedOperations[] = $operation;
        $this->mock->append($response);

        return $this;
    }

    public function transport(): MockTransport
    {
        return $this->mock;
    }

    /** @return list<RecordedCall> */
    public function calls(): array
    {
        $calls = [];
        $queued = $this->queuedOperations;
        foreach ($this->mock->requests() as $request) {
            $operation = self::operationOf($request) ?? array_shift($queued) ?? 'unknown';
            $calls[] = new RecordedCall($operation, $request);
        }

        return $calls;
    }

    /**
     * @param (callable(RecordedCall): bool)|null $where
     */
    public function assertCalled(string $operation, ?callable $where = null, ?int $times = null): void
    {
        $matching = array_filter(
            $this->calls(),
            static fn (RecordedCall $call): bool => $call->operation === $operation && ($where === null || $where($call)),
        );
        $count = count($matching);
        if ($count === 0 || ($times !== null && $count !== $times)) {
            self::fail(sprintf('Expected %s to be called%s; it was called %d time(s).', $operation, $times !== null ? " $times time(s)" : '', $count));
        }
    }

    public function assertNotCalled(?string $operation = null): void
    {
        $found = array_filter($this->calls(), static fn (RecordedCall $c): bool => $operation === null || $c->operation === $operation);
        if ($found !== []) {
            self::fail(sprintf('Expected %s not to be called; it was called %d time(s).', $operation ?? 'no operation', count($found)));
        }
    }

    /**
     * @param callable(array<string, mixed>): bool $point
     */
    public function assertPushed(callable $point): void
    {
        foreach ([...$this->pushedPoints(), ...$this->pushedLines()] as $item) {
            if ($point($item)) {
                return;
            }
        }
        self::fail('No pushed point or line matched.');
    }

    public function assertNothingPushed(): void
    {
        $pushed = count($this->pushedPoints()) + count($this->pushedLines());
        if ($pushed > 0) {
            self::fail(sprintf('Expected nothing to be pushed; %d item(s) were.', $pushed));
        }
    }

    /** @return list<array<string, mixed>> */
    public function pushedPoints(): array
    {
        return $this->pushed('ingest.metrics.push', 'points');
    }

    /** @return list<array<string, mixed>> */
    public function pushedLines(): array
    {
        return $this->pushed('ingest.logs.push', 'lines');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pushed(string $operation, string $key): array
    {
        $items = [];
        foreach ($this->calls() as $call) {
            if ($call->operation !== $operation || ! is_array($call->request->body)) {
                continue;
            }
            $list = $call->request->body[$key] ?? [];
            foreach (is_array($list) ? $list : [] as $item) {
                if (is_array($item)) {
                    /** @var array<string, mixed> $item */
                    $items[] = $item;
                }
            }
        }

        return $items;
    }

    private static function operationOf(RecordedRequest $request): ?string
    {
        $path = $request->path();

        return match (true) {
            $request->method === 'GET' && str_ends_with($path, '/me') => 'public.me.view',
            str_ends_with($path, '/api/metric-sources/ingest') => $request->method === 'GET' ? 'ingest.metrics.list' : 'ingest.metrics.push',
            str_ends_with($path, '/api/log-sources/ingest') => 'ingest.logs.push',
            default => null,
        };
    }

    private static function fail(string $message): never
    {
        if (class_exists(\PHPUnit\Framework\Assert::class)) {
            \PHPUnit\Framework\Assert::fail($message);
        }

        throw new \LogicException($message);
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }

    /** A key of valid shape (the checksum computed), so the fake's client passes the local checks. */
    private static function placeholderApiKey(): string
    {
        $body = str_repeat('0', 64);
        $alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $number = crc32($body);
        $encoded = '';
        while ($number > 0) {
            $encoded = $alphabet[$number % 62] . $encoded;
            $number = intdiv($number, 62);
        }

        return 'wt15ak_' . $body . str_pad($encoded, 6, '0', STR_PAD_LEFT);
    }
}
