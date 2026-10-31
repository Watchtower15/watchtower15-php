<?php

declare(strict_types=1);

namespace WatchTower15\Tests\Unit\Exception;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WatchTower15\Exception\ApiConnectionException;
use WatchTower15\Exception\ApiException;
use WatchTower15\Exception\AuthenticationException;
use WatchTower15\Exception\DailyLimitException;
use WatchTower15\Exception\InvalidRequestException;
use WatchTower15\Exception\LimitReachedException;
use WatchTower15\Exception\LogVolumeLimitException;
use WatchTower15\Exception\NotFoundException;
use WatchTower15\Exception\PayloadTooLargeException;
use WatchTower15\Exception\PaymentRequiredException;
use WatchTower15\Exception\PermissionException;
use WatchTower15\Exception\RateLimitException;
use WatchTower15\Exception\ServerException;
use WatchTower15\Exception\ServiceUnavailableException;
use WatchTower15\Exception\UnexpectedResponseException;
use WatchTower15\Exception\WatchTower15Exception;
use WatchTower15\Internal\ErrorMapper;
use WatchTower15\Testing\MockTransport;
use WatchTower15\Tests\Support\Harness;

final class ErrorMappingTest extends TestCase
{
    /** @return iterable<string, array{string, int, string, string}> */
    public static function table(): iterable
    {
        foreach ([ErrorMapper::V1, ErrorMapper::INGEST] as $api) {
            yield "$api 400" => [$api, 400, 'bad_request', InvalidRequestException::class];
            yield "$api 401" => [$api, 401, 'unauthorized', AuthenticationException::class];
            yield "$api 402" => [$api, 402, 'payment_required', PaymentRequiredException::class];
            yield "$api 403" => [$api, 403, 'forbidden', $api === ErrorMapper::V1 ? PermissionException::class : AuthenticationException::class];
            yield "$api 404" => [$api, 404, 'not_found', NotFoundException::class];
            yield "$api 413" => [$api, 413, 'too_large', PayloadTooLargeException::class];
            yield "$api 422 other" => [$api, 422, 'something_else', InvalidRequestException::class];
            yield "$api 429 rate" => [$api, 429, 'rate_limited', RateLimitException::class];
            yield "$api 500" => [$api, 500, 'server_error', ServerException::class];
            yield "$api 502" => [$api, 502, 'bad_gateway', ServerException::class];
            yield "$api 503" => [$api, 503, 'unavailable', ServiceUnavailableException::class];
            yield "$api 409" => [$api, 409, 'conflict', ApiException::class];
            yield "$api 418" => [$api, 418, 'teapot', ApiException::class];
        }
        yield 'v1 422 limit_reached' => [ErrorMapper::V1, 422, 'limit_reached', LimitReachedException::class];
        yield 'v1 422 held_limit_reached' => [ErrorMapper::V1, 422, 'held_limit_reached', LimitReachedException::class];
        yield 'ingest 422 limit_reached is plain invalid' => [ErrorMapper::INGEST, 422, 'limit_reached', InvalidRequestException::class];
        yield 'v1 429 daily' => [ErrorMapper::V1, 429, 'daily_limit_reached', DailyLimitException::class];
        yield 'ingest 429 log stop' => [ErrorMapper::INGEST, 429, 'log_volume_limit_reached', LogVolumeLimitException::class];
    }

    /** @param class-string<ApiException> $class */
    #[DataProvider('table')]
    public function testE1TheMappingTable(string $api, int $status, string $code, string $class): void
    {
        $response = $api === ErrorMapper::V1
            ? MockTransport::problem($status, $code, 'Detail text.', ['errors' => ['name' => ['required']], 'limit' => 5, 'used' => 5])
            : MockTransport::envelope($status, ['code' => $code, 'resumes_at' => 1_798_848_000_000], 'Detail text.', [], ['name' => ['required']]);

        $exception = ErrorMapper::map($response, $api, 'some.operation');

        self::assertInstanceOf($class, $exception);
        self::assertInstanceOf(WatchTower15Exception::class, $exception);
        self::assertSame($status, $exception->getStatusCode());
        self::assertSame($code, $exception->getErrorCode());
        self::assertSame('Detail text.', $exception->getDetail());
        self::assertSame($api === ErrorMapper::V1 ? 'req-' . $code : 'req-envelope', $exception->getRequestId());
        self::assertSame('some.operation', $exception->getOperation());
        self::assertStringContainsString('HTTP ' . $status, $exception->getMessage());
        if ($exception instanceof InvalidRequestException) {
            self::assertSame(['name' => ['required']], $exception->errors());
        }
        if ($exception instanceof LimitReachedException) {
            self::assertSame(5, $exception->limit());
            self::assertSame(5, $exception->used());
        }
        if ($exception instanceof LogVolumeLimitException) {
            self::assertSame('2027-01-02T00:00:00+00:00', $exception->resumesAt()?->format('c'));
        }
    }

