<?php

declare(strict_types=1);

namespace WatchTower15\Tests\Unit\Metrics;

use PHPUnit\Framework\TestCase;
use WatchTower15\Client;
use WatchTower15\ConfigurationException;
use WatchTower15\Metrics\CallableTokenProvider;
use WatchTower15\Testing\MockTransport;
use WatchTower15\Tests\Support\Harness;
use WatchTower15\Tests\Support\Keys;
use WatchTower15\Tests\Support\PipeHarness;

final class TokenProviderTest extends TestCase
{
    private const B = PipeHarness::T0 / 60;

    private function seedAndAt(PipeHarness $h, int $offset): void
    {
        $h->clock->now = new \DateTimeImmutable('@' . (PipeHarness::T0 + $offset - 50));
        $h->aggregator->increment('a');
        $h->clock->now = new \DateTimeImmutable('@' . (PipeHarness::T0 + $offset));
    }

    public function testP8AStringTokenIsUsedAndAProviderIsResolvedOnlyWhenSomethingIsSent(): void
    {
        $calls = 0;
        $provider = new CallableTokenProvider(function () use (&$calls): string {
            $calls++;

            return Keys::metrics('e');
        });
        $h = new PipeHarness(clientArguments: ['metricsToken' => $provider]);
        self::assertSame(0, $calls, 'not at construction');
        $h->aggregator->flush();
        self::assertSame(0, $calls, 'not when nothing is sent');

        $this->seedAndAt($h, 70);
        $h->accept();
        $h->aggregator->flush();

        self::assertSame(1, $calls);
        self::assertStringEndsWith(substr(Keys::metrics('e'), -4), (string) $h->transport->requests()[0]->header('Authorization'));
    }

    public function testP8NoTokenAnEmptyOneAndALogTokenKeepTheBucketsAndCountAConfigError(): void
    {
        $cases = [
            'absent' => [],
            'empty provider' => ['metricsToken' => new CallableTokenProvider(static fn (): string => '')],
            'log token' => ['metricsToken' => new CallableTokenProvider(static fn (): string => Keys::logs())],
        ];
        foreach ($cases as $label => $client) {
            $h = new PipeHarness(clientArguments: $client + ['metricsToken' => null]);
            $this->seedAndAt($h, 70);

            $report = $h->aggregator->flush();

            self::assertSame('config', $report->stopReason, $label);
            self::assertSame(1, $report->errors['config'], $label);
            self::assertSame([], $h->transport->requests(), $label);
            self::assertSame(1, $h->store->read((int) self::B)['count']['a'], $label);
            self::assertCount(1, $h->logger->level('warning'), $label);
        }
    }

    public function testP8AGoodTokenIsKeptForThreeHundredSecondsAtMost(): void
    {
        $calls = 0;
        $h = new PipeHarness(clientArguments: ['metricsToken' => new CallableTokenProvider(function () use (&$calls): string {
            $calls++;

            return Keys::metrics();
        })]);
        $h->accept(3);
        $this->seedAndAt($h, 70);
        $h->aggregator->flush();                       // t = 70
        $this->seedAndAt($h, 270);
        $h->aggregator->flush();                       // t = 270, 200 s later: the memo holds
        self::assertSame(1, $calls);
        $this->seedAndAt($h, 670);
        $h->aggregator->flush();                       // t = 670, 400 s later: asked again

        self::assertSame(2, $calls);
    }

