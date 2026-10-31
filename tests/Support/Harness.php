<?php

declare(strict_types=1);

namespace WatchTower15\Tests\Support;

use WatchTower15\Client;
use WatchTower15\Testing\MockTransport;

/** A client over a MockTransport, with a fake clock and sleeper. */
final class Harness
{
    public MockTransport $transport;

    public FakeSleeper $sleeper;

    public FakeClock $clock;

    public RecordingLogger $logger;

    public Client $client;

    /** @param array<string, mixed> $arguments */
    public function __construct(array $arguments = [])
    {
        $this->transport = new MockTransport();
        $this->sleeper = new FakeSleeper();
        $this->clock = new FakeClock();
        $this->logger = new RecordingLogger();
        $arguments += [
            'apiKey' => Keys::apiKey(),
            'metricsToken' => Keys::metrics(),
            'logsToken' => Keys::logs(),
            'httpClient' => $this->transport,
            'logger' => $this->logger,
        ];
        /** @phpstan-ignore argument.type */
        $this->client = new Client(...$arguments);
        $this->client->useTimeSeams($this->clock, $this->sleeper);
    }

    public static function meBody(): \GuzzleHttp\Psr7\Response
    {
        return MockTransport::json(['data' => [
            'key' => ['uuid' => 'k-1', 'name' => 'CI key', 'last_four' => 'abcd', 'scopes' => ['tags.view'], 'created_at' => 1_798_761_600_000, 'expires_at' => 1_798_848_000_000, 'extra' => 1],
            'user' => ['uuid' => 'u-1', 'name' => 'Ada', 'email' => 'ada@example.com'],
            'organisation' => ['uuid' => 'o-1', 'name' => 'Demo'],
        ]]);
    }
}
