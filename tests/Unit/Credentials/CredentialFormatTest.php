<?php

declare(strict_types=1);

namespace WatchTower15\Tests\Unit\Credentials;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WatchTower15\Client;
use WatchTower15\ConfigurationException;
use WatchTower15\Credentials\CredentialFormat;
use WatchTower15\Tests\Support\Keys;

final class CredentialFormatTest extends TestCase
{
    public function testC3AValidApiKeyIsAccepted(): void
    {
        self::assertTrue(CredentialFormat::isApiKey(Keys::apiKey()));
        new Client(apiKey: Keys::apiKey('0'));
    }

    /** @return iterable<string, array{string}> */
    public static function badApiKeys(): iterable
    {
        $good = Keys::apiKey();
        yield 'wrong case prefix' => ['wt15AK_' . substr($good, 7)];
        yield '76 characters' => [substr($good, 0, 76)];
        yield '78 characters' => [$good . 'a'];
        yield 'upper-case hex' => [substr($good, 0, 7) . strtoupper(substr($good, 7, 64)) . substr($good, 71)];
        yield 'underscore in the checksum' => [substr($good, 0, 72) . '_' . substr($good, 73)];
        $flipped = $good;
        $flipped[75] = $flipped[75] === 'x' ? 'y' : 'x';
        yield 'one checksum character changed' => [$flipped];
        $hex = $good;
        $hex[10] = $hex[10] === 'a' ? 'b' : 'a';
        yield 'one hex character changed' => [$hex];
    }

    #[DataProvider('badApiKeys')]
    public function testC3BadApiKeysAreRefusedAndMasked(string $bad): void
    {
        self::assertFalse(CredentialFormat::isApiKey($bad));
        try {
            new Client(apiKey: $bad);
            self::fail('expected a refusal');
        } catch (ConfigurationException $e) {
            self::assertStringNotContainsString(substr($bad, 20, 40), $e->getMessage());
            self::assertStringNotContainsString($bad, $e->getMessage());
        }
    }

    public function testC3MaskShowsPrefixAndTheFourCharactersBeforeTheChecksum(): void
    {
        $key = Keys::apiKey();

        self::assertSame('wt15ak_…' . substr($key, 67, 4), CredentialFormat::mask($key));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function tokens(): iterable
    {
        foreach (['wt15ms_' => 'isMetricsToken', 'wt15ls_' => 'isLogsToken'] as $prefix => $method) {
            $hex = str_repeat('a1', 32);
            yield "$prefix valid" => [$prefix . $hex . '|' . $method, true];
            yield "$prefix 63 hex" => [$prefix . substr($hex, 0, 63) . '|' . $method, false];
            yield "$prefix 65 hex" => [$prefix . $hex . 'a|' . $method, false];
            yield "$prefix upper hex" => [$prefix . strtoupper($hex) . '|' . $method, false];
            yield "$prefix no prefix" => [$hex . '|' . $method, false];
        }
    }

    #[DataProvider('tokens')]
    public function testC4TokenShapes(string $valueAndMethod, bool $valid): void
    {
        [$value, $method] = explode('|', $valueAndMethod);

        self::assertSame($valid, CredentialFormat::$method($value));
    }

    public function testC4SurroundingWhitespaceIsTrimmed(): void
    {
        $client = new Client(metricsToken: " \t" . Keys::metrics() . "\n", logsToken: Keys::logs() . "\r\n");

        self::assertSame('wt15ms_…' . substr(Keys::metrics(), -4), $client->__debugInfo()['metricsToken']);
    }

    public function testC4AMalformedTokenIsRefused(): void
    {
        $this->expectException(ConfigurationException::class);
        new Client(metricsToken: 'wt15ms_' . str_repeat('a', 63));
    }

    /** @return iterable<string, array{array<string, string>, string, string}> */
    public static function wrongSlots(): iterable
    {
        yield 'metric token as apiKey' => [['apiKey' => Keys::metrics()], 'metric source token', 'metricsToken'];
        yield 'log token as metricsToken' => [['metricsToken' => Keys::logs()], 'log source token', 'logsToken'];
        yield 'API key as logsToken' => [['logsToken' => Keys::apiKey()], 'API key', 'apiKey'];
    }

    /** @param array<string, string> $arguments */
    #[DataProvider('wrongSlots')]
    public function testC5ACredentialInTheWrongSlotIsNamed(array $arguments, string $what, string $param): void
    {
        try {
            new Client(...$arguments);
            self::fail('expected a refusal');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString($what, $e->getMessage());
            self::assertStringContainsString('`' . $param . '`', $e->getMessage());
            self::assertStringContainsString('…', $e->getMessage());
        }
    }
}
