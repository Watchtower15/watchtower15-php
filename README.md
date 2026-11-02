# watchtower15/sdk

The WatchTower15 PHP SDK: the framework-independent core. One `Client` with three credentials, a small HTTP layer, typed
errors, safe retries, and publishers for custom metrics and logs.

PHP `^8.3`, `ext-curl`, `ext-json`. `ext-zlib` is optional: with it the SDK gzips what it sends; without it the SDK sends
plain JSON.

This repository is a read-only split of the WatchTower15 PHP SDK: pull requests are not accepted. Documentation:
https://docs.watchtower15.com. Report a vulnerability to contact@watchtower15.com with the subject "Security".

## Install

```bash
composer require watchtower15/sdk
```

## The three credentials

A WatchTower15 call uses one of three credentials, each created in the console and each good for one job:

| Credential | Parameter | Variable | Used for |
|---|---|---|---|
| API key (`wt15ak_…`) | `apiKey` | `WT15_API_KEY` | `me()` and the `/v1` REST API. Personal: keep it out of shared code. |
| Metric source token (`wt15ms_…`) | `metricsToken` | `WT15_METRICS_TOKEN` | Pushing and listing custom metrics. |
| Log source token (`wt15ls_…`) | `logsToken` | `WT15_LOGS_TOKEN` | Pushing log lines. |

The SDK checks each credential's shape before anything is sent. A credential given in the wrong slot is named and refused
(`this is a metric source token (wt15ms_…a91f): pass it as metricsToken`), and a truncated paste never reaches a host.
Credentials are never kept in object properties, never serialised, never written to a log or an exception message; a dump of
a `Client` shows each as its prefix and last four characters only.

## Configure

```php
use WatchTower15\Client;

$wt15 = new Client(
    apiKey: 'wt15ak_…',
    metricsToken: 'wt15ms_…',
    logsToken: 'wt15ls_…',
);

// or from the environment: WT15_API_KEY, WT15_METRICS_TOKEN, WT15_LOGS_TOKEN, WT15_API_URL, WT15_INGEST_URL
$wt15 = Client::fromEnvironment();
$wt15 = Client::fromEnvironment(timeout: 5.0); // a named argument overrides
```

`fromEnvironment()` reads `$_SERVER`, then `$_ENV`, then `getenv()`, taking the first non-empty value. Laravel and Symfony fill
`$_SERVER` and `$_ENV` without `putenv()`, so all three are read.

| Option | Default | |
|---|---|---|
| `apiUrl` | `https://rest.watchtower15.com/v1` | `https` only; `http` for `localhost`, `127.0.0.1` and `[::1]` |
| `ingestUrl` | `https://api.watchtower15.com` | may carry a path prefix for a proxy |
| `timeout` | `15.0` | seconds, `0 < timeout ≤ 300` |
| `connectTimeout` | `2.0` | seconds, not above `timeout` |
| `maxRetries` | `2` | `0`–`10` |
| `httpClient` | cURL | an `Http\Transport` or any PSR-18 client |
| `logger` | none | a PSR-3 logger |
| `appInfo` | none | `name/version`, added to the `User-Agent` |

A call that needs a credential you did not give throws a `ConfigurationException` naming the parameter and its variable.

The per-call options `timeout`, `connect_timeout`, `max_retries` and `max_retry_wait` go in an array (or an
`Http\RequestOptions`) as the last argument of any call: `$wt15->me(['timeout' => 5.0])`.

## Who am I

```php
$me = $wt15->me();
echo $me->keyName, ' in ', $me->organisationName, ' (', implode(', ', $me->scopes), ')';
```

## Push metrics

```php
$result = $wt15->ingest->metrics->push([
    ['metric' => 'orders.completed', 'metric_type' => 'count', 'value' => 1],
    ['metric' => 'queue.depth', 'metric_type' => 'level', 'value' => 42, 'recorded_at' => new DateTimeImmutable()],
]);

$result->accepted;        // true when any chunk was accepted
$result->count;           // points stored
$result->rejected;        // every rejection reason, 0 where none
$result->rejectedTotal();
```

