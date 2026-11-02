<?php

declare(strict_types=1);

namespace WatchTower15\Tests\Unit\Logs;

use GuzzleHttp\Psr7\Response;
use Monolog\Handler\BufferHandler;
use Monolog\Level;
use PHPUnit\Framework\TestCase;
use WatchTower15\Exception\ApiConnectionException;
use WatchTower15\Logs\Breaker\FileBreakerStore;
use WatchTower15\Testing\MockTransport;
use WatchTower15\Tests\Support\LogHarness;
use WatchTower15\Tests\Support\RecordingBreakerStore;

/** LG5 to LG9, LG11 and LG28: the shipper's bounds, counts, breaker and reports, through the Monolog handler. */
final class ShipperTest extends TestCase
{
    private function hash(LogHarness $t): string
    {
        return (string) $t->client()->logsTokenHash();
    }

    private function down(): \Psr\Http\Message\ResponseInterface
    {
        return MockTransport::envelope(503, [], 'down');
    }

    public function testLg5NoLogsTokenSendsNothingAndReportsOnceAnHour(): void
    {
        $t = new LogHarness(['logsToken' => null]);
        for ($i = 0; $i < 10; $i++) {
            $t->write();
        }

        self::assertSame(0, $t->requests());
        self::assertSame(['WatchTower15 logs: lines not sent: missing_token 1'], $t->reports());
        $context = $t->self->records[0]['context'];
        self::assertTrue($context['wt15_report']);
        self::assertSame(['missing_token' => 1], $context['wt15_lines']);
        self::assertArrayNotHasKey('wt15_until', $context);

        $t->advance(3600);
        $t->write();
        self::assertCount(2, $t->self->records, 'reported again an hour later');
    }

    public function testLg6ARetryHasTheSameBytesAndKeyAndAChunkItsOwn(): void
    {
        $t = new LogHarness();
        $t->answers($this->down(), $t->ok());
        $t->write();

        $requests = $t->h->transport->requests();
        self::assertCount(2, $requests);
        self::assertSame($requests[0]->rawBody, $requests[1]->rawBody);
        self::assertSame($requests[0]->header('Idempotency-Key'), $requests[1]->header('Idempotency-Key'));
        self::assertStringStartsWith('wt15php-', (string) $requests[0]->header('Idempotency-Key'));
        self::assertSame([], $t->self->records, 'a retried success reports nothing');

        $t = new LogHarness();
        $t->answers($t->ok(1000), $t->ok(1000), $t->ok(500));
        $buffer = new BufferHandler($t->handler(), 0);
        for ($i = 0; $i < 2500; $i++) {
            $buffer->handle($t->record('line ' . $i));
        }
        $buffer->close();
        $keys = array_map(static fn ($r) => $r->header('Idempotency-Key'), $t->h->transport->requests());
        self::assertCount(3, $keys);
        self::assertCount(3, array_unique($keys));
        foreach ($t->h->transport->requests() as $request) {
            self::assertSame(3.0, $request->options->timeout);
            self::assertSame(1.0, $request->options->connectTimeout);
        }
    }

