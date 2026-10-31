<?php

declare(strict_types=1);

namespace WatchTower15\Tests\Unit\Http;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WatchTower15\ConfigurationException;
use WatchTower15\Exception\ApiConnectionException;
use WatchTower15\Exception\ApiException;
use WatchTower15\Exception\DailyLimitException;
use WatchTower15\Exception\RateLimitException;
use WatchTower15\Exception\UnexpectedResponseException;
use WatchTower15\Http\RequestOptions;
use WatchTower15\Http\RetryPolicy;
use WatchTower15\Internal\ErrorMapper;
use WatchTower15\Internal\Secrets;
use WatchTower15\Internal\SecretsHandle;
use WatchTower15\Internal\Sender;
use WatchTower15\Testing\MockTransport;
use WatchTower15\Tests\Support\FakeClock;
use WatchTower15\Tests\Support\FakeSleeper;
use WatchTower15\Tests\Support\Harness;
use WatchTower15\Tests\Support\Keys;
use Psr\Log\NullLogger;

final class RetryTest extends TestCase
{
    /** @return iterable<string, array{\Throwable|\Psr\Http\Message\ResponseInterface, ?float}> */
    public static function retryable(): iterable
    {
        yield 'connect failure' => [new ApiConnectionException('down', false, false, 'connect'), null];
        yield 'timeout' => [new ApiConnectionException('slow', true, false, 'timeout'), null];
        yield '408' => [MockTransport::problem(408, 'timeout'), null];
        yield '429 with Retry-After' => [MockTransport::problem(429, 'rate_limited', 'slow', [], ['Retry-After' => '2']), 2.0];
        yield '502' => [MockTransport::problem(502, 'bad_gateway'), null];
        yield '503' => [MockTransport::problem(503, 'unavailable'), null];
        yield '504' => [MockTransport::problem(504, 'timeout'), null];
        yield 'html 502 from an edge' => [new Response(502, ['Content-Type' => 'text/html', 'CF-Ray' => 'abc'], '<html>'), null];
    }

    #[DataProvider('retryable')]
    public function testR1AReadIsRetriedOnce(\Throwable|\Psr\Http\Message\ResponseInterface $first, ?float $exactWait): void
    {
        $h = new Harness();
        $h->transport->append($first, Harness::meBody());

        $h->client->me();

        self::assertCount(2, $h->transport->requests());
        self::assertCount(1, $h->sleeper->waits);
        if ($exactWait !== null) {
            self::assertSame($exactWait, $h->sleeper->waits[0]);
        } else {
            self::assertLessThanOrEqual(0.5, $h->sleeper->waits[0]);
            self::assertGreaterThanOrEqual(0.0, $h->sleeper->waits[0]);
        }
    }

    public function testR1ThreeHtmlBadGatewaysEndAsUnexpectedAfterThreeAttempts(): void
    {
        $h = new Harness();
        for ($i = 0; $i < 3; $i++) {
            $h->transport->append(new Response(502, ['Content-Type' => 'text/html', 'CF-Ray' => 'abc'], '<html>'));
        }

        try {
            $h->client->me();
            self::fail('expected an exception');
        } catch (UnexpectedResponseException $e) {
            self::assertCount(3, $h->transport->requests());
            self::assertSame('abc', $e->cfRay());
        }
        self::assertLessThanOrEqual(1.0, $h->sleeper->waits[1]);
    }

    /** @return iterable<string, array{\Throwable|\Psr\Http\Message\ResponseInterface, class-string<\Throwable>}> */
    public static function neverRetried(): iterable
    {
        foreach ([500, 400, 401, 403, 404, 422] as $status) {
            yield (string) $status => [MockTransport::problem($status, 'code_' . $status), ApiException::class];
        }
        yield '429 daily limit' => [MockTransport::problem(429, 'daily_limit_reached'), DailyLimitException::class];
        yield 'body too large' => [new ApiConnectionException('big', true, true, 'too_large'), ApiConnectionException::class];
    }

    /** @param class-string<\Throwable> $class */
    #[DataProvider('neverRetried')]
    public function testR2OneAttemptOnly(\Throwable|\Psr\Http\Message\ResponseInterface $answer, string $class): void
    {
        $h = new Harness();
        $h->transport->append($answer, Harness::meBody());

        try {
            $h->client->me();
            self::fail('expected an exception');
        } catch (\Throwable $e) {
            self::assertInstanceOf($class, $e);
        }
        self::assertCount(1, $h->transport->requests());
        self::assertSame([], $h->sleeper->waits);
    }

