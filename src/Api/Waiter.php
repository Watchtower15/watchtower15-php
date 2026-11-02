<?php

declare(strict_types=1);

namespace WatchTower15\Api;

use WatchTower15\Api\Generated\Models\ScansView;
use WatchTower15\ConfigurationException;
use WatchTower15\Exception\WaiterTimeoutException;

/**
 * Polls a scan until its status is neither `queued` nor `running` (plan WT15-SDK-00007 D15), on the client's clock and sleeper,
 * so a test with a fake pair never waits.
 *
 * @internal
 */
final class Waiter
{
    private const IN_PROGRESS = ['queued', 'running'];

    public function __construct(private readonly Operations $operations)
    {
    }

    /**
     * @param \Closure(): ScansView $poll
     * @throws ConfigurationException for an argument that cannot work, before the first poll
     * @throws WaiterTimeoutException
     */
    public function untilFinished(\Closure $poll, int $timeoutSeconds, int $pollSeconds): ScansView
    {
        if ($pollSeconds < 1) {
            throw new ConfigurationException('`$pollSeconds` must be at least 1.');
        }
        if ($timeoutSeconds < 0) {
            throw new ConfigurationException('`$timeoutSeconds` must not be negative.');
        }
        $deadline = self::seconds($this->operations->now()) + $timeoutSeconds;
        while (true) {
            $scan = $poll();
            if (! in_array($scan->status, self::IN_PROGRESS, true)) {
                return $scan;
            }
            if (self::seconds($this->operations->now()) + $pollSeconds > $deadline) {
                throw new WaiterTimeoutException($scan, $timeoutSeconds, $this->operations->lastResponse());
            }
            $this->operations->sleep((float) $pollSeconds);
        }
    }

    private static function seconds(\DateTimeImmutable $time): float
    {
        return (float) $time->format('U.u');
    }
}