    /** @return iterable<string, array{list<\Psr\Http\Message\ResponseInterface|\Throwable>, int, string, ?int}> */
    public static function failures(): iterable
    {
        // answers, requests made, the reason counted, the hold in seconds (null: none)
        yield 'a connect failure twice' => [[new ApiConnectionException('refused', false), new ApiConnectionException('refused', false)], 2, 'connection', 60];
        yield '503 twice' => [[MockTransport::envelope(503, [], 'down'), MockTransport::envelope(503, [], 'down')], 2, 'rejected_503', 60];
        yield 'a throttle 429 with Retry-After 30' => [[MockTransport::envelope(429, [], 'slow', ['Retry-After' => '30'])], 1, 'rate_limited', 30];
        yield 'a throttle 429 with none' => [[MockTransport::envelope(429, [], 'slow')], 1, 'rate_limited', 60];
        yield 'a throttle 429 with Retry-After 1 twice' => [[MockTransport::envelope(429, [], 'slow', ['Retry-After' => '1']), MockTransport::envelope(429, [], 'slow', ['Retry-After' => '1'])], 2, 'rate_limited', 1];
        yield '400' => [[MockTransport::envelope(400, [], 'bad')], 1, 'rejected_400', null];
        yield '403' => [[MockTransport::envelope(403, [], 'no')], 1, 'rejected_403', 60];
        yield '413' => [[MockTransport::envelope(413, [], 'big')], 1, 'rejected_413', null];
        yield '422 key reused' => [[MockTransport::envelope(422, ['code' => 'idempotency_key_reused'], 'reused')], 1, 'rejected_422', null];
    }

