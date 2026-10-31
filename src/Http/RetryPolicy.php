<?php

declare(strict_types=1);

namespace WatchTower15\Http;

/**
 * How an operation may be repeated.
 *
 * @internal
 */
enum RetryPolicy
{
    /** A read: safe to repeat. */
    case Read;

    /** A write that carries an idempotency key: the same bytes and key each time. */
    case IdempotentWrite;

    /** A write without a key: only a request that certainly never left. */
    case ConnectOnly;
}
