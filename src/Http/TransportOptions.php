<?php

declare(strict_types=1);

namespace WatchTower15\Http;

final readonly class TransportOptions
{
    public function __construct(
        public float $timeout = 15.0,
        public float $connectTimeout = 2.0,
        public int $maxResponseBytes = 16 * 1024 * 1024,
    ) {
    }
}
