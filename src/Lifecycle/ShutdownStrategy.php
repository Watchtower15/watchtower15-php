<?php

declare(strict_types=1);

namespace WatchTower15\Lifecycle;

/**
 * The seam between what to flush (the core decides) and when (the runtime decides).
 */
interface ShutdownStrategy
{
    public function register(\Closure $callback): void;
}
