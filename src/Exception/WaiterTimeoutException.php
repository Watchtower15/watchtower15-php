<?php

declare(strict_types=1);

namespace WatchTower15\Exception;

use WatchTower15\Api\Generated\Models\ScansView;
use WatchTower15\Http\ResponseInfo;

/**
 * `scans->waitUntilFinished()` ran out of time with the scan still queued or running (D15). It carries the status and request
 * id of the last poll and the last scan read.
 */
class WaiterTimeoutException extends ApiException
{
    public function __construct(private readonly ScansView $scan, int $timeoutSeconds, ?ResponseInfo $last = null)
    {
        parent::__construct(
            sprintf('The scan %s was still %s after %d seconds.', $scan->uuid, $scan->status, $timeoutSeconds),
            $last?->statusCode,
            'waiter_timeout',
            null,
            $last?->requestId,
            [],
            $last === null ? [] : $last->headers,
            'public.scans.view',
        );
    }

    /** The last scan the waiter read. */
    public function scan(): ScansView
    {
        return $this->scan;
    }
}
