<?php

declare(strict_types=1);

namespace WatchTower15\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WatchTower15\Client;
use WatchTower15\ConfigurationException;
use WatchTower15\Tests\Support\Keys;
use WatchTower15\Testing\MockTransport;

final class ClientConfigurationTest extends TestCase
{
    private const VARIABLES = ['WT15_API_KEY', 'WT15_METRICS_TOKEN', 'WT15_LOGS_TOKEN', 'WT15_API_URL', 'WT15_INGEST_URL'];

    protected function tearDown(): void
    {
        foreach (self::VARIABLES as $name) {
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);
        }
    }

    public function testC1DefaultsAndMissingCredentials(): void
    {
        $mock = new MockTransport();
        $client = new Client(httpClient: $mock);

        $calls = [
            ['apiKey', 'WT15_API_KEY', static fn () => $client->me()],
            ['metricsToken', 'WT15_METRICS_TOKEN', static fn () => $client->ingest->metrics->push([['metric' => 'a', 'metric_type' => 'count', 'value' => 1]])],
            ['logsToken', 'WT15_LOGS_TOKEN', static fn () => $client->ingest->logs->push([['message' => 'hello']])],
        ];
        foreach ($calls as [$param, $variable, $call]) {
            try {
                $call();
                self::fail('expected ConfigurationException');
            } catch (ConfigurationException $e) {
                self::assertStringContainsString($param, $e->getMessage());
                self::assertStringContainsString($variable, $e->getMessage());
            }
        }
        self::assertSame([], $mock->requests());
        self::assertSame('https://rest.watchtower15.com/v1', $client->apiUrl());
        self::assertSame('https://api.watchtower15.com', $client->ingestUrl());
        self::assertNull($client->lastResponse());
    }

    public function testC2EachSourceIsReadInOrder(): void
    {
        $_SERVER['WT15_METRICS_TOKEN'] = Keys::metrics('1');
        $client = Client::fromEnvironment();
        self::assertSame('wt15ms_…' . substr(Keys::metrics('1'), -4), $client->__debugInfo()['metricsToken']);
        unset($_SERVER['WT15_METRICS_TOKEN']);

        $_ENV['WT15_METRICS_TOKEN'] = Keys::metrics('2');
        self::assertSame('wt15ms_…2222', Client::fromEnvironment()->__debugInfo()['metricsToken']);
        unset($_ENV['WT15_METRICS_TOKEN']);

        putenv('WT15_METRICS_TOKEN=' . Keys::metrics('3'));
        self::assertSame('wt15ms_…3333', Client::fromEnvironment()->__debugInfo()['metricsToken']);

        $_SERVER['WT15_METRICS_TOKEN'] = '';
        self::assertSame('wt15ms_…3333', Client::fromEnvironment()->__debugInfo()['metricsToken'], 'an empty value is not given');

        self::assertSame('wt15ms_…4444', Client::fromEnvironment(metricsToken: Keys::metrics('4'))->__debugInfo()['metricsToken'], 'an override wins');
    }

    public function testC2TheFiveVariablesAreRead(): void
    {
        $_SERVER['WT15_API_KEY'] = Keys::apiKey();
        $_SERVER['WT15_LOGS_TOKEN'] = Keys::logs();
        $_SERVER['WT15_API_URL'] = 'http://127.0.0.1:48009/v1/';
        $_SERVER['WT15_INGEST_URL'] = 'http://localhost:48009';
        $client = Client::fromEnvironment();

        self::assertSame('http://127.0.0.1:48009/v1', $client->apiUrl());
        self::assertSame('http://localhost:48009', $client->ingestUrl());
        self::assertNotNull($client->__debugInfo()['apiKey']);
        self::assertNotNull($client->__debugInfo()['logsToken']);
    }

    public function testC2AnUnknownNamedOverrideAndAPositionalOneAreRefused(): void
    {
        try {
            Client::fromEnvironment(apikey: 'x');
            self::fail('expected a refusal');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('apikey', $e->getMessage());
        }
        try {
            Client::fromEnvironment('x');
            self::fail('expected a refusal');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('positional', $e->getMessage());
        }
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function urls(): iterable
    {
        yield 'loopback ip' => ['http://127.0.0.1:48009', 'http://127.0.0.1:48009'];
        yield 'localhost' => ['http://localhost', 'http://localhost'];
        yield 'ipv6 loopback' => ['http://[::1]:8080', 'http://[::1]:8080'];
        yield 'trailing slash' => ['https://example.com/v1/', 'https://example.com/v1'];
        yield 'http to a public host' => ['http://example.com', null];
        yield 'user-info' => ['https://user:pw@example.com', null];
        yield 'query' => ['https://example.com/v1?x=1', null];
        yield 'fragment' => ['https://example.com/v1#f', null];
        yield 'not a url' => ['not a url', null];
    }

    #[DataProvider('urls')]
    public function testC6BaseUrls(string $url, ?string $expected): void
    {
        if ($expected === null) {
            $this->expectException(ConfigurationException::class);
        }
        $client = new Client(apiUrl: $url, ingestUrl: $url);

        self::assertSame($expected, $client->apiUrl());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function badBounds(): iterable
    {
        yield 'timeout 0' => [['timeout' => 0.0]];
        yield 'timeout 301' => [['timeout' => 301.0]];
        yield 'connectTimeout above timeout' => [['timeout' => 5.0, 'connectTimeout' => 6.0]];
        yield 'maxRetries -1' => [['maxRetries' => -1]];
        yield 'maxRetries 11' => [['maxRetries' => 11]];
        yield 'appInfo with a space' => [['appInfo' => 'acme billing']];
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('badBounds')]
    public function testC8BoundsAreEnforced(array $arguments): void
    {
        $this->expectException(ConfigurationException::class);
        /** @phpstan-ignore argument.type */
        new Client(...$arguments);
    }

    public function testC8AGoodAppInfoIsAccepted(): void
    {
        $client = new Client(appInfo: 'acme-billing/2.1');

        self::assertStringEndsWith(' acme-billing/2.1', $client->userAgent());
    }
}
