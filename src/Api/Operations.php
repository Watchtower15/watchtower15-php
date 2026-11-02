<?php

declare(strict_types=1);

namespace WatchTower15\Api;

use WatchTower15\Api\Generated\OperationTable;
use WatchTower15\ConfigurationException;
use WatchTower15\Exception\RateLimitException;
use WatchTower15\Http\RequestOptions;
use WatchTower15\Http\ResponseInfo;
use WatchTower15\Http\RetryPolicy;
use WatchTower15\Internal\Answer;
use WatchTower15\Internal\ErrorMapper;
use WatchTower15\Internal\Hydrate;
use WatchTower15\Internal\Json;
use WatchTower15\Internal\Requirement;
use WatchTower15\Internal\Secrets;
use WatchTower15\Internal\SecretsHandle;
use WatchTower15\Internal\Sender;

/**
 * Runs an operation of the generated table (plan WT15-SDK-00007 D19): builds the request (D13, D14), sends it through the
 * same `Internal\Sender` as `me()` (auth, retries, errors, `User-Agent`, `lastResponse()`), and maps the answer to the
 * model, the `Page` or the raw array. `request()` runs through here with no table row (D16).
 *
 * @internal
 */
final class Operations
{
    private const REQUEST_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(
        private readonly Sender $sender,
        private readonly SecretsHandle $secrets,
        private readonly string $apiUrl,
    ) {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->sender->now();
    }

    public function sleep(float $seconds): void
    {
        $this->sender->sleep($seconds);
    }

    public function lastResponse(): ?ResponseInfo
    {
        return $this->sender->lastResponse();
    }

    /**
     * @param array<string, string> $pathValues
     * @param array<string, mixed> $params
     * @param RequestOptions|array<array-key, mixed>|null $options
     * @return object|array<string, mixed>
     */
    public function call(string $operation, array $pathValues, array $params, RequestOptions|array|null $options): object|array
    {
        $row = $this->row($operation);
        [$answer] = $this->run($operation, $row, $pathValues, $params, $options);
        $data = $answer->data();
        $model = $row['model'];

        return $model === null ? $data : $model::fromArray($data);
    }

    /**
     * @param array<string, string> $pathValues
     * @param array<string, mixed> $params
     * @param RequestOptions|array<array-key, mixed>|null $options
     * @return Page<object|array<string, mixed>>
     */
    public function page(string $operation, array $pathValues, array $params, RequestOptions|array|null $options): Page
    {
        $row = $this->row($operation);
        [$answer, $resolved] = $this->run($operation, $row, $pathValues, $params, $options);
        $body = $answer->body;
        $model = $row['model'];
        $items = [];
        foreach (is_array($body['data'] ?? null) ? $body['data'] : [] as $item) {
            $item = Hydrate::array($item);
            $items[] = $model === null ? $item : $model::fromArray($item);
        }
        $metaClass = $row['meta'];
        $meta = $metaClass !== null && is_array($body['meta'] ?? null) ? $metaClass::fromArray(Hydrate::array($body['meta'])) : null;
        $cursor = $body['next_cursor'] ?? null;
        $info = $this->sender->lastResponse();

        return new Page(
            $items,
            is_string($cursor) && $cursor !== '' ? $cursor : null,
            $meta,
            is_int($body['total'] ?? null) ? $body['total'] : null,
            $info,
            function (string $next) use ($operation, $pathValues, $params, $resolved): Page {
                $again = $params;
                $again['cursor'] = $next;

                return $this->page($operation, $pathValues, $again, $resolved);
            },
            function () use ($operation, $info, $resolved): void {
                $this->pace($operation, $info, $resolved);
            },
        );
    }

