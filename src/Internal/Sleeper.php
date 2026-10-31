<?php

declare(strict_types=1);

namespace WatchTower15\Internal;

/** @internal */
interface Sleeper
{
    public function sleep(float $seconds): void;
}
