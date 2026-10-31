<?php

declare(strict_types=1);

namespace WatchTower15\Internal;

use Psr\Http\Message\ResponseInterface;
use WatchTower15\Http\ResponseInfo;

/** @internal */
final readonly class ResponseInfoAndResponse
{
    public function __construct(public ResponseInfo $info, public ResponseInterface $response)
    {
    }
}
