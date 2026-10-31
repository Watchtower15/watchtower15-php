<?php

declare(strict_types=1);

namespace WatchTower15\Http;

final readonly class RateLimitState
{
    public function __construct(
        public int $limit,
        public int $remaining,
        public ?\DateTimeImmutable $resetAt,
    ) {
    }
}
