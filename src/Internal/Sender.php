<?php

declare(strict_types=1);

namespace WatchTower15\Internal;

use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use WatchTower15\ConfigurationException;
use WatchTower15\Exception\ApiConnectionException;
use WatchTower15\Exception\ApiException;
use WatchTower15\Exception\AuthenticationException;
use WatchTower15\Exception\UnexpectedResponseException;
use WatchTower15\Http\RequestOptions;
use WatchTower15\Http\ResponseInfo;
use WatchTower15\Http\RetryPolicy;
use WatchTower15\Http\Transport;
use WatchTower15\Http\TransportOptions;
use WatchTower15\Package;

/**
 * Runs one operation through the retries and the error mapper. It is the only place that sets `Authorization`.
 *
 * @internal
 */
final class Sender
{
    /** The per-minute window of the ingest throttles, waited when a 429 carries no `Retry-After`. */
    public const INGEST_THROTTLE_WAIT_SECONDS = 60.0;

    /** The default cap on a single wait. */
    public const MAX_RATE_LIMIT_WAIT_SECONDS = 60.0;

    private ?ResponseInfo $last = null;

    private ?string $framework = null;

    private ?string $userAgent = null;

    /** @var (\Closure(string): void)|null */
    private ?\Closure $observer = null;

    public function __construct(
        private readonly SecretsHandle $secrets,
        private readonly Transport $transport,
        private readonly LoggerInterface $logger,
        private Clock $clock,
        private Sleeper $sleeper,
        private readonly float $timeout,
        private readonly float $connectTimeout,
        private readonly int $maxRetries,
        private readonly ?string $appInfo,
    ) {
    }

    public function setSeams(Clock $clock, Sleeper $sleeper): void
    {
        $this->clock = $clock;
        $this->sleeper = $sleeper;
    }

    public function now(): \DateTimeImmutable
    {
        return $this->clock->now();
    }

    /**
     * `sha256` of the credential, resolving a token provider when there is one.
     *
     * @throws ConfigurationException when the credential is not configured or a provider returns a bad token
     */
    public function tokenHash(string $kind): string
    {
        Requirement::credential($this->secrets, $kind);

        return (string) Secrets::tokenHash($this->secrets, $kind, $this->clock->now()->getTimestamp());
    }

    /**
     * `sha256` of the credential, or null when none is configured; never throws for a missing credential.
     */
    public function tokenHashOrNull(string $kind): ?string
    {
        return Secrets::tokenHash($this->secrets, $kind, $this->clock->now()->getTimestamp());
    }

    /** Waits through the client's sleeper (a fake one in tests never waits). */
    public function sleep(float $seconds): void
    {
        $this->sleeper->sleep($seconds);
    }

    /**
     * Called with the operation name just before each request is handed to the transport (each retry included), so a test
     * double can tell which operation a request belongs to.
     *
     * @param (\Closure(string): void)|null $observer
     */
    public function observeOperations(?\Closure $observer): void
    {
        $this->observer = $observer;
    }

    public function lastResponse(): ?ResponseInfo
    {
        return $this->last;
    }

    public function setFramework(string $name, string $version): void
    {
        if ($this->framework !== null) {
            throw new \LogicException('The framework is already set.');
        }
        if (preg_match('/\A[a-z0-9-]+\z/', $name) !== 1 || preg_match('/\A[0-9A-Za-z.+-]+\z/', $version) !== 1) {
            throw new \InvalidArgumentException('The framework name or version has an invalid form.');
        }
        $this->framework = $name . '/' . $version;
        $this->userAgent = null;
    }

    public function userAgent(): string
    {
        return $this->userAgent ??= UserAgent::build(
            Package::version(),
            PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            $this->framework,
            $this->appInfo,
        );
    }

