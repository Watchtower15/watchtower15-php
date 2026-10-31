<?php

declare(strict_types=1);

namespace WatchTower15\Http;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use WatchTower15\Exception\ApiConnectionException;

/**
 * Wraps a caller's PSR-18 client. Its timeouts and redirects are the client's own: turn redirects off.
 */
final class Psr18Transport implements Transport
{
    public function __construct(private readonly ClientInterface $client)
    {
    }

    public function send(#[\SensitiveParameter] RequestInterface $request, TransportOptions $options): ResponseInterface
    {
        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            // Never chained: Guzzle's exceptions hold the request, whose Authorization header is a credential.
            throw new ApiConnectionException(
                sprintf('The HTTP client failed: %s: %s', $e::class, $e->getMessage()),
                wasSent: true,
                reason: 'other',
            );
        }
        $size = $response->getBody()->getSize();
        if ($size !== null && $size > $options->maxResponseBytes) {
            throw new ApiConnectionException('The response body is larger than the SDK accepts.', true, true, 'too_large');
        }

        return $response;
    }
}
