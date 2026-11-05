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

    /** K6 (Plan_API_06 D22): `accountType` reads `user.account_type`, null when the answer lacks it, and the constructor still takes today's positional arguments. */
    public function testK6AccountTypeIsReadAndAbsentFromAnOlderBackendIsNull(): void
    {
        $data = Harness::meBody()->getBody()->getContents();
        $decoded = json_decode($data, true);
        self::assertIsArray($decoded);
        $answer = $decoded['data'];
        self::assertIsArray($answer);

        $answer['user']['account_type'] = 'service_account';
        self::assertSame('service_account', \WatchTower15\Api\Me::fromData($answer)->accountType);

        $answer['user']['account_type'] = 'person';
        self::assertSame('person', \WatchTower15\Api\Me::fromData($answer)->accountType);

        unset($answer['user']['account_type']);
        self::assertNull(\WatchTower15\Api\Me::fromData($answer)->accountType);

        // A caller that builds one by hand with today's positional arguments (and `raw` last) is unchanged.
        $me = new \WatchTower15\Api\Me('k', 'n', 'abcd', ['tags.view'], new \DateTimeImmutable('@0'), null, 'u', 'Ada', 'ada@example.com', 'o', 'Demo', ['x' => 1]);
        self::assertNull($me->accountType);
        self::assertSame(['x' => 1], $me->toArray());
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
