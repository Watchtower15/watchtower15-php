<?php

declare(strict_types=1);

namespace WatchTower15\Tests\Support;

use WatchTower15\Internal\Sleeper;

final class FakeSleeper implements Sleeper
{
    /** @var list<float> */
    public array $waits = [];

    public function sleep(float $seconds): void
    {
        $this->waits[] = $seconds;
    }
}
