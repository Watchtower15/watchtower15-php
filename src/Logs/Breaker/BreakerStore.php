<?php

declare(strict_types=1);

namespace WatchTower15\Logs\Breaker;

/**
 * Where the log shipper keeps what it must remember between requests, per host and logs token (the token's SHA-256, or `none`):
 * the time sends resume after a volume stop or a hold, and when each standing condition was last reported. A store that cannot
 * be read or written counts as closed and is never an error the application sees.
 */
interface BreakerStore
{
    /** The entry for a key, an entry that ends more than an hour ahead read as ending in an hour (and written back so). */
    public function read(string $key): ?Breaker;

    /** Opens an entry ending at `$untilMs` (capped at an hour from now); never shortens an entry that ends later. */
    public function open(string $key, int $untilMs, string $reason): void;

    /** Removes the entry, only while it still ends at `$untilMs`. */
    public function clear(string $key, int $untilMs): void;

    public function lastReported(string $key, string $reason): ?int;

    public function markReported(string $key, string $reason, int $atMs): void;
}
