<?php

declare(strict_types=1);

namespace WatchTower15\Tests\Api;

use PHPUnit\Framework\TestCase;
use WatchTower15\Testing\FakeClient;
use WatchTower15\Testing\RecordedCall;
use WatchTower15\Tests\Support\Fixture;

/** R15 (plan WT15-SDK-00007 §9.2, D26): the fake over the generated surface. */
final class FakeClientSurfaceTest extends TestCase
{
    public function testR15TheFakeAnswersAndRecordsEveryGeneratedMethodTheWaiterAndRequest(): void
    {
        $fake = new FakeClient();
        $uuid = Fixture::UUID;
        $fake->queue('public.servers.list', Fixture::answer('public.servers.list', ['name' => 'web-1']));
        foreach (['queued', 'running', 'completed'] as $status) {
            $fake->queue('public.scans.view', Fixture::answer('public.scans.view', ['status' => $status]));
        }
        $fake->queue('request:GET /servers/{server_uuid}/ports', Fixture::response(['data' => [['port' => 443]], 'next_cursor' => null]));

        $servers = $fake->servers->list();
        $scan = $fake->scans->waitUntilFinished($uuid);
        $ports = $fake->request('GET', '/servers/{server_uuid}/ports', ['server_uuid' => $uuid]);

        self::assertSame('web-1', $servers->data[0]->name);
        self::assertSame('completed', $scan->status);
        self::assertSame([['port' => 443]], $ports['data']);
        self::assertSame([5.0, 5.0], $fake->time()->waits, 'the waiter ran on the fake clock and never waited');
        self::assertSame(
            ['public.servers.list', 'public.scans.view', 'public.scans.view', 'public.scans.view', 'request:GET /servers/{server_uuid}/ports'],
            array_map(static fn (RecordedCall $call): string => $call->operation, $fake->calls()),
        );
        $fake->assertCalled('public.servers.list');
        $fake->assertCalled('public.scans.view', times: 3);
        $fake->assertNotCalled('public.incidents.list');

        try {
            $fake->incidents->list();
            self::fail('a call with nothing queued must throw');
        } catch (\LogicException $e) {
            self::assertStringContainsString('public.incidents.list', $e->getMessage());
        }
    }

    public function testTheFakeStillAnswersPushesItself(): void
    {
        $fake = new FakeClient();

        $result = $fake->ingest->logs->push([['message' => 'hello']]);

        self::assertTrue($result->accepted);
        $fake->assertCalled('ingest.logs.push');
    }
}