    /**
     * `Client::request()` (D16): an operation newer than the installed SDK, through the same auth, retries and errors.
     *
     * @param array<string, mixed> $params
     * @param RequestOptions|array<array-key, mixed>|null $options
     * @return array<string, mixed> the decoded envelope
     */
    public function request(string $method, string $path, array $params, RequestOptions|array|null $options): array
    {
        $verb = strtoupper($method);
        if (! in_array($verb, self::REQUEST_METHODS, true)) {
            throw new ConfigurationException(sprintf('`$method` must be one of %s, not %s.', implode(', ', self::REQUEST_METHODS), json_encode($method)));
        }
        if (
            $path === '' || $path[0] !== '/' || str_contains($path, '://') || str_contains($path, '..') || str_contains($path, '?') || str_contains($path, '#')
            || stripos($path, '%2e') !== false || stripos($path, '%2f') !== false || preg_match('/[\x00-\x1f\x7f]/', $path) === 1
        ) {
            throw new ConfigurationException('`$path` must be a path under /v1 that starts with "/" and holds no scheme, "..", "?", "#", encoded dots or slashes, or control characters.');
        }
        Requirement::credential($this->secrets, Secrets::API);
        $resolved = RequestOptions::from($options);

        $filled = preg_replace_callback('/\{([A-Za-z0-9_]+)\}/', static function (array $m) use (&$params): string {
            $name = $m[1];
            $value = $params[$name] ?? null;
            if (! (is_string($value) || is_int($value)) || (string) $value === '') {
                throw new ConfigurationException(sprintf('`$path` has the placeholder {%s}, which has no value in `$params[\'%s\']`.', $name, $name));
            }
            unset($params[$name]);

            return rawurlencode((string) $value);
        }, $path);
        if ($filled === null || str_contains($filled, '{') || str_contains($filled, '}')) {
            throw new ConfigurationException('`$path` has a placeholder that cannot be filled.');
        }

        $operation = 'request:' . $verb . ' ' . $path;
        $headers = [];
        if ($verb === 'POST') {
            $headers['Idempotency-Key'] = $resolved->idempotencyKey ?? self::uuid();
        } else {
            $resolved->refuseIdempotencyKey();
        }
        $policy = match ($verb) {
            'GET' => RetryPolicy::Read,
            'POST' => RetryPolicy::IdempotentWrite,
            default => RetryPolicy::ConnectOnly,
        };
        $url = $this->apiUrl . $filled;
        $body = null;
        if ($verb === 'GET' || $verb === 'DELETE') {
            $query = self::query($params);
            $url .= $query === '' ? '' : '?' . $query;
        } elseif ($params !== []) {
            $body = Json::encode($params);
            $headers['Content-Type'] = 'application/json';
        }

        return $this->sender->execute(ErrorMapper::V1, Secrets::API, $operation, $verb, $url, $policy, $body, $headers, $resolved)->body;
    }

    // ── internals ───────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @return array{method: string, path: string, pathParams: list<string>, query: list<string>, body: list<string>|null, paged: bool, meta: class-string<Hydratable>|null, model: class-string<Hydratable>|null, status: int, idempotent: bool}
     */
    private function row(string $operation): array
    {
        return OperationTable::OPERATIONS[$operation] ?? throw new \LogicException(sprintf('The operation %s is not in the operation table.', $operation));
    }

