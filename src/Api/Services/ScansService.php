<?php

declare(strict_types=1);

namespace WatchTower15\Api\Services;

use WatchTower15\Api\Generated\Models\ScansView;
use WatchTower15\Api\Generated\Services\ScansService as GeneratedScansService;
use WatchTower15\Api\Waiter;

/** The generated scans service, plus the waiter (plan WT15-SDK-00007 D15). */
class ScansService extends GeneratedScansService
{
    /**
     * Polls the scan every `$pollSeconds` until its status is neither `queued` nor `running` and returns it. A status the SDK
     * does not know ends the wait too. Past `$timeoutSeconds` it throws `WaiterTimeoutException`, which carries the last scan.
     *
     * @throws \WatchTower15\ConfigurationException when `$pollSeconds` is below 1 or `$timeoutSeconds` is negative
     * @throws \WatchTower15\Exception\WaiterTimeoutException
     */
    public function waitUntilFinished(string $scanUuid, int $timeoutSeconds = 120, int $pollSeconds = 5): ScansView
    {
        return (new Waiter($this->operations))->untilFinished(fn (): ScansView => $this->view($scanUuid), $timeoutSeconds, $pollSeconds);
    }
}
