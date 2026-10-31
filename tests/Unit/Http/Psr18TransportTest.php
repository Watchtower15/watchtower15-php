<?php

declare(strict_types=1);

namespace WatchTower15\Tests\Unit\Http;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use WatchTower15\Exception\ApiConnectionException;
use WatchTower15\Http\Psr18Transport;
use WatchTower15\Http\TransportOptions;

final class Psr18TransportTest extends TestCase
{
    public function testH4TheRequestPassesThroughAndFailuresBecomeConnectionExceptions(): void
    {
        $seen = new \ArrayObject();
        $ok = new Psr18Transport(new class ($seen) implements ClientInterface {
            public function __construct(private \ArrayObject $seen)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->seen[] = $request;

                return new Response(200, [], '{}');
            }
        });
        $request = new Request('GET', 'https://example.com/x', ['Authorization' => 'Bearer t']);

        self::assertSame(200, $ok->send($request, new TransportOptions())->getStatusCode());
        self::assertSame($request, $seen[0]);

        foreach ([NetworkExceptionInterface::class, RequestExceptionInterface::class] as $interface) {
            $client = $this->failing($interface, $request);
            try {
                (new Psr18Transport($client))->send($request, new TransportOptions());
                self::fail('expected a connection exception');
            } catch (ApiConnectionException $e) {
                self::assertTrue($e->wasSent());
                self::assertNull($e->getPrevious());
                self::assertStringContainsString('boom', $e->getMessage());
                self::assertStringNotContainsString('Bearer t', $e->getMessage());
            }
        }
    }

    private function failing(string $interface, RequestInterface $request): ClientInterface
    {
        $exception = $interface === NetworkExceptionInterface::class
            ? new class ('boom', $request) extends \RuntimeException implements NetworkExceptionInterface {
                public function __construct(string $m, private RequestInterface $r)
                {
                    parent::__construct($m);
                }

                public function getRequest(): RequestInterface
                {
                    return $this->r;
                }
            }
            : new class ('boom', $request) extends \RuntimeException implements RequestExceptionInterface {
                public function __construct(string $m, private RequestInterface $r)
                {
                    parent::__construct($m);
                }

                public function getRequest(): RequestInterface
                {
                    return $this->r;
                }
            };

        return new class ($exception) implements ClientInterface {
            public function __construct(private \Throwable $e)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw $this->e;
            }
        };
    }
}
