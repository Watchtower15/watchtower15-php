<?php

declare(strict_types=1);

namespace WatchTower15\Tests\Support;

use GuzzleHttp\Psr7\Response;
use WatchTower15\Testing\MockTransport;

/** The on-spec answers under `Fixtures/v1/<operationId>/` (plan WT15-SDK-00007 D20), written from the pinned spec. */
final class Fixture
{
    public const UUID = '5f0c2e8a-1b0d-4c7e-9a52-0d3f6b8e4a11';

    /** @return array<string, mixed> */
    public static function load(string $operation, string $name = 'ok'): array
    {
        $path = __DIR__ . '/../Fixtures/v1/' . $operation . '/' . $name . '.json';
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new \LogicException("Fixture $path is not a JSON object.");
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $envelope
     * @param array<string, string> $headers
     */
    public static function response(array $envelope, int $status = 200, array $headers = []): Response
    {
        return MockTransport::json($envelope, $status, $headers);
    }

    /**
     * The fixture's answer with the first element of `data` (a list) or `data` itself (an object) changed.
     *
     * @param array<string, mixed> $change
     * @param array<string, mixed> $envelope top-level keys to set (`next_cursor`, `total`, `meta`)
     * @param array<string, string> $headers
     */
    public static function answer(string $operation, array $change = [], array $envelope = [], int $status = 200, array $headers = []): Response
    {
        $body = self::load($operation);
        $data = $body['data'] ?? [];
        if (is_array($data) && array_is_list($data) && isset($data[0]) && is_array($data[0])) {
            $data[0] = $change + $data[0];
        } elseif (is_array($data)) {
            $data = $change + $data;
        }
        $body['data'] = $data;

        return self::response($envelope + $body, $status, $headers);
    }

    /** A list answer whose rows carry the given uuids. @param list<string> $uuids @param array<string, string> $headers */
    public static function page(string $operation, array $uuids, ?string $next, array $headers = []): Response
    {
        $body = self::load($operation);
        $row = is_array($body['data'] ?? null) && isset($body['data'][0]) && is_array($body['data'][0]) ? $body['data'][0] : [];
        $body['data'] = array_map(static fn (string $uuid): array => ['uuid' => $uuid] + $row, $uuids);
        $body['next_cursor'] = $next;

        return self::response($body, 200, $headers);
    }
}
