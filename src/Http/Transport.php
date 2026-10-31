<?php

declare(strict_types=1);

namespace WatchTower15\Http;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use WatchTower15\Exception\ApiConnectionException;

/**
 * Sends one request and returns every status as a response; throws only {@see ApiConnectionException}.
 *
 * An implementation marks `send()`'s `$request` `#[\SensitiveParameter]` (it carries the `Authorization` header) and
 * never chains an exception that holds the request.
 */
interface Transport
{
    /**
     * @throws ApiConnectionException
     */
    public function send(#[\SensitiveParameter] RequestInterface $request, TransportOptions $options): ResponseInterface;
}