* Every point that names a `metric` carries `metric_type`, `count` or `level`. A point that addresses a metric by
  `metric_uuid` may leave it out.
* The SDK stamps no time. A point without `recorded_at` is stamped by the server on receipt.
* `push()` cuts the points into chunks of at most 1,000 points and 1,000,000 bytes, keeps their order, gzips each, and sends
  one `Idempotency-Key` per chunk, built before the first attempt and reused by every retry, so a retry is never stored twice.
  A single point too large for one request is refused before anything is sent (`Ingest\InvalidPayloadException`).
* **Keys.** A chunk holding a point older than 30 days (the backfill tier, where the server demands a key) gets a key derived
  from its content, so re-running the same import is stored once. Every other chunk gets a key with a per-call nonce:
  retries are safe, and two separate calls are never merged.
* **Import pattern.** A recent import that crashes and is re-run without a key can count twice. To re-run any import safely,
  pass a caller key and keep it on the re-run: `push($points, idempotencyKey: 'import/2026-10')`. One chunk uses the key
  unchanged; several use `<key>/<chunk index>`. A key is 1–100 printable ASCII characters. Never reuse a key for other points:
  the server drops them in silence (metrics) or answers 422 `idempotency_key_reused` (logs).
* `push([])` sends nothing and returns an empty result.

List the metrics of the token's source, their status and the cap:

```php
$listing = $wt15->ingest->metrics->list();
foreach ($listing->metrics as $metric) {
    echo $metric->key, ' ', $metric->type, ' ', $metric->status, "\n";
}
echo $listing->used, ' of ', $listing->maxMetrics, "\n";
```

## Push logs

```php
$wt15->ingest->logs->push([
    ['message' => 'payment failed', 'log_level' => 'error', 'stream_name' => 'checkout'],
    ['message' => 'retrying', 'logged_at' => new DateTimeImmutable()],
]);
```

`message` must not be blank. `message` and `raw_line` are cut at 65,536 bytes on a character boundary, as the server cuts
them. A stray invalid UTF-8 byte becomes U+FFFD. Lines are chunked (1,000 lines, 5,000,000 bytes), gzipped and keyed like
metrics, with the per-call nonce; pass a caller key to re-run a push safely. On a host that runs the WatchTower15 agent, the
agent's file tail is the better path for logs.

## Errors

Everything the SDK throws for a call implements `WatchTower15\Exception\WatchTower15Exception`.

| Exception | When |
|---|---|
| `AuthenticationException` | 401; and ingest's one 403 for every token problem |
| `PermissionException` | 403 on `/v1` |
| `NotFoundException` | 404 |
| `InvalidRequestException` | 400, and 422 other than a limit (`errors()`) |
| `LimitReachedException` | 422 `limit_reached` (`limit()`, `used()`) |
| `PaymentRequiredException` | 402 |
| `RateLimitException` | 429; `DailyLimitException` and `LogVolumeLimitException` (`resumesAt()`) extend it |
| `PayloadTooLargeException` | 413 |
| `ServiceUnavailableException`, `ServerException` | 503; other 5xx |
| `ApiConnectionException` | no answer: DNS, connect, TLS, a timeout, a body too large (`wasSent()`) |
| `UnexpectedResponseException` | an edge or proxy answered, a redirect, an empty 200 (`cfRay()`) |
| `ApiException` | any other 4xx; the base of the family |
| `ConfigurationException`, `Ingest\InvalidPayloadException` | a bad option, a credential in the wrong slot, a refused point or line |

An `ApiException` gives `getStatusCode()`, `getErrorCode()`, `getDetail()`, `getRequestId()`, `getRetryAfter()` and
`getProblem()`. When a push fails part way, `partialResult()` holds the chunks that were accepted.

## Retries