    public function testE1TheRequestIdFallsBackToTheHeaderAndRetryAfterReadsBothForms(): void
    {
        $body = '{"type":"x","title":"t","status":429,"code":"rate_limited","detail":"slow"}';
        $delta = ErrorMapper::map(new Response(429, ['Content-Type' => 'application/problem+json', 'X-Request-Id' => 'hdr-1', 'Retry-After' => '7'], $body), ErrorMapper::V1, 'op');
        self::assertSame('hdr-1', $delta->getRequestId());
        self::assertSame(7, $delta->getRetryAfter());

        $future = gmdate('D, d M Y H:i:s', time() + 120) . ' GMT';
        $date = ErrorMapper::map(new Response(429, ['Content-Type' => 'application/problem+json', 'Retry-After' => $future], $body), ErrorMapper::V1, 'op');
        self::assertEqualsWithDelta(120, $date->getRetryAfter(), 5);
    }

    public function testE2UnknownAndSpecialCases(): void
    {
        $key = ErrorMapper::map(MockTransport::envelope(422, ['code' => 'idempotency_key_reused'], 'Reused.', [], ['Idempotency-Key' => ['reused']]), ErrorMapper::INGEST, 'ingest.logs.push');

        self::assertInstanceOf(InvalidRequestException::class, $key);
        self::assertSame('idempotency_key_reused', $key->getErrorCode());
        self::assertSame(['Idempotency-Key' => ['reused']], $key->errors());
    }

    public function testE3NonWatchTower15Answers(): void
    {
        $html = ErrorMapper::map(new Response(403, ['Content-Type' => 'text/html', 'CF-Ray' => 'abc-LHR'], '<html>blocked</html>'), ErrorMapper::V1, 'op');
        self::assertInstanceOf(UnexpectedResponseException::class, $html);
        self::assertSame('abc-LHR', $html->cfRay());
        self::assertSame('text/html', $html->contentType());

        $empty = ErrorMapper::map(new Response(200, [], ''), ErrorMapper::V1, 'op');
        self::assertInstanceOf(UnexpectedResponseException::class, $empty);

        $redirect = ErrorMapper::map(new Response(302, ['Location' => 'https://elsewhere.example/x?token=secret']), ErrorMapper::INGEST, 'op');
        self::assertInstanceOf(UnexpectedResponseException::class, $redirect);
        self::assertStringContainsString('elsewhere.example', $redirect->getMessage());
        self::assertStringNotContainsString('token=secret', $redirect->getMessage());
        self::assertStringNotContainsString('/x', $redirect->getMessage());

        $shapeless = ErrorMapper::map(new Response(500, ['Content-Type' => 'application/json'], '{"oops":true}'), ErrorMapper::V1, 'op');
        self::assertInstanceOf(UnexpectedResponseException::class, $shapeless);
    }

    public function testE3A2xxWithoutTheSuccessShapeIsNotASuccess(): void
    {
        self::assertFalse(ErrorMapper::isSuccess(new Response(200, [], '{"hello":1}'), ErrorMapper::V1));
        self::assertFalse(ErrorMapper::isSuccess(new Response(200, [], '{"status":"failure"}'), ErrorMapper::INGEST));
        self::assertTrue(ErrorMapper::isSuccess(new Response(200, [], '{"data":{}}'), ErrorMapper::V1));
        self::assertTrue(ErrorMapper::isSuccess(MockTransport::envelope(200), ErrorMapper::INGEST));
    }

    public function testE4NothingSecretReachesAnExceptionOrItsTrace(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $secrets = ['wt15ak_', 'wt15ms_', 'wt15ls_'];
            $cases = [
                MockTransport::problem(403, 'forbidden'),
                MockTransport::envelope(429, ['code' => 'log_volume_limit_reached'], 'Stop'),
                new Response(403, ['Content-Type' => 'text/html'], '<html>'),
            ];
            foreach ($cases as $response) {
                $h = new Harness();
                $h->transport->append($response);
                try {
                    $h->client->me();
                    self::fail('expected an exception');
                } catch (ApiException $e) {
                    $text = $e->getMessage() . $e->__toString() . json_encode($e->getProblem()) . json_encode($e->getHeaders());
                    foreach ($secrets as $prefix) {
                        self::assertStringNotContainsString($prefix . str_repeat('a', 20), $text);
                    }
                    self::assertStringNotContainsString('Authorization', $text);
                    self::assertStringNotContainsString('Bearer', $text);
                }
            }
            $this->assertConnectionTraceIsClean();
        } finally {
            if ($previous !== false) {
                ini_set('zend.exception_ignore_args', $previous);
            }
        }
    }

    private function assertConnectionTraceIsClean(): void
    {
        $h = new Harness();
        $h->transport->append(new ApiConnectionException('refused', false, false, 'connect'), new ApiConnectionException('refused', false, false, 'connect'), new ApiConnectionException('refused', false, false, 'connect'));
        try {
            $h->client->me();
            self::fail('expected a connection exception');
        } catch (ApiConnectionException $e) {
            self::assertNull($e->getPrevious());
            foreach ($e->getTrace() as $frame) {
                foreach ($frame['args'] ?? [] as $argument) {
                    self::assertNotInstanceOf(\Psr\Http\Message\RequestInterface::class, $argument);
                    if (is_string($argument)) {
                        self::assertStringNotContainsString(str_repeat('a', 30), $argument);
                    }
                }
            }
        }
    }
}