    public function testR2AWaitOverTheCapIsNotTaken(): void
    {
        $h = new Harness();
        $h->transport->append(MockTransport::problem(429, 'rate_limited', 'slow', [], ['Retry-After' => '61']), Harness::meBody());

        try {
            $h->client->me();
            self::fail('expected an exception');
        } catch (RateLimitException $e) {
            self::assertSame(61, $e->getRetryAfter());
        }
        self::assertCount(1, $h->transport->requests());
    }

    public function testR4AConnectOnlyOperationRetriesOnlyWhatNeverLeft(): void
    {
        $transport = new MockTransport();
        $sleeper = new FakeSleeper();
        $handle = new SecretsHandle();
        Secrets::store($handle, Keys::apiKey(), null, null);
        $sender = new Sender($handle, $transport, new NullLogger(), new FakeClock(), $sleeper, 15.0, 2.0, 2, null);

        $transport->append(new ApiConnectionException('down', false, false, 'connect'), MockTransport::json(['data' => []]));
        $sender->execute(ErrorMapper::V1, Secrets::API, 'test.op', 'POST', 'https://example.com/x', RetryPolicy::ConnectOnly, '{}');
        self::assertCount(2, $transport->requests());

        $transport->append(new ApiConnectionException('slow', true, false, 'timeout'), MockTransport::json(['data' => []]));
        try {
            $sender->execute(ErrorMapper::V1, Secrets::API, 'test.op', 'POST', 'https://example.com/x', RetryPolicy::ConnectOnly, '{}');
            self::fail('expected the timeout');
        } catch (ApiConnectionException $e) {
            self::assertTrue($e->wasSent());
        }
        self::assertCount(3, $transport->requests());
    }

    public function testR5RetryOptions(): void
    {
        // maxRetries 0 on the client.
        $h = new Harness(['maxRetries' => 0]);
        $h->transport->append(MockTransport::problem(503, 'unavailable'), Harness::meBody());
        try {
            $h->client->me();
            self::fail('expected an exception');
        } catch (ApiException) {
            self::assertCount(1, $h->transport->requests());
        }

        // Per call.
        foreach ([new RequestOptions(maxRetries: 3), ['max_retries' => 3, 'timeout' => 5.0]] as $options) {
            $h = new Harness(['maxRetries' => 0]);
            $h->transport->append(
                MockTransport::problem(503, 'unavailable'),
                MockTransport::problem(503, 'unavailable'),
                MockTransport::problem(503, 'unavailable'),
                MockTransport::problem(503, 'unavailable'),
            );
            try {
                $h->client->me($options);
                self::fail('expected an exception');
            } catch (ApiException) {
                self::assertCount(4, $h->transport->requests());
            }
            if (is_array($options)) {
                self::assertSame(5.0, $h->transport->requests()[0]->options->timeout);
            }
        }
    }

    public function testR5AnUnknownOptionKeyIsRefused(): void
    {
        $h = new Harness();
        try {
            $h->client->me(['retries' => 3]);
            self::fail('expected a refusal');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('retries', $e->getMessage());
        }
        self::assertSame([], $h->transport->requests());
    }

    public function testR5MaxRetryWaitDecidesWhetherARetryAfterIsTaken(): void
    {
        $h = new Harness();
        $h->transport->append(MockTransport::problem(429, 'rate_limited', 'slow', [], ['Retry-After' => '3']), Harness::meBody());
        try {
            $h->client->me(['max_retry_wait' => 2.0]);
            self::fail('expected an exception');
        } catch (RateLimitException $e) {
            self::assertSame(3, $e->getRetryAfter());
        }
        self::assertCount(1, $h->transport->requests());

        $h = new Harness();
        $h->transport->append(MockTransport::problem(429, 'rate_limited', 'slow', [], ['Retry-After' => '1']), Harness::meBody());
        $h->client->me(['max_retry_wait' => 2.0]);
        self::assertSame([1.0], $h->sleeper->waits);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidOptions(): iterable
    {
        yield 'timeout 0' => [['timeout' => 0], 'timeout'];
        yield 'max_retries -1' => [['max_retries' => -1], 'max_retries'];
        yield 'connect_timeout above the client timeout' => [['connect_timeout' => 20.0], 'connectTimeout'];
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('invalidOptions')]
    public function testR5InvalidOptionsAreRefusedBeforeSending(array $options, string $named): void
    {
        $h = new Harness();
        try {
            $h->client->me($options);
            self::fail('expected a refusal');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString($named, $e->getMessage());
        }
        self::assertSame([], $h->transport->requests());
    }
}
