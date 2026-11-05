<?php

declare(strict_types=1);

namespace WatchTower15\Tests\Api;

use PHPUnit\Framework\TestCase;
use WatchTower15\Api\Generated\Models\DomainsRunScan;
use WatchTower15\Api\Operations;
use WatchTower15\Api\Generated\Models\IncidentsAcknowledge;
use WatchTower15\Api\Generated\Models\ServersListItem;
use WatchTower15\Api\Generated\Models\ServersMetrics;
use WatchTower15\Api\Page;
use WatchTower15\ConfigurationException;
use WatchTower15\Exception\ApiConnectionException;
use WatchTower15\Exception\ApiException;
use WatchTower15\Exception\AuthenticationException;
use WatchTower15\Exception\InvalidRequestException;
use WatchTower15\Exception\NotFoundException;
use WatchTower15\Exception\PermissionException;
use WatchTower15\Exception\RateLimitException;
use WatchTower15\Exception\ServiceUnavailableException;
use WatchTower15\Exception\UnexpectedResponseException;
use WatchTower15\Exception\WaiterTimeoutException;
use WatchTower15\Http\RequestOptions;
use WatchTower15\Testing\FakeTime;
use WatchTower15\Testing\MockTransport;
use WatchTower15\Tests\Support\Fixture;
use WatchTower15\Tests\Support\Harness;

/** R1–R14 (plan WT15-SDK-00007 §9.2). R15 is in FakeClientSurfaceTest. */
final class OperationsTest extends TestCase
{
    private const U = Fixture::UUID;

    private const BASE = 'https://rest.watchtower15.com/v1';

    // ── R1–R4: reads, paging, pacing ────────────────────────────────────────────────────────────────────────────────

    public function testR1AListGoesOutAsTheBackendReadsItAndComesBackAsAPage(): void
    {
        $h = new Harness();
        $h->transport->append(Fixture::answer('public.servers.list', [], ['total' => 3]));

        $page = $h->client->servers->list(['health' => ['critical', 'warning'], 'include_total' => true, 'limit' => 100]);

        $request = $h->transport->requests()[0];
        self::assertSame('GET', $request->method);
        // The URI object writes the brackets as %5B%5D, the same bytes the backend reads as `health[]`.
        self::assertSame(self::BASE . '/servers?health[]=critical&health[]=warning&include_total=1&limit=100', rawurldecode($request->uri));
        self::assertInstanceOf(Page::class, $page);
        self::assertContainsOnlyInstancesOf(ServersListItem::class, $page->data);
        self::assertSame(3, $page->total);
        self::assertNull($page->nextCursor);
        self::assertSame(200, $page->response?->statusCode);
    }

    public function testR2UnknownFieldsAndValuesAreKeptAndNeverRefused(): void
    {
        $h = new Harness();
        $h->transport->append(MockTransport::json(['data' => [['health_status' => 'brand_new', 'extra_field' => ['x' => 1]] + Fixture::load('public.servers.list')['data'][0]], 'next_cursor' => null]));

        $item = $h->client->servers->list()->data[0];

        self::assertSame('brand_new', $item->healthStatus);
        self::assertSame(['x' => 1], $item->toArray()['extra_field']);
        self::assertSame(self::U, $item->uuid);
        self::assertSame('2027-01-01T00:00:00+00:00', $item->agentLastSeenAt?->format('c'), 'an instant is a UTC DateTimeImmutable');
    }

    public function testR3AutoPagingFetchesLazilyWithTheSameFiltersPlusTheCursor(): void
    {
        $h = new Harness();
        $h->transport->append(Fixture::page('public.servers.list', ['a1', 'a2'], 'c1'), Fixture::page('public.servers.list', ['b1', 'b2'], 'c2'), Fixture::page('public.servers.list', ['c1'], null));

        $items = $h->client->servers->list(['limit' => 2, 'search' => 'web'])->autoPaging();
        self::assertCount(1, $h->transport->requests(), 'the first page is fetched by list()');
        self::assertSame('a1', $items->current()->uuid);
        self::assertCount(1, $h->transport->requests(), 'no call before an item beyond the first page is asked for');

        $uuids = [];
        foreach ($items as $item) {
            $uuids[] = $item->uuid;
        }

        self::assertSame(['a1', 'a2', 'b1', 'b2', 'c1'], $uuids);
        $uris = array_map(static fn ($r): string => $r->uri, $h->transport->requests());
        self::assertSame([
            self::BASE . '/servers?limit=2&search=web',
            self::BASE . '/servers?cursor=c1&limit=2&search=web',
            self::BASE . '/servers?cursor=c2&limit=2&search=web',
        ], $uris);
    }

