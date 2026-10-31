<?php

declare(strict_types=1);

namespace WatchTower15\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use WatchTower15\Client;
use WatchTower15\Internal\UserAgent;
use WatchTower15\Package;
use WatchTower15\Tests\Support\Harness;
use WatchTower15\Tests\Support\Keys;
use WatchTower15\Testing\MockTransport;

final class UserAgentTest extends TestCase
{
    private function php(): string
    {
        return PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    }

    public function testH1TheBuilder(): void
    {
        $php = $this->php();

        self::assertSame("watchtower15-php/dev php/$php", UserAgent::build('dev', $php, null, null));
        self::assertSame("watchtower15-php/0.1.1 php/$php", UserAgent::build('v0.1.1', $php, null, null));
        self::assertSame("watchtower15-php/dev-feature-WT15-SDK-00003 php/$php", UserAgent::build('dev-feature/WT15-SDK-00003', $php, null, null));
        $long = UserAgent::build(str_repeat('1', 60), $php, null, null);
        self::assertSame('watchtower15-php/' . str_repeat('1', 47) . " php/$php", $long);
        self::assertSame("watchtower15-php/0.1.1 php/$php laravel/13.14.0 acme-billing/2.1", UserAgent::build('0.1.1', $php, 'laravel/13.14.0', 'acme-billing/2.1'));
    }

    public function testH1TheClientUsesThePackageVersionAndTheFrameworkIsSetOnce(): void
    {
        $client = new Client();
        $version = (string) preg_replace('/[^0-9A-Za-z.+-]/', '-', ltrim(Package::version(), 'v'));

        self::assertStringStartsWith('watchtower15-php/' . substr($version, 0, 47) . ' php/', $client->userAgent());

        $client->useFramework('laravel', '13.14.0');
        self::assertStringContainsString(' laravel/13.14.0', $client->userAgent());
        $this->expectException(\LogicException::class);
        $client->useFramework('laravel', '13.14.0');
    }

    public function testH2EveryRequestCarriesTheUserAgentAndTheRightCredential(): void
    {
        $h = new Harness();
        $h->transport->append(
            Harness::meBody(),
            MockTransport::envelope(200, ['metrics' => [], 'limits' => ['max_metrics' => 5, 'used' => 0]]),
            MockTransport::metricsAccepted(1),
            MockTransport::logsAccepted(1),
        );

        $h->client->me();
        $h->client->ingest->metrics->list();
        $h->client->ingest->metrics->push([['metric' => 'a.b', 'metric_type' => 'count', 'value' => 1]]);
        $h->client->ingest->logs->push([['message' => 'hello']]);

        $requests = $h->transport->requests();
        self::assertCount(4, $requests);
        $expected = [Keys::apiKey(), Keys::metrics(), Keys::metrics(), Keys::logs()];
        foreach ($requests as $i => $request) {
            self::assertSame($h->client->userAgent(), $request->header('User-Agent'));
            self::assertSame('application/json', $request->header('Accept'));
            $masked = (string) $request->header('Authorization');
            self::assertStringStartsWith('Bearer ' . substr($expected[$i], 0, 7) . '…', $masked);
            self::assertStringEndsWith($i === 0 ? substr($expected[0], 67, 4) : substr($expected[$i], -4), $masked);
        }
        self::assertStringEndsWith('/v1/me', $requests[0]->uri);
        self::assertStringEndsWith('/api/log-sources/ingest', $requests[3]->uri);
    }
}