    /**
     * @param string               $api        `ErrorMapper::V1` or `ErrorMapper::INGEST`.
     * @param string               $credential `Secrets::API`, `METRICS` or `LOGS`.
     * @param array<string, string> $headers
     */
    public function execute(
        string $api,
        string $credential,
        string $operation,
        string $method,
        string $url,
        RetryPolicy $policy,
        #[\SensitiveParameter] ?string $body = null,
        array $headers = [],
        ?RequestOptions $options = null,
    ): Answer {
        $options ??= new RequestOptions();
        $timeout = $options->timeout ?? $this->timeout;
        $connectTimeout = $options->connectTimeout ?? $this->connectTimeout;
        if ($connectTimeout > $timeout) {
            throw new ConfigurationException('The request option `connectTimeout` must not exceed the timeout that applies.');
        }
        $maxRetries = $options->maxRetries ?? $this->maxRetries;
        $maxWait = $options->maxRetryWait ?? self::MAX_RATE_LIMIT_WAIT_SECONDS;
        $transportOptions = new TransportOptions($timeout, $connectTimeout);

        $token = Secrets::get($this->secrets, $credential, $this->clock->now()->getTimestamp());
        if ($token === null) {
            throw new ConfigurationException('No credential is configured for ' . $operation . '.');
        }
        $request = $this->buildRequest($method, $url, $token, $body, $headers);

        $attempt = 0;
        while (true) {
            $attempt++;
            if ($this->observer !== null) {
                ($this->observer)($operation);
            }
            try {
                $response = $this->transport->send($request, $transportOptions);
            } catch (ApiConnectionException $e) {
                $e = $e->forOperation($operation);
                if ($attempt <= $maxRetries && $this->retryConnection($policy, $e)) {
                    $wait = $this->jitter($attempt);
                    $this->logRetry($operation, 'connection: ' . $e->reason(), $attempt, $wait);
                    $this->sleeper->sleep($wait);

                    continue;
                }

                throw $e;
            }

            $info = ResponseInfo::fromResponse($response, $operation);
            $this->last = $info;
            DeprecationNotices::check($operation, $response, $this->logger);

            if (ErrorMapper::isSuccess($response, $api)) {
                /** @var array<string, mixed> $decoded */
                $decoded = Json::decodeObject((string) $response->getBody()) ?? [];

                return new Answer($response, $decoded);
            }

            $status = $response->getStatusCode();
            if ($attempt <= $maxRetries && $this->retryStatus($policy, $status, $response)) {
                $retryAfter = ErrorMapper::retryAfter($response, $this->clock->now());
                $wait = $retryAfter !== null
                    ? (float) $retryAfter
                    : ($status === 429 && $api === ErrorMapper::INGEST ? self::INGEST_THROTTLE_WAIT_SECONDS : $this->jitter($attempt));
                if ($wait <= $maxWait) {
                    $this->logRetry($operation, 'HTTP ' . $status, $attempt, $wait);
                    $this->sleeper->sleep($wait);

                    continue;
                }
            }

            $failure = ErrorMapper::map($response, $api, $operation);
            if ($failure instanceof AuthenticationException && $credential === Secrets::METRICS) {
                Secrets::forgetMemo($this->secrets);
            }

            throw $failure;
        }
    }

    /**
     * One unauthenticated request, never retried and never mapped: the caller reads the response (the CLI's reachability check).
     *
     * @throws ApiConnectionException
     */
    public function probe(string $operation, string $url): ResponseInfoAndResponse
    {
        $request = new Request('GET', $url, ['User-Agent' => $this->userAgent(), 'Accept' => 'application/json']);
        if ($this->observer !== null) {
            ($this->observer)($operation);
        }
        try {
            $response = $this->transport->send($request, new TransportOptions($this->timeout, $this->connectTimeout));
        } catch (ApiConnectionException $e) {
            throw $e->forOperation($operation);
        }
        $this->last = ResponseInfo::fromResponse($response, $operation);

        return new ResponseInfoAndResponse($this->last, $response);
    }

    /**
     * @param array<string, string> $headers
     */
    private function buildRequest(string $method, string $url, #[\SensitiveParameter] string $token, #[\SensitiveParameter] ?string $body, array $headers): RequestInterface
    {
        $all = [
            'User-Agent' => $this->userAgent(),
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $token,
        ] + $headers;

        return new Request($method, $url, $all, $body);
    }

    private function retryConnection(RetryPolicy $policy, ApiConnectionException $e): bool
    {
        if ($e->isResponseTooLarge()) {
            return false;
        }

        return match ($policy) {
            RetryPolicy::Read, RetryPolicy::IdempotentWrite => true,
            RetryPolicy::ConnectOnly => ! $e->wasSent(),
        };
    }

    private function retryStatus(RetryPolicy $policy, int $status, ResponseInterface $response): bool
    {
        if ($policy === RetryPolicy::ConnectOnly) {
            return false;
        }
        if ($status === 408) {
            return true;
        }
        if ($status === 429) {
            return ! in_array(ErrorMapper::codeOf($response), ['daily_limit_reached', 'log_volume_limit_reached'], true);
        }
        if (in_array($status, [502, 503, 504], true)) {
            return true;
        }

        return $policy === RetryPolicy::IdempotentWrite && $status >= 500 && $status <= 599;
    }

    private function jitter(int $attempt): float
    {
        $max = min(8.0, 0.5 * (2 ** ($attempt - 1)));

        return $max * (mt_rand() / mt_getrandmax());
    }

    private function logRetry(string $operation, string $reason, int $attempt, float $wait): void
    {
        $this->logger->notice(sprintf('WatchTower15 retry of %s (%s), attempt %d, waiting %.3f s.', $operation, $reason, $attempt, $wait), [
            'operation' => $operation,
            'reason' => $reason,
            'attempt' => $attempt,
            'wait' => $wait,
        ]);
    }
}
