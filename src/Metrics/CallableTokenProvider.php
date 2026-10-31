<?php

declare(strict_types=1);

namespace WatchTower15\Metrics;

/**
 * A provider over a closure: `new CallableTokenProvider(fn () => getenv('WT15_METRICS_TOKEN') ?: '')`.
 */
final class CallableTokenProvider implements TokenProvider
{
    public function __construct(private readonly \Closure $resolver)
    {
    }

    public function token(): string
    {
        $token = ($this->resolver)();

        return is_string($token) ? $token : '';
    }
}