    public function testR4PacingWaitsForAResetThatIsCloseAndThrowsForOneThatIsNot(): void
    {
        // A reset 20 s away: slept on the fake sleeper, then the next page is fetched.
        $h = new Harness();
        $limited = static fn (int $seconds): array => ['X-RateLimit-Limit' => '60', 'X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => (string) ($h->clock->now->getTimestamp() + $seconds)];
        $h->transport->append(Fixture::page('public.servers.list', ['a'], 'c1', $limited(20)), Fixture::page('public.servers.list', ['b'], null));
        $uuids = array_map(static fn ($i): string => $i->uuid, iterator_to_array($h->client->servers->list()->autoPaging(), false));
        self::assertSame(['a', 'b'], $uuids);
        self::assertSame([20.0], $h->sleeper->waits);
        self::assertCount(2, $h->transport->requests());

        // 90 s away: RateLimitException with the seconds to the reset, nothing sent.
        $h = new Harness();
        $limited = static fn (int $seconds): array => ['X-RateLimit-Limit' => '60', 'X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => (string) ($h->clock->now->getTimestamp() + $seconds)];
        $h->transport->append(Fixture::page('public.servers.list', ['a'], 'c1', $limited(90)), Fixture::page('public.servers.list', ['b'], null));
        try {
            iterator_to_array($h->client->servers->list()->autoPaging());
            self::fail('expected a RateLimitException');
        } catch (RateLimitException $e) {
            self::assertSame(90, $e->getRetryAfter());
            self::assertSame(429, $e->getStatusCode());
            self::assertSame('rate_limited', $e->getErrorCode());
        }
        self::assertCount(1, $h->transport->requests());
        self::assertSame([], $h->sleeper->waits);

        // 20 s away with a 10 s cap on this call: it throws too.
        $h = new Harness();
        $limited = static fn (int $seconds): array => ['X-RateLimit-Limit' => '60', 'X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => (string) ($h->clock->now->getTimestamp() + $seconds)];
        $h->transport->append(Fixture::page('public.servers.list', ['a'], 'c1', $limited(20)));
        $this->expectException(RateLimitException::class);
        iterator_to_array($h->client->servers->list([], ['max_retry_wait' => 10])->autoPaging());
    }

    // ── R5–R7: writes ───────────────────────────────────────────────────────────────────────────────────────────────

    public function testR5AWriteKeepsOneIdempotencyKeyAcrossItsRetriesAndAReplayIsASuccess(): void
    {
        $h = new Harness();
        $h->transport->append(
            new ApiConnectionException('down', false, false, 'connect'),
            MockTransport::problem(503, 'service_unavailable'),
            Fixture::answer('public.incidents.acknowledge', [], [], 200, ['Idempotent-Replayed' => 'true']),
        );

        $result = $h->client->incidents->acknowledge(self::U);

        self::assertInstanceOf(IncidentsAcknowledge::class, $result);
        $requests = $h->transport->requests();
        self::assertCount(3, $requests);
        $keys = array_map(static fn ($r): ?string => $r->header('Idempotency-Key'), $requests);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string) $keys[0]);
        self::assertSame([$keys[0], $keys[0], $keys[0]], $keys);
        self::assertTrue($h->client->lastResponse()?->replayed);
        self::assertSame('POST', $requests[0]->method);
        self::assertSame(self::BASE . '/incidents/' . self::U . '/acknowledge', $requests[0]->uri);

        // The caller's own key is used as given.
        $h->transport->append(Fixture::answer('public.incidents.acknowledge'));
        $h->client->incidents->acknowledge(self::U, [], ['idempotency_key' => 'my-key-1']);
        self::assertSame('my-key-1', $h->transport->requests()[3]->header('Idempotency-Key'));

        // A key of the wrong form is refused before anything is sent.
        foreach ([str_repeat('k', 256), 'a b', ''] as $bad) {
            try {
                $h->client->incidents->acknowledge(self::U, [], ['idempotency_key' => $bad]);
                self::fail('expected a ConfigurationException');
            } catch (ConfigurationException $e) {
                self::assertStringContainsString('idempotency_key', $e->getMessage());
            }
        }
        self::assertCount(4, $h->transport->requests());
    }

    public function testR6AWriteWithoutAKeyIsNotRetriedAfterItWasSent(): void
    {
        // The table's three writes all declare the header, so the not-idempotent policy (ConnectOnly) is reached through request().
        $h = new Harness();
        $h->transport->append(new ApiConnectionException('slow', true, false, 'timeout'), Fixture::response(['data' => []]));

        try {
            $h->client->request('PATCH', '/things/{thing_uuid}', ['thing_uuid' => self::U]);
            self::fail('expected an ApiConnectionException');
        } catch (ApiConnectionException $e) {
            self::assertTrue($e->wasSent());
        }
        self::assertCount(1, $h->transport->requests());
    }

    public function testR7RunScanAnswers202WithTheScanAndItsPath(): void
    {
        $h = new Harness();
        $h->transport->append(Fixture::answer('public.domains.runScan', ['status' => 'queued', 'scan_uuid' => '9d3b0c1e-2f6a-4a3b-8c55-7e1d2f4a6b88'], [], 202));

        $scan = $h->client->domains->runScan(self::U, 'ssl');

        self::assertInstanceOf(DomainsRunScan::class, $scan);
        self::assertSame('9d3b0c1e-2f6a-4a3b-8c55-7e1d2f4a6b88', $scan->scanUuid);
        self::assertSame('queued', $scan->status);
        $request = $h->transport->requests()[0];
        self::assertSame('POST', $request->method);
        self::assertSame(self::BASE . '/domains/' . self::U . '/scans/ssl', $request->uri);
        self::assertNotNull($request->header('Idempotency-Key'));
        self::assertNull($request->body, 'the writes have no body');
    }

    // ── R8: the waiter ──────────────────────────────────────────────────────────────────────────────────────────────

    public function testR8TheWaiterPollsToAFinalStatusAndTimesOutWithTheLastScan(): void
    {
        $scan = static fn (string $status): \GuzzleHttp\Psr7\Response => Fixture::answer('public.scans.view', ['status' => $status], [], 200, ['X-Request-Id' => 'req-' . $status]);

        // queued, running, completed: returned on the third poll, after two waits.
        $h = new Harness();
        $time = new FakeTime(new \DateTimeImmutable('2026-11-01T12:00:00Z'));
        $h->client->useTimeSeams($time, $time);
        $h->transport->append($scan('queued'), $scan('running'), $scan('completed'));
        $done = $h->client->scans->waitUntilFinished(self::U);
        self::assertSame('completed', $done->status);
        self::assertSame([5.0, 5.0], $time->waits);
        self::assertSame('2026-11-01T12:00:10+00:00', $time->now()->format('c'));
        self::assertCount(3, $h->transport->requests());
        self::assertSame(self::BASE . '/scans/' . self::U, $h->transport->requests()[0]->uri);

        // A status added later ends the wait and is returned.
        $h = new Harness();
        $h->transport->append($scan('archived'));
        self::assertSame('archived', $h->client->scans->waitUntilFinished(self::U)->status);

        // Still running past the timeout: the last scan rides on the exception.
        $h = new Harness();
        $time = new FakeTime(new \DateTimeImmutable('2026-11-01T12:00:00Z'));
        $h->client->useTimeSeams($time, $time);
        $h->transport->append($scan('running'), $scan('running'), $scan('running'), $scan('running'));
        try {
            $h->client->scans->waitUntilFinished(self::U, 10, 5);
            self::fail('expected a WaiterTimeoutException');
        } catch (WaiterTimeoutException $e) {
            self::assertSame('running', $e->scan()->status);
            self::assertSame('waiter_timeout', $e->getErrorCode());
            self::assertSame(200, $e->getStatusCode());
            self::assertSame('req-running', $e->getRequestId());
            self::assertInstanceOf(ApiException::class, $e);
        }
        self::assertCount(3, $h->transport->requests());

        // An argument that cannot work is refused before the first poll.
        $h = new Harness();
        foreach ([[120, 0], [120, -1], [-1, 5]] as [$timeout, $poll]) {
            try {
                $h->client->scans->waitUntilFinished(self::U, $timeout, $poll);
                self::fail('expected a ConfigurationException');
            } catch (ConfigurationException $e) {
                self::assertMatchesRegularExpression('/pollSeconds|timeoutSeconds/', $e->getMessage());
            }
        }
        self::assertCount(0, $h->transport->requests());
    }

    // ── R9, R10: request() ──────────────────────────────────────────────────────────────────────────────────────────

    public function testR9RequestRunsAnyOperationWithTheSamePolicies(): void
    {
        $h = new Harness();
        $h->transport->append(Fixture::response(['data' => [['port' => 22]], 'next_cursor' => null, 'total' => 1]));
        $envelope = $h->client->request('GET', '/servers/{server_uuid}/ports', ['server_uuid' => self::U, 'limit' => 5]);
        self::assertSame(self::BASE . '/servers/' . self::U . '/ports?limit=5', $h->transport->requests()[0]->uri);
        self::assertSame([['port' => 22]], $envelope['data']);
        self::assertSame(1, $envelope['total']);

        // A POST carries one key, the same on its retry.
        $h = new Harness();
        $h->transport->append(MockTransport::problem(503, 'service_unavailable'), Fixture::answer('public.incidents.resolve'));
        $h->client->request('POST', '/incidents/{incident_uuid}/resolve', ['incident_uuid' => self::U, 'note' => 'done']);
        [$first, $second] = $h->transport->requests();
        self::assertNotNull($first->header('Idempotency-Key'));
        self::assertSame($first->header('Idempotency-Key'), $second->header('Idempotency-Key'));
        self::assertSame(['note' => 'done'], $first->body, 'the rest of the params is the JSON body');

        // A DELETE is a write (Plan_API_06 D24 (1)): it carries a key too, the same on its retry after a 503.
        $h = new Harness();
        $h->transport->append(MockTransport::problem(503, 'service_unavailable'), Fixture::response(['data' => []]));
        $h->client->request('delete', '/things/{thing_uuid}', ['thing_uuid' => self::U]);
        [$first, $second] = $h->transport->requests();
        self::assertSame('DELETE', $first->method);
        self::assertNotNull($first->header('Idempotency-Key'));
        self::assertSame($first->header('Idempotency-Key'), $second->header('Idempotency-Key'));

        // Its connect failure before anything was sent is retried once.
        $h = new Harness();
        $h->transport->append(new ApiConnectionException('refused', false, false, 'connect'), Fixture::response(['data' => []]));
        $h->client->request('DELETE', '/things/{thing_uuid}', ['thing_uuid' => self::U]);
        self::assertCount(2, $h->transport->requests());

        // An idempotency key on a PATCH is refused, naming it, with nothing sent.
        $h = new Harness();
        try {
            $h->client->request('PATCH', '/things/{thing_uuid}', ['thing_uuid' => self::U], ['idempotency_key' => 'k']);
            self::fail('expected a ConfigurationException');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('idempotency_key', $e->getMessage());
        }
        self::assertCount(0, $h->transport->requests());
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function refusedRequests(): iterable
    {
        yield 'a full url' => ['GET', 'https://evil.example/v1/me'];
        yield 'a parent segment' => ['GET', '/../api/me'];
        yield 'a query in the path' => ['GET', '/servers?x=1'];
        yield 'a fragment' => ['GET', '/servers#x'];
        yield 'an unfilled placeholder' => ['GET', '/servers/{server_uuid}'];
        yield 'an encoded dot' => ['GET', '/%2e%2e/api/me'];
        yield 'an encoded slash' => ['GET', '/servers%2F1'];
        yield 'a control character' => ['GET', "/servers\n"];
        yield 'a path without a leading slash' => ['GET', 'servers'];
        yield 'another method' => ['HEAD', '/servers'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedRequests')]
    public function testR10RequestRefusesWhatCouldCarryTheKeyElsewhere(string $method, string $path): void
    {
        $h = new Harness();

        try {
            $h->client->request($method, $path);
            self::fail('expected a ConfigurationException');
        } catch (ConfigurationException $e) {
            self::assertMatchesRegularExpression('/path|method/', $e->getMessage());
        }
        self::assertCount(0, $h->transport->requests());
    }

    // ── R11, R12: errors and a model ────────────────────────────────────────────────────────────────────────────────

    /** @return iterable<string, array{0: int, 1: string, 2: class-string<ApiException>}> */
    public static function problems(): iterable
    {
        yield '400' => [400, 'invalid_request', InvalidRequestException::class];
        yield '401' => [401, 'unauthorized', AuthenticationException::class];
        yield '403' => [403, 'forbidden', PermissionException::class];
        yield '404' => [404, 'not_found', NotFoundException::class];
        yield '422' => [422, 'invalid_request', InvalidRequestException::class];
        yield '429' => [429, 'rate_limited', RateLimitException::class];
        yield '503' => [503, 'service_unavailable', ServiceUnavailableException::class];
        yield 'an unknown code with 409 maps by status' => [409, 'something_new', ApiException::class];
    }

    /**
     * @param class-string<ApiException> $class
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('problems')]
    public function testR11EachProblemCodeMapsToItsClassOnAGeneratedMethod(int $status, string $code, string $class): void
    {
        $h = new Harness();
        $h->transport->append(...array_fill(0, 3, MockTransport::problem($status, $code, 'Nope.', [], ['Retry-After' => '1'])));

        try {
            $h->client->servers->view(self::U);
            self::fail('expected an exception');
        } catch (ApiException $e) {
            self::assertSame($class, $e::class);
            self::assertSame($status, $e->getStatusCode());
            self::assertSame($code, $e->getErrorCode());
            self::assertSame('req-' . $code, $e->getRequestId());
        }
    }

    public function testR12TheMetricsFamilyIsOneMethodAnsweringOneModel(): void
    {
        $h = new Harness();
        $h->transport->append(Fixture::answer('public.servers.metrics', ['family' => 'saturation', 'oom_kills_in_window' => 2]));

        $metrics = $h->client->servers->metrics(self::U, ['family' => 'saturation', 'period' => '1h']);

        self::assertInstanceOf(ServersMetrics::class, $metrics);
        self::assertSame(self::BASE . '/servers/' . self::U . '/metrics?family=saturation&period=1h', $h->transport->requests()[0]->uri);
        self::assertSame('saturation', $metrics->family);
        self::assertSame(2, $metrics->oomKillsInWindow);
        self::assertNotSame([], $metrics->series);
        self::assertSame([[1798761600000, 1.5]], $metrics->series[0]->points);
        self::assertSame('2027-01-01T00:00:00+00:00', $metrics->window->from->format('c'));
    }

    // ── K1 (plan WT15-API-00006 §9.4): a write is any non-GET ───────────────────────────────────────────────────────────

    /**
     * A generated PUT and DELETE (a fixture table: the operation table holds none yet) send an Idempotency-Key, keep it across a retry after a 502
     * that was sent, and retry under IdempotentWrite because their rows say `idempotent: true`; the same for `request('PUT')` and
     * `request('DELETE')`; `request('PATCH')` sends no key and is not retried once sent.
     */
    public function testK1APutAndADeleteAreWritesLikeAPost(): void
    {
        foreach (['PUT', 'DELETE'] as $verb) {
            $h = new Harness();
            $row = ['method' => $verb, 'path' => '/things/{thing_uuid}', 'pathParams' => ['thing_uuid'], 'query' => [], 'body' => $verb === 'PUT' ? ['name'] : null, 'paged' => false, 'meta' => null, 'model' => null, 'status' => $verb === 'PUT' ? 200 : 204, 'idempotent' => true];
            $h->transport->append(MockTransport::problem(502, 'bad_gateway'), Fixture::response(['data' => null]));

            $run = new \ReflectionMethod(Operations::class, 'run');
            $property = new \ReflectionProperty($h->client, 'operations');
            $run->invoke($property->getValue($h->client), 'fixture.' . strtolower($verb), $row, ['thing_uuid' => self::U], $verb === 'PUT' ? ['name' => 'x'] : [], null);

            $requests = $h->transport->requests();
            self::assertCount(2, $requests, "$verb is retried after a 502 it was sent through");
            self::assertSame($verb, $requests[0]->method);
            self::assertNotNull($requests[0]->header('Idempotency-Key'), "$verb carries a key");
            self::assertSame($requests[0]->header('Idempotency-Key'), $requests[1]->header('Idempotency-Key'), "$verb keeps its key across the retry");
        }

        foreach (['PUT', 'DELETE'] as $verb) {
            $h = new Harness();
            $h->transport->append(MockTransport::problem(502, 'bad_gateway'), Fixture::response(['data' => null]));
            $h->client->request($verb, '/things/{thing_uuid}', ['thing_uuid' => self::U]);
            $requests = $h->transport->requests();
            self::assertCount(2, $requests, "request($verb) is retried as a write");
            self::assertSame($requests[0]->header('Idempotency-Key'), $requests[1]->header('Idempotency-Key'));
            self::assertNotNull($requests[0]->header('Idempotency-Key'));
        }

        // The caller's own key is taken by a PUT and a DELETE, and refused by a PATCH.
        $h = new Harness();
        $h->transport->append(Fixture::response(['data' => null]));
        $h->client->request('DELETE', '/things/{thing_uuid}', ['thing_uuid' => self::U], ['idempotency_key' => 'mine-1']);
        self::assertSame('mine-1', $h->transport->requests()[0]->header('Idempotency-Key'));

        $h = new Harness();
        $h->transport->append(new ApiConnectionException('slow', true, false, 'timeout'), Fixture::response(['data' => []]));
        try {
            $h->client->request('PATCH', '/things/{thing_uuid}', ['thing_uuid' => self::U]);
            self::fail('expected an ApiConnectionException');
        } catch (ApiConnectionException) {
            // expected: sent, so not retried
        }
        self::assertCount(1, $h->transport->requests());
        self::assertNull($h->transport->requests()[0]->header('Idempotency-Key'), 'a PATCH sends no key');
    }

    // ── K2: a 204 is a success only where the spec declares one ─────────────────────────────────────────────────────────

    /** @return array{method: string, path: string, pathParams: list<string>, query: list<string>, body: list<string>|null, paged: bool, meta: null, model: class-string<\WatchTower15\Api\Hydratable>|null, status: int, idempotent: bool} */
    private static function fixtureRow(string $method, int $status, ?string $model = null): array
    {
        return ['method' => $method, 'path' => '/things/{thing_uuid}', 'pathParams' => ['thing_uuid'], 'query' => [], 'body' => null, 'paged' => false, 'meta' => null, 'model' => $model, 'status' => $status, 'idempotent' => true];
    }

    public function testK2A204IsASuccessOnlyForAnOperationTheSpecDeclaresSo(): void
    {
        $operations = static fn (Harness $h): Operations => (new \ReflectionProperty($h->client, 'operations'))->getValue($h->client);
        $run = new \ReflectionMethod(Operations::class, 'run');
        $result = new \ReflectionMethod(Operations::class, 'result');
        $empty = static fn (int $status): \GuzzleHttp\Psr7\Response => new \GuzzleHttp\Psr7\Response($status);

        // An operation the spec declares 204 with no 2xx schema: its 204 answers null.
        $h = new Harness();
        $h->transport->append($empty(204));
        $row = self::fixtureRow('DELETE', 204);
        [$answer] = $run->invoke($operations($h), 'fixture.delete', $row, ['thing_uuid' => self::U], [], null);
        self::assertNull($result->invoke($operations($h), $row, $answer));
        self::assertSame(204, $h->client->lastResponse()?->statusCode);

        // An operation with a 2xx schema: an empty 200 is still an unexpected answer, never a null.
        $h = new Harness();
        $h->transport->append($empty(200));
        try {
            $run->invoke($operations($h), 'fixture.put', self::fixtureRow('PUT', 200, ServersListItem::class), ['thing_uuid' => self::U], [], null);
            self::fail('expected an UnexpectedResponseException');
        } catch (UnexpectedResponseException) {
            // expected
        }

        // And an operation that declares 204 does not take an empty 200 for its success.
        $h = new Harness();
        $h->transport->append($empty(200));
        try {
            $run->invoke($operations($h), 'fixture.delete', self::fixtureRow('DELETE', 204), ['thing_uuid' => self::U], [], null);
            self::fail('expected an UnexpectedResponseException');
        } catch (UnexpectedResponseException) {
            // expected
        }

        // request(): a 204 answers ['data' => null] for any method; an empty 200 is still unexpected.
        $h = new Harness();
        $h->transport->append($empty(204));
        self::assertSame(['data' => null], $h->client->request('DELETE', '/things/{thing_uuid}', ['thing_uuid' => self::U]));

        $h = new Harness();
        $h->transport->append($empty(204));
        self::assertSame(['data' => null], $h->client->request('POST', '/things/{thing_uuid}/archive', ['thing_uuid' => self::U]));

        $h = new Harness();
        $h->transport->append($empty(200));
        $this->expectException(UnexpectedResponseException::class);
        $h->client->request('GET', '/things/{thing_uuid}', ['thing_uuid' => self::U]);
    }

    // ── R13: where the idempotency key is refused ───────────────────────────────────────────────────────────────────

    public function testR13TheIdempotencyKeyIsOnlyTakenByAWrite(): void
    {
        $h = new Harness();

        $refusals = [
            'a generated GET' => static fn () => $h->client->servers->list([], new RequestOptions(idempotencyKey: 'k')),
            'me()' => static fn () => $h->client->me(['idempotency_key' => 'k']),
            'the metrics list' => static fn () => $h->client->ingest->metrics->list(['idempotency_key' => 'k']),
            'a metrics push' => static fn () => $h->client->ingest->metrics->push([['key' => 'x']], null, ['idempotency_key' => 'k']),
            'a logs push' => static fn () => $h->client->ingest->logs->push([['message' => 'x']], null, ['idempotency_key' => 'k']),
        ];
        foreach ($refusals as $what => $call) {
            try {
                $call();
                self::fail("$what must refuse an idempotency key");
            } catch (ConfigurationException $e) {
                self::assertStringContainsString('idempotency_key', $e->getMessage(), $what);
                if (str_contains($what, 'push')) {
                    self::assertStringContainsString('$idempotencyKey', $e->getMessage(), $what);
                }
            }
        }
        self::assertCount(0, $h->transport->requests());

        $h->transport->append(Fixture::answer('public.incidents.resolve'));
        $h->client->incidents->resolve(self::U, [], ['idempotency_key' => 'k']);
        self::assertSame('k', $h->transport->requests()[0]->header('Idempotency-Key'));
    }

    // ── R14: a time cursor ──────────────────────────────────────────────────────────────────────────────────────────

    public function testR14TheLogsTimeCursorIsSentBackAsGiven(): void
    {
        $h = new Harness();
        $page = static function (string $line, ?string $cursor): \GuzzleHttp\Psr7\Response {
            $body = Fixture::load('public.logs.search');
            $body['data'] = [['message' => $line] + $body['data'][0]];
            $body['next_cursor'] = $cursor;

            return Fixture::response($body);
        };
        $h->transport->append($page('first', '1798761600000:abc/def'), $page('second', null));

        $lines = array_map(static fn ($l): string => $l->message, iterator_to_array($h->client->logs->search(['period' => '1h'])->autoPaging(), false));

        self::assertSame(['first', 'second'], $lines);
        self::assertSame(self::BASE . '/logs?cursor=' . rawurlencode('1798761600000:abc/def') . '&period=1h', $h->transport->requests()[1]->uri);
    }
}
