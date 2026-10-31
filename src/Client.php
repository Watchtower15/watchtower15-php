<?php

declare(strict_types=1);

namespace WatchTower15;

use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use WatchTower15\Api\Me;
use WatchTower15\Credentials\CredentialFormat;
use WatchTower15\Http\CurlTransport;
use WatchTower15\Http\Psr18Transport;
use WatchTower15\Http\RequestOptions;
use WatchTower15\Http\ResponseInfo;
use WatchTower15\Http\RetryPolicy;
use WatchTower15\Http\Transport;
use WatchTower15\Ingest\IngestServices;
use WatchTower15\Internal\Clock;
use WatchTower15\Internal\Environment;
use WatchTower15\Internal\ErrorMapper;
use WatchTower15\Internal\Requirement;
use WatchTower15\Internal\Secrets;
use WatchTower15\Internal\SecretsHandle;
use WatchTower15\Internal\Sender;
use WatchTower15\Internal\Sleeper;
use WatchTower15\Internal\SystemClock;
use WatchTower15\Internal\SystemSleeper;
use WatchTower15\Metrics\TokenProvider;

/**
 * The WatchTower15 client: three credentials, one transport, typed errors and safe retries.
 *
 * No property of this object holds a credential (they live in `Internal\Secrets`), so a dump of it shows none.
 */
class Client
{
    public const DEFAULT_API_URL = 'https://rest.watchtower15.com/v1';

    public const DEFAULT_INGEST_URL = 'https://api.watchtower15.com';

    private const WHITESPACE = " \t\n\r\0\x0B";

    private const NAMED = ['apiKey', 'metricsToken', 'logsToken', 'apiUrl', 'ingestUrl', 'timeout', 'connectTimeout', 'maxRetries', 'httpClient', 'logger', 'appInfo'];

    public readonly IngestServices $ingest;

    private readonly SecretsHandle $secrets;

    private readonly Sender $sender;

    private readonly string $apiUrl;

    private readonly string $ingestUrl;

    private readonly float $timeout;

    private readonly float $connectTimeout;

    private readonly int $maxRetries;

    private readonly ?string $appInfo;

    private readonly LoggerInterface $logger;

    public function __construct(
        #[\SensitiveParameter] ?string $apiKey = null,
        #[\SensitiveParameter] string|TokenProvider|null $metricsToken = null,
        #[\SensitiveParameter] ?string $logsToken = null,
        string $apiUrl = self::DEFAULT_API_URL,
        string $ingestUrl = self::DEFAULT_INGEST_URL,
        float $timeout = 15.0,
        float $connectTimeout = 2.0,
        int $maxRetries = 2,
        Transport|ClientInterface|null $httpClient = null,
        ?LoggerInterface $logger = null,
        ?string $appInfo = null,
    ) {
        if (! ($timeout > 0 && $timeout <= 300)) {
            throw new ConfigurationException('`timeout` must be above 0 and at most 300 seconds.');
        }
        if (! ($connectTimeout > 0 && $connectTimeout <= $timeout)) {
            throw new ConfigurationException('`connectTimeout` must be above 0 and not above `timeout`.');
        }
        if ($maxRetries < 0 || $maxRetries > 10) {
            throw new ConfigurationException('`maxRetries` must be between 0 and 10.');
        }
        if ($appInfo !== null && preg_match('#\A[A-Za-z0-9._-]+/[A-Za-z0-9._+-]+\z#', $appInfo) !== 1) {
            throw new ConfigurationException('`appInfo` must look like `name/version`, with letters, digits and `._+-` only.');
        }

        $apiKey = self::credential($apiKey, 'apiKey', 'api_key');
        $metricsToken = is_string($metricsToken) ? self::credential($metricsToken, 'metricsToken', 'metrics_token') : $metricsToken;
        $logsToken = self::credential($logsToken, 'logsToken', 'logs_token');

        $this->apiUrl = self::baseUrl($apiUrl, 'apiUrl');
        $this->ingestUrl = self::baseUrl($ingestUrl, 'ingestUrl');
        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;
        $this->maxRetries = $maxRetries;
        $this->appInfo = $appInfo;
        $this->logger = $logger ?? new NullLogger();

        $this->secrets = new SecretsHandle();
        Secrets::store($this->secrets, $apiKey, $metricsToken, $logsToken);

        $transport = match (true) {
            $httpClient instanceof Transport => $httpClient,
            $httpClient instanceof ClientInterface => new Psr18Transport($httpClient),
            default => new CurlTransport(),
        };
        $this->sender = new Sender(
            $this->secrets,
            $transport,
            $this->logger,
            new SystemClock(),
            new SystemSleeper(),
            $timeout,
            $connectTimeout,
            $maxRetries,
            $appInfo,
        );
        $this->ingest = new IngestServices($this->sender, $this->secrets, $this->ingestUrl);
    }

    /**
     * A client from `WT15_API_KEY`, `WT15_METRICS_TOKEN`, `WT15_LOGS_TOKEN`, `WT15_API_URL` and `WT15_INGEST_URL`, each read from
     * `$_SERVER`, then `$_ENV`, then `getenv()`. A named argument overrides its variable.
     */
    public static function fromEnvironment(mixed ...$overrides): self
    {
        return self::fromValues(Environment::read(), $overrides);
    }