| Operation | Retried | Never retried |
|---|---|---|
| `me()`, `metrics->list()` | no answer, 408, 429, 502, 503, 504 | 500 and every other 4xx |
| `metrics->push()`, `logs->push()` | the same, and any 5xx, with the same bytes and key | every other 4xx; the log volume stop; a daily limit |

Up to `maxRetries` more attempts. A wait is the server's `Retry-After`; a throttled ingest push with none waits 60 s (the
throttle's window); any other wait is a random wait up to `min(8 s, 0.5 s × 2^(attempt−1))`. A wait longer than the call's
`max_retry_wait` (60 s by default) is not taken: the error is thrown at once.

## The last response

`$wt15->lastResponse()` returns the status, headers, request id and rate limit of the last exchange, a failed one included.
`rateLimit->resetAt` is null on ingest successes. A `Deprecation` or `Sunset` header is logged once per operation at
`warning`.

## Check a configuration

```bash
export WT15_API_KEY=… WT15_METRICS_TOKEN=… WT15_LOGS_TOKEN=…
vendor/bin/watchtower15 test
vendor/bin/watchtower15 test --reachability   # needs no credential
vendor/bin/watchtower15 test --json
vendor/bin/watchtower15 --version
```

`test` checks the API key with `me()`, the metrics token with `list()`, and the logs token by its format only (log ingest has
no read-only call, and a test push would store a line). It reports the clock skew against the answer's `Date`. Exit 0 when
every check passed, 1 when one failed or was not reached, 2 when nothing is configured or a variable is malformed. The
command reads only the process environment, never a framework's `.env` file: export the variables first.

## Public API

Every `/v1` operation is a method on a service named after its area: `$wt15->servers->list()`,
`$wt15->domains->runScan($domainUuid, 'ssl')`, `$wt15->incidents->acknowledge($incidentUuid)`. The method is the action of
the operation's id (`public.servers.systemServices` is `$wt15->servers->systemServices($serverUuid)`), the services are
`alarms`, `customMetrics`, `databases`, `domains`, `health`, `incidents`, `logs`, `scans`, `servers`, `services` and
`tags`, and `me()` stays on the client.

```php
$wt15 = WatchTower15\Client::fromEnvironment();

$page = $wt15->servers->list(['health' => ['critical', 'warning'], 'limit' => 50, 'include_total' => true]);
foreach ($page->data as $server) {
    echo $server->name, ' ', $server->healthStatus, ' last seen ', $server->agentLastSeenAt?->format('c'), "\n";
}
echo $page->total, ' servers; more: ', $page->hasMore() ? 'yes' : 'no';

$metrics = $wt15->servers->metrics($serverUuid, ['family' => 'cpu', 'period' => '1h']);
```

**Arguments.** The ids in the path come first, as strings. The filters are one `$params` array, whose keys your IDE and
PHPStan know (an array shape per method); `$params` is there even for an operation with no filter yet, so the filter the API
adds later changes no signature. The per-call `$options` come last, as for `me()`. A filter newer than the SDK is sent as
you give it.

**Models.** An answer is a readonly object with typed properties (`$server->uuid`, `$server->agentLastSeenAt`). A time is a
UTC `DateTimeImmutable`. A value that can be one of a set of words stays a string, so a word the API adds later never
breaks your code; beside it, an enum of the words known today (`WatchTower15\Api\Generated\Enums\ServersMetricsFamily`)
helps you compare. `toArray()` returns exactly what the server sent, fields newer than the SDK included.

**Paging.** A list is a `Page`: `data`, `nextCursor`, `meta` and `total` (only with `include_total`). `autoPaging()` walks
every item of every page and fetches the next page only when you reach it:

```php
foreach ($wt15->servers->list(['limit' => 100, 'sort' => 'name'])->autoPaging() as $server) {
    // ...
}
```

When a response says the rate limit is spent, `autoPaging()` waits for its reset if that is at most `max_retry_wait`
seconds away (60 unless you set it) and otherwise throws `RateLimitException` (`getRetryAfter()`), without sending a request
that could only earn a 429.

