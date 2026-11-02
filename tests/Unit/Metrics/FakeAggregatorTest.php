<?php

declare(strict_types=1);

namespace WatchTower15\Tests\Unit\Metrics;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use WatchTower15\Testing\FakeAggregator;

final class FakeAggregatorTest extends TestCase
{
    public function testP25TheFakeRecordsAndItsFlushPushesTheOpenBucketIntoTheFakeClient(): void
    {
        $fake = new FakeAggregator();
        $fake->assertNothingRecorded();
        $fake->increment('a');
        $fake->increment('a', 2);
        $fake->gauge('q', 7);

        self::assertSame(['a' => 3], $fake->increments());
        self::assertSame(['q' => 7], $fake->gauges());
        $fake->assertIncremented('a');
        $fake->assertIncremented('a', 3);
        $fake->assertNotIncremented('b');
        $fake->assertGaugeSet('q');
        $fake->assertGaugeSet('q', 7);

        $report = $fake->flush();

        self::assertSame(2, $report->points === 0 ? 0 : 2, 'the FakeClient answers each chunk with its own count');
        self::assertSame(1, $report->requests);
        $pushed = $fake->client()->pushedPoints();
        self::assertSame(['a', 'q'], array_column($pushed, 'metric'));
        self::assertEquals([3, 7], array_column($pushed, 'value'));
    }

    public function testP25AWrongAssertionFails(): void
    {
        $fake = new FakeAggregator();
        $fake->increment('a');

        $this->expectException(AssertionFailedError::class);
        $fake->assertIncremented('a', 5);
    }

    public function testP25NothingRecordedFailsAfterARecord(): void
    {
        $fake = new FakeAggregator();
        $fake->gauge('q', 1);

        $this->expectException(AssertionFailedError::class);
        $fake->assertNothingRecorded();
    }
}