    /**
     * @param array<string, string>     $environment Variables by name.
     * @param array<int|string, mixed>  $overrides
     *
     * @internal
     */
    public static function fromValues(array $environment, array $overrides, Transport|ClientInterface|null $httpClient = null): self
    {
        foreach ($overrides as $name => $_) {
            if (! is_string($name)) {
                throw new ConfigurationException('`fromEnvironment()` takes named arguments only; got a positional one.');
            }
            if (! in_array($name, self::NAMED, true)) {
                throw new ConfigurationException(sprintf('`fromEnvironment()` has no argument `%s`; the names are %s.', $name, implode(', ', self::NAMED)));
            }
        }
        $arguments = [];
        foreach (Environment::VARIABLES as $name => $variable) {
            if (isset($environment[$variable]) && trim($environment[$variable], self::WHITESPACE) !== '') {
                $arguments[$name] = $environment[$variable];
            }
        }
        $arguments = array_merge($arguments, $overrides);
        if ($httpClient !== null && ! isset($arguments['httpClient'])) {
            $arguments['httpClient'] = $httpClient;
        }

        /** @phpstan-ignore argument.type */
        return new self(...$arguments);
    }

    /**
     * `GET /v1/me`: the API key's own record.
     *
     * @param RequestOptions|array<string, mixed>|null $options
     */
    public function me(RequestOptions|array|null $options = null): Me
    {
        Requirement::credential($this->secrets, Secrets::API);
        $answer = $this->sender->execute(
            ErrorMapper::V1,
            Secrets::API,
            'public.me.view',
            'GET',
            $this->apiUrl . '/me',
            RetryPolicy::Read,
            null,
            [],
            RequestOptions::from($options),
        );

        return Me::fromData($answer->data());
    }

    /** What the last HTTP exchange answered, or null before the first. */
    public function lastResponse(): ?ResponseInfo
    {
        return $this->sender->lastResponse();
    }

    /** The `User-Agent` this client sends. */
    public function userAgent(): string
    {
        return $this->sender->userAgent();
    }

    /**
     * A bridge names its framework once.
     *
     * @internal
     */
    public function useFramework(string $name, string $version): void
    {
        $this->sender->setFramework($name, $version);
    }

    /** @internal */
    public function logger(): LoggerInterface
    {
        return $this->logger;
    }

    /**
     * The SHA-256 of the metric token, resolving a provider when there is one (for the flush's frozen key).
     *
     * @internal
     *
     * @throws ConfigurationException when no token is configured or a provider returns a bad one
     */
    public function metricsTokenHash(): string
    {
        return $this->sender->tokenHash(Secrets::METRICS);
    }

    /** @internal */
    public function sender(): Sender
    {
        return $this->sender;
    }

    /** @internal */
    public function apiUrl(): string
    {
        return $this->apiUrl;
    }

    /** @internal */
    public function ingestUrl(): string
    {
        return $this->ingestUrl;
    }

    /** @internal */
    public function secretsHandle(): SecretsHandle
    {
        return $this->secrets;
    }

    /** @internal Replaces the clock and the sleeper, for tests. */
    public function useTimeSeams(Clock $clock, Sleeper $sleeper): void
    {
        $this->sender->setSeams($clock, $sleeper);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'apiKey' => Secrets::masked($this->secrets, Secrets::API),
            'metricsToken' => Secrets::masked($this->secrets, Secrets::METRICS),
            'logsToken' => Secrets::masked($this->secrets, Secrets::LOGS),
            'apiUrl' => $this->apiUrl,
            'ingestUrl' => $this->ingestUrl,
            'timeout' => $this->timeout,
            'connectTimeout' => $this->connectTimeout,
            'maxRetries' => $this->maxRetries,
            'appInfo' => $this->appInfo,
        ];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('a Client holds credentials and is not serialisable');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('a Client holds credentials and is not serialisable');
    }

    private static function credential(#[\SensitiveParameter] ?string $value, string $parameter, string $kind): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value, self::WHITESPACE);
        if ($value === '') {
            return null;
        }
        $valid = match ($kind) {
            'api_key' => CredentialFormat::isApiKey($value),
            'metrics_token' => CredentialFormat::isMetricsToken($value),
            default => CredentialFormat::isLogsToken($value),
        };
        if ($valid) {
            return $value;
        }
        $actual = CredentialFormat::kindOf($value);
        if ($actual !== null && $actual !== $kind) {
            throw new ConfigurationException(sprintf(
                'This is %s: pass it as `%s`, not `%s`.',
                CredentialFormat::describe($value),
                CredentialFormat::parameterFor($actual),
                $parameter,
            ));
        }
        $shape = match ($kind) {
            'api_key' => 'an API key: `wt15ak_`, 64 lower-case hex characters and a 6-character checksum',
            'metrics_token' => 'a metric source token: `wt15ms_` and 64 lower-case hex characters',
            default => 'a log source token: `wt15ls_` and 64 lower-case hex characters',
        };

        throw new ConfigurationException(sprintf('`%s` (`%s`) is not the shape of %s; check it was pasted whole.', $parameter, CredentialFormat::mask($value), $shape));
    }

    private static function baseUrl(string $url, string $parameter): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new ConfigurationException(sprintf('`%s` is not a URL.', $parameter));
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $loopback = in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true);
        if ($scheme !== 'https' && ! ($scheme === 'http' && $loopback)) {
            throw new ConfigurationException(sprintf('`%s` must use https (http is accepted for localhost, 127.0.0.1 and [::1] only).', $parameter));
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || str_contains($url, '?') || str_contains($url, '#')) {
            throw new ConfigurationException(sprintf('`%s` must not carry user-info, a query or a fragment.', $parameter));
        }

        return rtrim($url, '/');
    }
}