**Writes.** `acknowledge`, `resolve` and `runScan` send an `Idempotency-Key`, a random one unless you give your own
(`['idempotency_key' => 'ack-2026-11-01-42']`, 1 to 255 visible ASCII characters). A retry sends the same key, so a write
retries like a read, and an answer replayed from the first try (`$wt15->lastResponse()->replayed`) is a success.

**Waiting for a scan.** `runScan` answers at once with a scan that is `queued`:

```php
$scan = $wt15->domains->runScan($domainUuid, 'ssl');
$done = $wt15->scans->waitUntilFinished($scan->scanUuid, timeoutSeconds: 120, pollSeconds: 5);
echo $done->status;   // completed, failed or timeout; a status added later ends the wait too
```

Past the timeout it throws `WaiterTimeoutException`, whose `scan()` is the last scan read.

**An operation newer than this SDK.** `request()` calls it with the same key, retries, errors and `lastResponse()`:

```php
$envelope = $wt15->request('GET', '/servers/{server_uuid}/ports', ['server_uuid' => $serverUuid, 'limit' => 5]);
$ports = $envelope['data'];
```

`{name}` placeholders are filled from the array and URL-encoded, the rest goes to the query (GET, DELETE) or a JSON body.
A path that is not under `/v1`, a placeholder left unfilled or an unknown method is refused with `ConfigurationException`
before anything is sent.

**Errors.** Everything the SDK throws implements `WatchTower15Exception`; a refusal before sending (`ConfigurationException`:
a bad argument, option or path) and an answer the API gave (`ApiException` and its subclasses, by status) are separate
classes, so `catch (ApiException)` handles the API's answers and `catch (WatchTower15Exception)` handles everything.

**Test your own code.** `FakeClient` answers and records every method above, the waiter and `request()`, with no network.
Queue an answer by the operation's id, then assert on it. The fake never sleeps: the waiter's polls and the paging's waits run
on a clock that only moves forward.

```php
use WatchTower15\Testing\FakeClient;
use WatchTower15\Testing\MockTransport;

$fake = new FakeClient();
$fake->queue('public.servers.list', MockTransport::json(['data' => [$serverRow], 'next_cursor' => null]));

$names = (new Inventory($fake))->serverNames();   // your code, typed against Client

$fake->assertCalled('public.servers.list');
$fake->assertNotCalled('public.incidents.acknowledge');
```

A call with nothing queued for its operation throws a `LogicException` naming it. `queue('request:GET /servers/{server_uuid}/ports', ...)`
answers a `request()` call by the path as you wrote it.

## Test your own code

```php
use WatchTower15\Testing\FakeClient;

$fake = new FakeClient();      // a Client that never reaches a network
$service = new Reporter($fake);
$service->report();

$fake->assertPushed(fn (array $point) => $point['metric'] === 'orders.completed');
$fake->assertCalled('ingest.metrics.push', times: 1);
```

`FakeClient` answers pushes itself; `queue('public.me.view', $response)` answers other operations. `Testing\MockTransport`
queues responses and records requests for lower-level tests.

## Your own HTTP client

Pass any PSR-18 client as `httpClient`. The SDK then uses its timeouts and redirects: turn redirects off, or a credential
can follow a redirect. To implement `Http\Transport` yourself, mark `send()`'s `$request` parameter
`#[\SensitiveParameter]` and never chain an exception that holds the request (its `Authorization` header is a credential).

## Security

API keys are personal: keep them in the environment or a secrets store, never in code. Report a vulnerability to
contact@watchtower15.com with the subject "Security".

## What is not in this release

The metric aggregator, the log handler, the generated `/v1` surface and the framework bridges (Laravel, Symfony, CakePHP)
arrive in later releases. Everything under `Internal\` and every member marked `@internal` is outside semantic versioning.

MIT licence.
