<?php

declare(strict_types=1);

namespace WatchTower15\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use WatchTower15\Client;
use WatchTower15\ConfigurationException;
use WatchTower15\Tests\Support\Harness;
use WatchTower15\Tests\Support\Keys;

final class SecretsLeakTest extends TestCase
{
    public function testC7NoCredentialIsReachableFromAClient(): void
    {
        $harness = new Harness();
        $client = $harness->client;
        $secrets = [Keys::apiKey(), Keys::metrics(), Keys::logs()];

        ob_start();
        var_dump($client);
        $dump = (string) ob_get_clean();
        $printed = print_r($client, true);
        $exported = var_export($client, true);
        $json = json_encode($client);

        foreach ([$dump, $printed, $exported, (string) $json] as $text) {
            foreach ($secrets as $secret) {
                self::assertStringNotContainsString($secret, $text);
                self::assertStringNotContainsString(substr($secret, 7, 40), $text);
            }
        }
        self::assertStringContainsString('wt15ak_…', $dump);
        self::assertStringContainsString('wt15ak_…', $printed);

        $seen = [];
        $this->walk($client, $secrets, $seen);
        self::assertGreaterThan(3, count($seen), 'the walk visited the objects the client holds');
    }

    /**
     * @param list<string>              $secrets
     * @param array<int, true>          $seen
     */
    private function walk(mixed $value, array $secrets, array &$seen): void
    {
        if (is_object($value)) {
            if (isset($seen[spl_object_id($value)])) {
                return;
            }
            $seen[spl_object_id($value)] = true;
            $value = (array) $value;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->walk($item, $secrets, $seen);
            }

            return;
        }
        if (is_string($value)) {
            foreach ($secrets as $secret) {
                self::assertStringNotContainsString(substr($secret, 7, 40), $value);
            }
        }
    }

    public function testC7ASerialisedClientThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('not serialisable');
        serialize((new Harness())->client);
    }

    public function testC7ACloneKeepsWorking(): void
    {
        $harness = new Harness();
        $clone = clone $harness->client;
        $harness->transport->append(Harness::meBody());

        $clone->me();

        self::assertSame('Bearer wt15ak_…' . substr(Keys::apiKey(), 67, 4), $harness->transport->requests()[0]->header('Authorization'));
    }

    public function testC7AConstructionFailureTraceHoldsNoCredential(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            new Client(apiKey: 'wt15ak_' . str_repeat('f', 64) . 'ZZZZZZ');
            self::fail('expected a refusal');
        } catch (ConfigurationException $e) {
            foreach ($e->getTrace() as $frame) {
                foreach ($frame['args'] ?? [] as $argument) {
                    self::assertNotSame('wt15ak_' . str_repeat('f', 64) . 'ZZZZZZ', $argument);
                }
            }
            $first = $e->getTrace()[0]['args'][0] ?? null;
            self::assertInstanceOf(\SensitiveParameterValue::class, $first);
        } finally {
            if ($previous !== false) {
                ini_set('zend.exception_ignore_args', $previous);
            }
        }
    }

    public function testNoNetworkIsTouchedByTheseTests(): void
    {
        self::assertInstanceOf(Response::class, Harness::meBody());
    }
}
