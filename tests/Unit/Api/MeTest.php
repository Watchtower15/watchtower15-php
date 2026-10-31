<?php

declare(strict_types=1);

namespace WatchTower15\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use WatchTower15\Exception\AuthenticationException;
use WatchTower15\Testing\MockTransport;
use WatchTower15\Tests\Support\Harness;

final class MeTest extends TestCase
{
    public function testM1MeIsReadIntoATypedObject(): void
    {
        $h = new Harness();
        $h->transport->append(Harness::meBody());

        $me = $h->client->me();

        $request = $h->transport->requests()[0];
        self::assertSame('GET', $request->method);
        self::assertSame('https://rest.watchtower15.com/v1/me', $request->uri);
        self::assertSame('k-1', $me->keyUuid);
        self::assertSame('CI key', $me->keyName);
        self::assertSame('abcd', $me->keyLastFour);
        self::assertSame(['tags.view'], $me->scopes);
        self::assertSame('2027-01-01T00:00:00+00:00', $me->keyCreatedAt->format('c'));
        self::assertSame('UTC', $me->keyCreatedAt->getTimezone()->getName());
        self::assertNotNull($me->keyExpiresAt);
        self::assertSame('ada@example.com', $me->userEmail);
        self::assertSame('Demo', $me->organisationName);
        self::assertSame(1, $me->toArray()['key']['extra']);
    }

    public function testM2AnUnauthorisedKeyRaisesAuthenticationException(): void
    {
        $h = new Harness();
        $h->transport->append(MockTransport::problem(401, 'unauthorized'));

        try {
            $h->client->me();
            self::fail('expected an exception');
        } catch (AuthenticationException $e) {
            self::assertSame('unauthorized', $e->getErrorCode());
        }
    }
}