    /**
     * @param array{method: string, path: string, pathParams: list<string>, query: list<string>, body: list<string>|null, paged: bool, meta: class-string<Hydratable>|null, model: class-string<Hydratable>|null, status: int, idempotent: bool} $row
     * @param array<string, string> $pathValues
     * @param array<string, mixed> $params
     * @param RequestOptions|array<array-key, mixed>|null $options
     * @return array{0: Answer, 1: RequestOptions}
     */
    private function run(string $operation, array $row, array $pathValues, array $params, RequestOptions|array|null $options): array
    {
        Requirement::credential($this->secrets, Secrets::API);
        $resolved = RequestOptions::from($options);
        $write = $row['method'] === 'POST';
        if (! $write) {
            $resolved->refuseIdempotencyKey();
        }

        $path = $row['path'];
        foreach ($row['pathParams'] as $name) {
            $value = $pathValues[$name] ?? '';
            if ($value === '') {
                throw new ConfigurationException(sprintf('The path argument `%s` of %s must not be empty.', $name, $operation));
            }
            $path = str_replace('{' . $name . '}', rawurlencode($value), $path);
        }

        $bodyParams = [];
        $queryParams = $params;
        if ($row['body'] !== null) {
            foreach ($row['body'] as $name) {
                if (array_key_exists($name, $queryParams)) {
                    $bodyParams[$name] = $queryParams[$name];
                    unset($queryParams[$name]);
                }
            }
        }

        $headers = [];
        if ($write) {
            $headers['Idempotency-Key'] = $resolved->idempotencyKey ?? self::uuid();
        }
        $body = null;
        if ($row['body'] !== null) {
            $body = Json::encode($bodyParams === [] ? new \stdClass() : $bodyParams);
            $headers['Content-Type'] = 'application/json';
        }
        $query = self::query($queryParams);
        $url = $this->apiUrl . $path . ($query === '' ? '' : '?' . $query);
        $policy = match (true) {
            ! $write => RetryPolicy::Read,
            $row['idempotent'] => RetryPolicy::IdempotentWrite,
            default => RetryPolicy::ConnectOnly,
        };

        return [$this->sender->execute(ErrorMapper::V1, Secrets::API, $operation, $row['method'], $url, $policy, $body, $headers, $resolved), $resolved];
    }

    /**
     * D12's pacing, before the next page is fetched: when the last answer left no request, wait for the reset if it is at
     * most `max_retry_wait` away, otherwise throw without sending (a call that could only earn a 429).
     */
    private function pace(string $operation, ?ResponseInfo $info, RequestOptions $options): void
    {
        $limit = $info?->rateLimit;
        if ($info === null || $limit === null || $limit->remaining > 0 || $limit->resetAt === null) {
            return;
        }
        $wait = (float) ($limit->resetAt->getTimestamp() - $this->sender->now()->getTimestamp());
        if ($wait <= 0) {
            return;
        }
        $cap = $options->maxRetryWait ?? Sender::MAX_RATE_LIMIT_WAIT_SECONDS;
        if ($wait <= $cap) {
            $this->sender->sleep($wait);

            return;
        }

        throw new RateLimitException(
            sprintf('The rate limit is spent for another %d seconds, longer than the %d the call waits; the next page was not requested.', (int) $wait, (int) $cap),
            429,
            'rate_limited',
            null,
            $info->requestId,
            [],
            $info->headers,
            $operation,
            (int) ceil($wait),
        );
    }

    /**
     * D14: a list goes in as `<name>[]=<v>` once per value, a boolean as `1` or `0`, a null key is left out, an instant as epoch ms.
     *
     * @param array<string, mixed> $params
     */
    private static function query(array $params): string
    {
        ksort($params);
        $parts = [];
        foreach ($params as $name => $value) {
            if ($value === null) {
                continue;
            }
            $key = rawurlencode((string) $name);
            if (is_array($value)) {
                foreach ($value as $item) {
                    if ($item !== null) {
                        $parts[] = $key . '[]=' . self::scalar((string) $name, $item);
                    }
                }

                continue;
            }
            $parts[] = $key . '=' . self::scalar((string) $name, $value);
        }

        return implode('&', $parts);
    }

    private static function scalar(string $name, mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_float($value) => (string) $value,
            is_string($value) => rawurlencode($value),
            $value instanceof \DateTimeInterface => (string) ((int) $value->format('U') * 1000 + (int) $value->format('v')),
            $value instanceof \BackedEnum => rawurlencode((string) $value->value),
            default => throw new ConfigurationException(sprintf('The parameter `%s` must be a string, a number, a boolean, a date or a list of them.', $name)),
        };
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }
}
