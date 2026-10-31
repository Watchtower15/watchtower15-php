<?php

declare(strict_types=1);

namespace WatchTower15\Internal;

/** @internal */
interface Clock
{
    public function now(): \DateTimeImmutable;
}