    /**
     * @param list<\Psr\Http\Message\ResponseInterface|\Throwable> $answers
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function testLg7EachFailureIsCountedHeldAndRetriedAsSpecified(array $answers, int $requests, string $reason, ?int $holdSeconds): void
    {
        $t = new LogHarness();
        $t->answers(...$answers);
        $t->write();

        self::assertSame($requests, $t->requests());
        self::assertSame(['WatchTower15 logs: lines not sent: ' . $reason . ' 1'], $t->reports());
        $entry = $t->store->read($this->hash($t));
        if ($holdSeconds === null) {
            self::assertNull($entry, 'no hold');

            return;
        }
        self::assertNotNull($entry);
        self::assertSame($t->nowMs() + $holdSeconds * 1000, $entry->untilMs);
        self::assertSame('held', $entry->reason);

        $t->write();
        self::assertSame($requests, $t->requests(), 'the next flush makes no request');
    }

    public function testLg7AThrottleWithARetryAfterOfTwoIsRetriedOnceAndHoldsNothing(): void
    {
        $t = new LogHarness();
        $t->answers(MockTransport::envelope(429, [], 'slow', ['Retry-After' => '2']), $t->ok());
        $t->write();

        $requests = $t->h->transport->requests();
        self::assertCount(2, $requests);
        self::assertSame($requests[0]->rawBody, $requests[1]->rawBody);
        self::assertSame($requests[0]->header('Idempotency-Key'), $requests[1]->header('Idempotency-Key'));
        self::assertSame([], $t->self->records);
        self::assertNull($t->store->read($this->hash($t)));
    }

    public function testLg7APartFailureCountsTheUnsentLines(): void
    {
        $t = new LogHarness();
        $t->answers($t->ok(1000), MockTransport::envelope(400, [], 'bad'));
        $buffer = new BufferHandler($t->handler(), 0);
        for ($i = 0; $i < 2500; $i++) {
            $buffer->handle($t->record('line ' . $i));
        }
        $buffer->close();

        self::assertSame(2, $t->requests());
        self::assertSame(['WatchTower15 logs: lines not sent: rejected_400 1500'], $t->reports());
    }

    /** @return iterable<string, array{\Psr\Http\Message\ResponseInterface, string}> */
    public static function standing(): iterable
    {
        yield 'a 404' => [new Response(404, [], '<html>not here</html>'), 'rejected_404'];
        yield 'a 302' => [new Response(302, ['Location' => 'https://elsewhere.test/'], ''), 'rejected_302'];
        yield 'an HTML 200' => [new Response(200, ['Content-Type' => 'text/html'], '<html>edge</html>'), 'rejected_200'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('standing')]
    public function testLg7AMisconfiguredUrlPushesEveryTimeHoldsNothingAndReportsOnceAnHour(\Psr\Http\Message\ResponseInterface $answer, string $reason): void
    {
        $t = new LogHarness();
        for ($i = 0; $i < 3; $i++) {
            $t->answers($answer);
            $t->write();
        }

        self::assertSame(3, $t->requests(), 'each flush pushes');
        self::assertSame(['WatchTower15 logs: lines not sent: ' . $reason . ' 1'], $t->reports(), 'one report for three');
        self::assertNull($t->store->read($this->hash($t)));
    }

    public function testLg8TheVolumeStopIsReCheckedHourly(): void
    {
        $t = new LogHarness();
        $hash = $this->hash($t);
        $day = $t->nowMs() + 86_400_000;
        $stop = static fn (array $headers = [], array $data = []) => MockTransport::envelope(429, ['code' => 'log_volume_limit_reached'] + $data, 'Stop', $headers);

        // The backend's Retry-After is at most an hour; the day in resumes_at is not believed.
        $t->answers($stop(['Retry-After' => '3600'], ['resumes_at' => $day]));
        $t->write();
        self::assertSame($t->nowMs() + 3_600_000, $t->store->read($hash)?->untilMs);
        self::assertSame('volume_limit', $t->store->read($hash)?->reason);
        for ($i = 0; $i < 3; $i++) {
            $t->write();
        }
        self::assertSame(1, $t->requests(), 'the next three flushes make no request');
        self::assertCount(2, $t->self->records, 'the stop, and one standing report among the three');

        $t->advance(3600);
        $t->answers($stop([], ['resumes_at' => $t->nowMs() + 86_400_000]));
        $t->write();
        self::assertSame(2, $t->requests(), 'an hour on, one request');
        self::assertSame($t->nowMs() + 3_600_000, $t->store->read($hash)?->untilMs, 'a stop of an hour again, not the day');

        $t->advance(3600);
        $t->answers($stop(['Retry-After' => '600']));
        $t->write();
        self::assertSame($t->nowMs() + 600_000, $t->store->read($hash)?->untilMs);

        $t->advance(600);
        $t->answers($stop());
        $t->write();
        self::assertSame($t->nowMs() + 3_600_000, $t->store->read($hash)?->untilMs, 'neither: an hour');

        $t->advance(3600);
        $probe = null;
        $t->h->transport->fallback(function () use ($t, $hash, &$probe) {
            $probe = $t->store->read($hash);

            return $t->ok();
        });
        $t->write();
        self::assertSame($t->nowMs() + 10_000, $probe?->untilMs, 'the ended stop is rewritten as a probe of 10 s before the push');
        self::assertSame('volume_limit', $probe?->reason);
        self::assertNull($t->store->read($hash), 'and removed on the 200');
    }

    /** @return iterable<string, array{list<\Psr\Http\Message\ResponseInterface|\Throwable>}> */
    public static function holds(): iterable
    {
        yield 'a connect failure' => [[new ApiConnectionException('refused', false), new ApiConnectionException('refused', false)]];
        yield '503' => [[MockTransport::envelope(503, [], 'down'), MockTransport::envelope(503, [], 'down')]];
        yield '403' => [[MockTransport::envelope(403, [], 'no')]];
        yield 'the throttle' => [[MockTransport::envelope(429, [], 'slow', ['Retry-After' => '30'])]];
    }

    /**
     * @param list<\Psr\Http\Message\ResponseInterface|\Throwable> $answers
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('holds')]
    public function testLg8AHoldDropsTheNextFlushAndEndsWithAProbe(array $answers): void
    {
        $t = new LogHarness();
        $hash = $this->hash($t);
        $t->answers(...$answers);
        $t->write();
        $made = $t->requests();
        $t->write();
        self::assertSame($made, $t->requests());
        self::assertSame('held', $t->store->read($hash)?->reason);

        $t->advance(61);
        $seen = null;
        $t->h->transport->fallback(function () use ($t, $hash, &$seen) {
            $seen = $t->store->read($hash);

            return $t->ok();
        });
        $t->write();
        self::assertSame($t->nowMs() + 10_000, $seen?->untilMs);
        self::assertNull($t->store->read($hash));
    }

    public function testLg8AFailingProbeIsReplacedByAHoldFromTheFailure(): void
    {
        $t = new LogHarness();
        $hash = $this->hash($t);
        $t->answers(MockTransport::envelope(403, [], 'no'));
        $t->write();
        $t->advance(61);
        $t->answers($this->down(), $this->down());
        $t->write();

        self::assertSame($t->nowMs() + 60_000, $t->store->read($hash)?->untilMs);
        self::assertStringContainsString('rejected_503 1', $t->reports()[1]);
    }

    public function testLg8ABlankOnlyFlushWritesNoProbeAndALaterFailureClearsIt(): void
    {
        $t = new LogHarness();
        $hash = $this->hash($t);
        $t->answers(MockTransport::envelope(403, [], 'no'));
        $t->write();
        $ended = $t->store->read($hash);
        $t->advance(61);

        $t->write($t->record(''));
        self::assertSame($ended?->untilMs, $t->store->read($hash)?->untilMs, 'the ended entry is unchanged');
        self::assertSame(1, $t->requests());
        self::assertStringContainsString('invalid_payload 1', $t->reports()[1]);

        $t->answers(MockTransport::envelope(400, [], 'bad'));
        $t->write();
        self::assertSame(2, $t->requests());
        self::assertNull($t->store->read($hash), 'the 400 wrote no stop or hold: the probe is cleared');
        self::assertStringContainsString('rejected_400 1', $t->reports()[2]);
    }

    public function testLg8AHoldNeverShortensALaterStopAndNoEntryEndsMoreThanAnHourAhead(): void
    {
        $t = new LogHarness();
        $hash = $this->hash($t);
        $t->store->open($hash, $t->nowMs() + 1_800_000, 'volume_limit');
        $t->store->open($hash, $t->nowMs() + 60_000, 'held');
        self::assertSame($t->nowMs() + 1_800_000, $t->store->read($hash)?->untilMs);
        self::assertSame('volume_limit', $t->store->read($hash)?->reason);

        $other = new LogHarness();
        $other->store->open($this->hash($other), $other->nowMs() + 172_800_000, 'volume_limit');
        self::assertSame($other->nowMs() + 3_600_000, $other->store->read($this->hash($other))?->untilMs);
    }

    public function testLg9TheFileStoreSharesEntriesAndNeverHoldsTheToken(): void
    {
        $directory = sys_get_temp_dir() . '/wt15-lg9-' . bin2hex(random_bytes(4));
        try {
            $t = new LogHarness([], new FileBreakerStore($directory, null));
            $store = new FileBreakerStore($directory, $t->clock);
            $hash = $this->hash($t);
            $t->answers(MockTransport::envelope(403, [], 'no'));
            $t->write();

            $entry = $store->read($hash);
            self::assertNotNull($entry);
            self::assertSame('held', $entry->reason);
            $file = $directory . '/wt15-logs-' . $hash . '.breaker';
            self::assertFileExists($file);
            self::assertSame('0644', substr(sprintf('%o', fileperms($file)), -4));
            self::assertMatchesRegularExpression('/\A\d+ held\n\z/', (string) file_get_contents($file));
            $t->write();   // a standing condition (the hold) is reported once, and its time kept beside the entry
            self::assertFileExists($directory . '/wt15-logs-' . $hash . '.reported');
            foreach (glob($directory . '/*') ?: [] as $path) {
                self::assertStringNotContainsString(\WatchTower15\Tests\Support\Keys::logs(), $path . (string) file_get_contents($path));
            }

            // An ended entry is read with its past end; clear() with another end keeps it, with its own removes it.
            $fresh = new LogHarness();
            $ended = new \WatchTower15\Logs\Breaker\Breaker($entry->untilMs, 'held');
            $late = new FileBreakerStore($directory, new \WatchTower15\Tests\Support\FakeClock($t->clock->now->modify('+2 minutes')));
            self::assertSame($ended->untilMs, $late->read($hash)?->untilMs);
            $late->clear($hash, $ended->untilMs + 1);
            self::assertNotNull($late->read($hash));
            $late->clear($hash, $ended->untilMs);
            self::assertNull($late->read($hash));
            self::assertNotNull($fresh);
        } finally {
            foreach (glob($directory . '/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($directory);
        }
    }

    public function testLg9AProbeIsVisibleToAnotherStoreFromInsideThePush(): void
    {
        $directory = sys_get_temp_dir() . '/wt15-lg9b-' . bin2hex(random_bytes(4));
        try {
            $t = new LogHarness([], new FileBreakerStore($directory, null));
            $t->store = new FileBreakerStore($directory, $t->clock);
            $other = new FileBreakerStore($directory, $t->clock);
            $hash = $this->hash($t);
            $t->answers(MockTransport::envelope(403, [], 'no'));
            $t->write();
            $t->advance(61);
            $seen = null;
            $t->h->transport->fallback(function () use ($other, $hash, &$seen) {
                $seen = $other->read($hash);

                return $this->okResponse();
            });
            $t->write();

            self::assertSame($t->nowMs() + 10_000, $seen?->untilMs);
        } finally {
            foreach (glob($directory . '/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($directory);
        }
    }

    public function testLg9AnEntryEndingTwoDaysAheadIsCappedAndWrittenBack(): void
    {
        $directory = sys_get_temp_dir() . '/wt15-lg9c-' . bin2hex(random_bytes(4));
        mkdir($directory);
        try {
            $t = new LogHarness();
            $store = new FileBreakerStore($directory, $t->clock);
            $file = $directory . '/wt15-logs-abc.breaker';
            file_put_contents($file, ($t->nowMs() + 172_800_000) . " volume_limit\n");

            self::assertSame($t->nowMs() + 3_600_000, $store->read('abc')?->untilMs);
            self::assertSame(($t->nowMs() + 3_600_000) . " volume_limit\n", file_get_contents($file));

            // A directory it cannot write counts as closed, and nothing is thrown.
            $broken = new FileBreakerStore('/proc/wt15-nope', $t->clock);
            $broken->open('abc', $t->nowMs() + 1000, 'held');
            self::assertNull($broken->read('abc'));
        } finally {
            foreach (glob($directory . '/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($directory);
        }
    }

    public function testLg28LogsTokenHashAndAShipperWithNoTokenTouchesOnlyTheNoneKey(): void
    {
        $with = new LogHarness();
        $without = new LogHarness(['logsToken' => null]);
        $fake = new \WatchTower15\Testing\FakeClient();

        self::assertSame(hash('sha256', \WatchTower15\Tests\Support\Keys::logs()), $with->client()->logsTokenHash());
        self::assertNull($without->client()->logsTokenHash());
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', (string) $fake->logsTokenHash());

        $recording = new RecordingBreakerStore(new \WatchTower15\Logs\Breaker\InMemoryBreakerStore());
        $t = new LogHarness(['logsToken' => null], $recording);
        $t->write();
        self::assertSame(0, $t->requests());
        self::assertSame(0, $recording->count('read'));
        self::assertSame(0, $recording->count('open'));
        self::assertSame(['lastReported none', 'markReported none'], $recording->calls);

        $recording = new RecordingBreakerStore(new \WatchTower15\Logs\Breaker\InMemoryBreakerStore());
        $t = new LogHarness([], $recording);
        $t->answers(MockTransport::envelope(429, ['code' => 'log_volume_limit_reached'], 'Stop', ['Retry-After' => '3600']));
        $t->write();
        self::assertContains('open ' . hash('sha256', \WatchTower15\Tests\Support\Keys::logs()), $recording->calls);
    }

    private function okResponse(): \Psr\Http\Message\ResponseInterface
    {
        return MockTransport::logsAccepted(1);
    }
}
