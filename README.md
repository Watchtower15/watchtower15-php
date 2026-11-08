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

## Support policy

- **PHP.** Every PHP minor from 8.3 is supported until its own security support ends (php.net's dates). Dropping one is a
  minor release, announced in the release before it. A new PHP minor joins the tested matrix by its general availability
  (PHP 8.6: 19 November 2026).
- **Frameworks.** Laravel 12 and 13; Symfony 6.4, 7.4 and 8.x (8.x on PHP 8.4 and later); CakePHP 5.1 and later. A
  framework version is dropped in the first minor release after its own security support ends.
- **Versions.** [Semantic Versioning](https://semver.org), independent of the API's `v1`. A new API operation is additive and ships in the
  next release; raising the PHP floor is a minor release announced one release ahead; a change to the SDK's own signatures,
  array shapes or property types is a major release. All four packages are released together, at one version.
- **Tested.** Every release is tested on these corners:

| Package | PHP 8.3 (lowest dependencies) | PHP 8.4 | PHP 8.5 |
|---|---|---|---|
| `watchtower15/sdk` | yes | not a separate corner | yes |
| `watchtower15/laravel` | Laravel 12 | not a separate corner | Laravel 13 |
| `watchtower15/symfony` | Symfony 6.4 and 7.4 | Symfony 8 | Symfony 8 |
| `watchtower15/cakephp` | CakePHP 5.1 | not a separate corner | CakePHP 5.4 |

- **Security fixes** ship in the newest minor release as a patch release at once (Composer blocks the install of a version
  with a published advisory). Report a vulnerability to the address in `SECURITY.md`.

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

## Metrics aggregator

For counts and levels on the request path, without a request on the request path. `increment()` and `gauge()` write into a
minute bucket in a store every process shares, in microseconds, and **never throw and never send**; a flush, once a minute,
sends one request per closed bucket.

```php
use WatchTower15\Client;
use WatchTower15\Lifecycle\PhpShutdownStrategy;
use WatchTower15\Metrics\Aggregator;
use WatchTower15\Store\RedisStore;

$redis = new Redis();
$redis->connect('127.0.0.1');

$metrics = new Aggregator(
    client: Client::fromEnvironment(),
    store: new RedisStore($redis, prefix: 'myapp'),          // one prefix per application, no default
    allowList: ['orders.shipped' => 'count', 'queue.depth' => 'level'],
    heartbeat: 'myapp.flushes',
);
$metrics->registerShutdown(new PhpShutdownStrategy());       // or flush from cron, below

$metrics->increment('orders.shipped');
$metrics->gauge('queue.depth', 812);
```

* A count is the sum of its bucket; a level is the last value written in it. Every point carries `metric_type` and the bucket's
  start as `recorded_at`. A key written both ways in one bucket sends the count and drops the level.
* A bucket is **closed** 5 seconds after its minute ends. A flush sends the closed buckets, oldest first, within `maxRequests`
  (10) requests and 90 seconds a run, one request per chunk of 1,000 points, with an `Idempotency-Key` frozen per bucket and
  chunk: a resend after a timed-out success is stored once. There is no retry inside a run; the next run resends what was kept.
* **Every outcome is named.** A refused token (401, 403), a redirect, a transport error, a throttle or a 5xx keeps the bucket and
  stops the run; any other 4xx drops that chunk and goes on. The next run resends what was kept, for up to `maxBuckets`
  (60) minutes; older buckets are dropped and counted.
* **Never throws, counts instead.** The counters (`invalid_key`, `invalid_value`, `negative_count`, `not_allowed`, `type_conflict`,
  `late_write`, `dropped_age`, `dropped_rejected`, `auth`, `config`, `transport`, `redirect`, `internal`, `store`) are kept in the
  store and read by the next flush, which logs one warning (counts and reasons only, never a value or a token) when anything is
  not zero. `late_write` means a write finished after its bucket's grace: the count is either lost (its bucket was already
  flushed) or its bucket is stored twice (an earlier attempt landed with its answer lost). When the store itself is down, the
  count is kept in the process only: under PHP-FPM it ends with the request, so a store that stays down shows only as the
  heartbeat's silence.
* **The heartbeat** adds 1 to a count key in every flush, so every flush sends something and a stopped pipe shows as silence an
  alarm can see.
* **The allow-list** keeps a typo from minting a billable metric: a key not in it, or used by the other method than its type,
  is dropped and counted. It is off by default in the core.
* **The token** is the same `metricsToken` as for `push()`: a string, or a `Metrics\TokenProvider` (for example
  `new CallableTokenProvider(fn () => $vault->get('wt15-metrics'))`) that is called only when something is about to be sent, never
  at construction, kept for at most 300 seconds, and forgotten when the token is refused.
* `new Aggregator(..., enabled: false)` stores nothing, sends nothing and makes the flush a no-op that says so.

### Stores and their scope

| Store | Scope | Use it for |
|---|---|---|
| `Store\RedisStore` (phpredis or Predis) | the fleet | PHP-FPM on several servers. A single Redis or Valkey primary; **Redis Cluster is not supported**. A prefix is required: two applications on one Redis database must each name their own. |
| `Store\ApcuStore` | one host, one SAPI | One PHP-FPM server. **APCu is not shared between PHP-FPM and the CLI**: a cron flush would read an empty store, so the CLI flush refuses it. A prefix is required: two PHP-FPM pools under one master share one APCu segment. |
| `Store\FileStore` | one host | Bare PHP with neither: one JSON file per bucket under `flock` in a directory the web user and the cron user can both write (mode 0770, created at the first write). Local filesystems only, not NFS. |
| `Store\InMemoryStore` | one process | Long-running workers, CLI scripts and tests. |

### When to flush

| Runtime | Store | Flush |
|---|---|---|
| PHP-FPM, several servers | `RedisStore` | cron `vendor/bin/watchtower15 flush` on one server, or `registerShutdown(new PhpShutdownStrategy())` on all (once a minute for the fleet) |
| PHP-FPM, one server | `RedisStore` or `FileStore` (cron), or `ApcuStore` (`registerShutdown()` only) | as above; with APCu a minute with no request flushes at the next request |
| Apache mod_php, or any SAPI with neither `fastcgi_finish_request()` nor `litespeed_finish_request()` | `RedisStore` or `FileStore` | cron: an after-response flush there holds the response until it ends |
| Long-running worker | `InMemoryStore` or a shared one | `flushIfDue()` in the loop; `registerShutdown()` for the final flush |
| CLI script, cron job | `InMemoryStore` | `registerShutdown()`: a final flush at exit; or `push()` |
| Serverless | none | `push()` at the end of the invocation: minute buckets do not fit a frozen process |

```bash
# crontab: a bootstrap file that returns the Aggregator
* * * * * cd /app && vendor/bin/watchtower15 flush --bootstrap=config/wt15-metrics.php
```

The bootstrap path may also come from `WT15_METRICS_BOOTSTRAP`. `flush` exits 0 for a clean run, a disabled aggregator, another
flush running, or a run that stopped at its request cap; 1 for any other stop reason; and 2 when it cannot start: no bootstrap, a
file that does not exist, throws, or returns anything but an `Aggregator`, or a store the CLI cannot see (in-memory, APCu).
`--json` prints the `FlushReport`.

### Things to know about the after-response flush

* `fastcgi_finish_request()` (and LiteSpeed's `litespeed_finish_request()`) ends the response but not the request, so an open PHP
  session stays locked during the flush.
* Under a SAPI with neither function (Apache's mod_php among them) an after-response flush holds that response until it ends: flush
  from cron there.
* `PhpShutdownStrategy` flushes after every other shutdown function, the application's own later ones included; a shutdown
  function that calls `exit()` stops PHP's queue, the flush with it.
* `registerShutdown()` on an `InMemoryStore` under `fpm-fcgi` sends one request per page view.
* Under PHP-FPM a pool's `request_terminate_timeout` must be above the flush's worst case (`maxRequests × (timeout + connectTimeout)`,
  40 s at the defaults; up to about 100 s when one bucket holds more chunks than `maxRequests`), or the worker is killed in the
  middle of an after-response flush: the buckets are kept, but a `RedisStore` or `ApcuStore` lock stays held for its 120 s, so the
  next minute reports `locked`. Set it above that, or flush from cron.
* An aggregator whose `Client` uses a PSR-18 client (`Psr18Transport`) must set a timeout of at most 10 s on that client, or use a
  `Client` of its own on the default transport; a slow ingest would otherwise outlive the flush's 120 s lock.
* Hosts that share a `RedisStore` need clocks within the 5-second grace (NTP): a host whose clock runs behind can write into a
  bucket a faster host has already flushed, and the count is lost with no `late_write`.
* A metric token rotated between an attempt whose answer was lost and its resend changes that bucket's key (the token's hash is in
  it), so the server stores the bucket twice.
* The per-metric budget of 100,000 points a day (about 69 a minute) caps how many per-host stores can push one key.

### Test your own code

`Testing\FakeAggregator` is built with no arguments, records `increment()` and `gauge()`, and its `flush()` pushes every bucket, the
open one included, into its own `FakeClient`: `assertIncremented('orders.shipped', 3)`, `assertNotIncremented()`,
`assertGaugeSet()`, `assertNothingRecorded()`, `client()`.

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
| `me()`, `metrics->list()`, every other read | no answer, 408, 429, 502, 503, 504 | 500 and every other 4xx |
| every `/v1` write (POST, PUT, DELETE), `request()` for them | the same, and any 5xx, with the same `Idempotency-Key` | every other 4xx |
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
`alarms`, `customMetrics`, `databases`, `domains`, `health`, `incidents`, `logSources`, `logs`, `metricSources`, `scans`,
`serverProfiles`, `servers`, `services`, `tagGroups` and `tags`, and `me()` stays on the client.

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

**Writes.** A write is a POST, a PUT or a DELETE. Each sends an `Idempotency-Key`, a random one unless you give your own
(`['idempotency_key' => 'ack-2026-11-01-42']`, 1 to 255 visible ASCII characters). A retry sends the same key, so a write
retries like a read, and an answer replayed from the first try (`$wt15->lastResponse()->replayed`) is a success. A delete
answers `204 No Content`: its method returns `null`, and `request()` answers `['data' => null]`. A PATCH is refused by the
API, so `request('PATCH', …)` sends no key and is not retried once it was sent.

**A write on a monitored resource.** The same rules cover the writes on servers, domains, services, databases, custom metrics,
metric and log sources, server profiles and tags: the ids first, the body as the `$params` array, the object it changed as the
answer.

```php
$group = $wt15->tagGroups->create(['name' => 'environment']);
$tag = $wt15->tags->create(['tag_group_uuid' => $group->uuid, 'name' => 'production']);
$wt15->tags->update($tag->uuid, ['name' => 'prod']);
$wt15->tags->delete($tag->uuid);
```

A write returns the operation's own model, with the view's fields: `servers->provision()` a `ServersProvision`, `services->runNow()` a
`ServicesRunNow` beside `ServicesView`, and so on, so a model's name is the operation's, not the area's.

A secret is shown once, in the answer of the call that made it: the enrollment token of `servers->provision()` and
`servers->rotateToken()`, and the token of `metricSources->rotateToken()` and `logSources->rotateToken()`. A call that is
replayed from its first try (your own repeat with the same key, or the SDK's own retry after a lost answer) answers the same
object with that token `null`, because the API keeps no secret. A null token on a rotation or a provision therefore means
"rotate again": check it and call the method again, with a new key, to get a token you can use.

```php
$server = $wt15->servers->rotateToken($serverUuid);
if ($server->enrollmentToken === null) {
    $server = $wt15->servers->rotateToken($serverUuid, [], ['idempotency_key' => bin2hex(random_bytes(16))]);
}
```

**Waiting for a scan.** `runScan` answers at once with a scan that is `queued`:

```php
$scan = $wt15->domains->runScan($domainUuid, 'ssl');
$done = $wt15->scans->waitUntilFinished($scan->scanUuid, timeoutSeconds: 120, pollSeconds: 5);
echo $done->status;   // completed, failed or timeout; a status added later ends the wait too
```

Past the timeout it throws `WaiterTimeoutException`, whose `scan()` is the last scan read.

**An operation newer than this SDK.** `request()` calls it with the same key (a POST, PUT or DELETE), retries, errors and `lastResponse()`:

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

## Logs

The SDK sends application logs to WatchTower15 log ingest through the logging you already have. On a host that runs the WatchTower15
agent, **the agent's file tail is the better path**: no token in the application and no HTTP from PHP. Use this where it does not run.

### With Monolog 3

```php
use Monolog\Handler\BufferHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use WatchTower15\Client;
use WatchTower15\Logs\Monolog\WatchTower15Handler;

$wt15 = Client::fromEnvironment();                 // WT15_LOGS_TOKEN
$log = new Logger('billing');
$log->pushProcessor(new PsrLogMessageProcessor());  // {placeholders} replaced, as Laravel's channels do
$log->pushHandler(new BufferHandler(
    new WatchTower15Handler($wt15, selfLogger: $otherLogger),   // a logger that does not hold this handler
    100,
    flushOnOverflow: true,
));
```

`composer require monolog/monolog` yourself: Monolog 3 is a suggestion of this package, not a requirement, and the handler's
signatures name Monolog 3's `LogRecord`. For a Monolog 2 application, use Monolog's own `PsrHandler` over the `Psr3Logger` below.

* One line per record: `message` is the record formatted by the handler's formatter (by default `%message% %context% %extra%`, with
  the exception's trace), `log_level` the record's level unchanged (Monolog's eight levels are ingest's eight), `logged_at` the
  record's time, `stream_name` the handler's `stream` option or else the record's channel. A stream name that does not match ingest's
  pattern (`^[A-Za-z0-9._/@:+-]{1,255}$`) is left out, so the source's own name applies. A blank message is dropped.
* **Wrap the handler in `BufferHandler`**, so a request's lines go in one push. The handler's own level and its processors hold on
  that batch path. Unwrapped, it sends each record as it is logged.
* The push waits for the response to end: `fastcgi_finish_request()` (PHP-FPM, FrankenPHP) or `litespeed_finish_request()` ends it
  first. Under mod_php neither exists and the push holds the response for its round trip, up to about 8 s when ingest fails: use the
  agent's file tail there. (The response ends, the request does not: an open PHP session stays locked during the push.)
* Two runtimes have no end of request to wait for. On **serverless** (AWS Lambda through Bref, for one) the platform may freeze the
  environment once the invocation has answered, so flush before it answers (`BufferHandler::close()` or the `Psr3Logger`'s `flush()`);
  the push then counts in the invocation's time. In a **long-running loop** (RoadRunner, FrankenPHP's worker mode, a consumer script)
  shutdown functions run only at exit: call `flush()` after each unit of work.

### Without Monolog

```php
use WatchTower15\Logs\Psr3Logger;

$logger = new Psr3Logger($wt15, stream: 'billing', selfLogger: $otherLogger, bufferLimit: 100);
$logger->error('Payment failed for {order}', ['order' => 42, 'exception' => $e]);
```

A PSR-3 logger that buffers lines and sends them at `bufferLimit` lines, on `flush()`, at the end of the request after the response and
on destruct. A message has its `{placeholders}` interpolated from scalar and `Stringable` context values, then the context as one JSON
object after a space (values JSON cannot encode become `[resource]`, `[object <class>]` or `0`, and an invalid byte U+FFFD), then an
`exception` in the context with its trace. An unknown level throws PSR-3's `InvalidArgumentException`; nothing else throws.

### Bounds, the stop and the hold

Every push passes per-call options: a 3 s timeout, a 1 s connect timeout, one retry and no wait above 2 s. They hold on the SDK's default
cURL transport and the framework bridges' own; on `Psr18Transport` the timeouts are your PSR-18 client's, so give it a timeout of at most
3 s and a connect timeout of at most 1 s (or hand the handler a `Client` of its own on the default transport). A retried push is stored
and billed once: it carries the same `Idempotency-Key`.

A failing ingest must not tie up your workers, so a **per-host breaker** remembers it:

* The **volume stop** (the organisation reached its log volume limit) lasts the answer's `Retry-After` (at most an hour), and is
  re-checked hourly.
* A **hold** of a minute follows an unreachable ingest, a 5xx, a 403 (a revoked token) or the per-token throttle (for its
  `Retry-After`).
* While either is open the lines are dropped without a request. When it ends, **one** push probes ingest while the host's other
  sends still wait.

The breaker lives in files (`wt15-logs-<sha256 of the token>.breaker`, mode 0644) under the system temporary directory, shared by
every PHP-FPM worker of the host. **Every user that runs the application (PHP-FPM, cron, a deploy user) must be able to write that
directory**: a file another user wrote in the sticky temporary directory can be read but neither replaced nor deleted, and that user's
holds are never written. Give all of them one directory, or pass each a `FileBreakerStore($directory)`. Use **one directory per
application**: applications that share one share the report times of `config` and `missing_token`, so one's hourly report silences
another's. `InMemoryBreakerStore` serves one process and tests.

### Failures are counted, never thrown

Nothing reaches your application and nothing is logged through the handler itself. Lines that are not sent are counted by reason
(`config`, `missing_token`, `invalid_payload`, `volume_limit`, `rate_limited`, `held`, `connection`, `rejected_<status>`,
`internal`), and **at most one report per flush** goes to the `selfLogger` at `warning`:

```
WatchTower15 logs: lines not sent: rejected_503 12, invalid_payload 1
```

with `wt15_report` `true`, `wt15_lines` (each reason with its lines) and, when the flush opened a stop or a hold, `wt15_until` (RFC 3339
UTC with milliseconds) in its context. Alert on those. A standing condition (`missing_token`, `config`, `held`, a stop that is open,
a wrong ingest URL) is reported at most once an hour per host. The `selfLogger` must **never** be a logger that holds this handler;
a `Client` whose `logger` routes to the handler would ship the SDK's own retry notices as lines (records that carry `wt15_level` or
`wt15_report` are dropped, but only for loggers the SDK knows).

### The ingest limit

Log ingest takes 600 pushes a minute per token, and the SDK sends one push per request, job or command that logs at or above the
channel's level (more past `bufferLimit`), from every host on that token. Above about ten logging requests a second per token, lines
are lost: raise the channel's level, use a token per service, or use the agent's file tail. Keep secrets out of log context.

## Security

API keys are personal: keep them in the environment or a secrets store, never in code. Report a vulnerability to
contact@watchtower15.com with the subject "Security".

## What is not in this release

The framework bridges for Symfony and CakePHP arrive in later releases. Everything under `Internal\` and every member marked `@internal` is outside semantic versioning.

MIT licence.
