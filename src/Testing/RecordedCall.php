<?php

declare(strict_types=1);

namespace WatchTower15\Testing;

/**
 * One operation a {@see FakeClient} was asked to run.
 */
final readonly class RecordedCall
{
    public function __construct(public string $operation, public RecordedRequest $request)
    {
    }
}
