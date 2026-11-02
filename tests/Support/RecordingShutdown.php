<?php

declare(strict_types=1);

namespace WatchTower15\Tests\Support;

use WatchTower15\Lifecycle\ShutdownStrategy;

/** A shutdown strategy that records its callbacks and runs them when told. */
final class RecordingShutdown implements ShutdownStrategy
{
    /** @var list<\Closure> */
    public array $callbacks = [];

    public function register(\Closure $callback): void
    {
        $this->callbacks[] = $callback;
    }

    public function runAll(): void
    {
        $callbacks = $this->callbacks;
        $this->callbacks = [];
        foreach ($callbacks as $callback) {
            $callback();
        }
    }
}
