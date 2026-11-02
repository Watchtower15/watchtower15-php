<?php

declare(strict_types=1);

namespace WatchTower15\Internal;

use WatchTower15\Metrics\BucketStore;

/**
 * The aggregator's error counters: in the store, or, when the store itself fails, in the process until the next report.
 *
 * @internal
 */
final class ErrorCounts
{
    /** @var array<string, int> */
    private array $process = [];

    public function __construct(private readonly BucketStore $store)
    {
    }

    public function count(string $reason, int $by = 1): void
    {
        try {
            $this->store->addError($reason, $by);
        } catch (\Throwable) {
            $this->process[$reason] = ($this->process[$reason] ?? 0) + $by;
        }
    }

    /** A store call failed: counted in the process, since the store cannot count its own failure. */
    public function storeFailure(): void
    {
        $this->process['store'] = ($this->process['store'] ?? 0) + 1;
    }

    /**
     * @return array<string, int>
     */
    public function takeProcess(): array
    {
        $taken = $this->process;
        $this->process = [];

        return $taken;
    }
}
