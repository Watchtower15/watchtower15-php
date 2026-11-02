<?php

declare(strict_types=1);

namespace WatchTower15\Tests\Support;

use WatchTower15\Client;
use WatchTower15\Metrics\Aggregator;
use WatchTower15\Metrics\BucketStore;
use WatchTower15\Store\InMemoryStore;
use WatchTower15\Testing\MockTransport;

/** An aggregator over a MockTransport and one fake clock shared by the client, the store and the aggregator. */
final class PipeHarness
{
    public const T0 = 1_798_761_600 + 600;   // a minute boundary, 10 minutes into the day

    public MockTransport $transport;

    public FakeClock $clock;

    public FakeSleeper $sleeper;

    public RecordingLogger $logger;

    public Client $client;

    public BucketStore $store;

    public Aggregator $aggregator;

    /**
     * @param array<string, mixed> $aggregatorArguments
     * @param array<string, mixed> $clientArguments
     */
    public function __construct(array $aggregatorArguments = [], array $clientArguments = [], ?BucketStore $store = null, ?int $at = null)
    {
        $this->clock = new FakeClock((new \DateTimeImmutable('@' . ($at ?? self::T0)))->setTimezone(new \DateTimeZone('UTC')));
        $this->transport = new MockTransport();
        $this->sleeper = new FakeSleeper();
        $this->logger = new RecordingLogger();
        $clientArguments += ['metricsToken' => Keys::metrics(), 'httpClient' => $this->transport, 'logger' => $this->logger];
        /** @phpstan-ignore argument.type */
        $this->client = new Client(...$clientArguments);
        $this->client->useTimeSeams($this->clock, $this->sleeper);
        $this->store = $store ?? new InMemoryStore($this->clock);
        $aggregatorArguments += ['client' => $this->client, 'store' => $this->store, 'clock' => $this->clock];
        /** @phpstan-ignore argument.type */
        $this->aggregator = new Aggregator(...$aggregatorArguments);
    }

    public function advance(int $seconds): void
    {
        $this->clock->now = $this->clock->now->modify(sprintf('%+d seconds', $seconds));
    }

    public function bucket(): int
    {
        return intdiv($this->clock->now->getTimestamp(), 60);
    }

    /** @return list<array<string, mixed>> The decoded points of every request, in order. */
    public function sentPoints(): array
    {
        $all = [];
        foreach ($this->transport->requests() as $request) {
            if (is_array($request->body)) {
                foreach ($request->body['points'] ?? [] as $point) {
                    $all[] = $point;
                }
            }
        }

        return $all;
    }

    /** Queue n accepting answers (one point each). */
    public function accept(int $n = 1): void
    {
        for ($i = 0; $i < $n; $i++) {
            $this->transport->append(MockTransport::metricsAccepted(1));
        }
    }
}