    public function testP8ARefusedTokenForgetsTheMemoAndTheNewTokenSendsTheSameBucket(): void
    {
        $token = Keys::metrics('1');
        $calls = 0;
        $h = new PipeHarness(clientArguments: ['metricsToken' => new CallableTokenProvider(function () use (&$calls, &$token): string {
            $calls++;

            return $token;
        })]);
        $this->seedAndAt($h, 70);
        $h->transport->append(MockTransport::envelope(403, ['code' => 'forbidden'], 'No'));
        $first = $h->aggregator->flush();
        self::assertSame('auth', $first->stopReason);
        self::assertSame(1, $calls);

        $token = Keys::metrics('2');
        $h->advance(60);
        $h->accept();
        $second = $h->aggregator->flush();

        self::assertSame(2, $calls, 'the provider is asked again: the 403 forgot the memo');
        self::assertSame(1, $second->bucketsSent);
        $keys = array_map(static fn ($r): string => (string) $r->header('Idempotency-Key'), $h->transport->requests());
        self::assertNotSame($keys[0], $keys[1], 'the new token is in the key');
        self::assertSame($h->transport->requests()[0]->body['points'][0]['recorded_at'], $h->transport->requests()[1]->body['points'][0]['recorded_at']);
    }

    public function testP8RefusedResultsAreNeverMemoized(): void
    {
        $answers = ['', 'wt15ms_short', 'throw'];
        $calls = 0;
        $h = new PipeHarness(clientArguments: ['metricsToken' => new CallableTokenProvider(function () use (&$calls, &$answers): string {
            $calls++;
            $next = array_shift($answers);
            if ($next === 'throw') {
                throw new \RuntimeException('provider failed');
            }

            return (string) $next;
        })]);
        $reports = [];
        for ($i = 0; $i < 3; $i++) {
            $this->seedAndAt($h, 70 + $i * 60);
            $reports[] = $h->aggregator->flush();
        }

        self::assertSame(3, $calls, 'once in each run');
        self::assertSame(['config', 'config', 'internal'], array_map(static fn ($r): ?string => $r->stopReason, $reports));
        self::assertSame([], $h->transport->requests());
        self::assertSame(3, array_sum(array_map(static fn (int $b): int => $h->store->read($b)['count']['a'] ?? 0, range((int) self::B, (int) self::B + 2))));
    }

    public function testP27TheApiPathThrowsWhereThePipeCounts(): void
    {
        $h = new Harness(['metricsToken' => new CallableTokenProvider(static fn (): string => '')]);

        $this->expectException(ConfigurationException::class);
        $h->client->ingest->metrics->push([['metric' => 'a', 'metric_type' => 'count', 'value' => 1]]);
    }

    public function testP28NoTokenIsReachableFromAClientWithAProvider(): void
    {
        $token = Keys::metrics('9');
        $h = new Harness(['metricsToken' => new CallableTokenProvider(static fn (): string => $token)]);
        $client = $h->client;
        self::assertSame(
            'provider (WatchTower15\Metrics\CallableTokenProvider), unresolved',
            $client->__debugInfo()['metricsToken'],
        );

        $this->assertNoSecret($client, $token);
        $h->transport->append(MockTransport::metricsAccepted(1));
        $client->ingest->metrics->push([['metric' => 'a', 'metric_type' => 'count', 'value' => 1]]);
        $this->assertNoSecret($client, $token);
        self::assertSame('wt15ms_…' . substr($token, -4), $client->__debugInfo()['metricsToken']);
    }

    private function assertNoSecret(Client $client, string $token): void
    {
        ob_start();
        var_dump($client);
        $texts = [(string) ob_get_clean(), print_r($client, true), var_export($client, true), (string) json_encode($client)];
        foreach ($texts as $text) {
            self::assertStringNotContainsString(substr($token, 7, 40), $text);
        }
        $seen = [];
        $walk = function (mixed $value) use (&$walk, &$seen, $token): void {
            if (is_object($value)) {
                if (isset($seen[spl_object_id($value)])) {
                    return;
                }
                $seen[spl_object_id($value)] = true;
                $value = (array) $value;
            }
            if (is_array($value)) {
                foreach ($value as $item) {
                    $walk($item);
                }
            } elseif (is_string($value)) {
                self::assertStringNotContainsString(substr($token, 7, 40), $value);
            }
        };
        $walk($client);
    }
}
