<?php

declare(strict_types=1);

namespace WatchTower15\Internal;

use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Logs a `Deprecation` or `Sunset` header once per operation per process.
 *
 * @internal
 */
final class DeprecationNotices
{
    /** @var array<string, true> */
    private static array $seen = [];

    private function __construct()
    {
    }

    public static function check(string $operation, ResponseInterface $response, LoggerInterface $logger): void
    {
        $deprecation = $response->getHeaderLine('Deprecation');
        $sunset = $response->getHeaderLine('Sunset');
        if ($deprecation === '' && $sunset === '') {
            return;
        }
        if (isset(self::$seen[$operation])) {
            return;
        }
        self::$seen[$operation] = true;

        $links = [];
        foreach ($response->getHeader('Link') as $link) {
            if (preg_match('/rel="?(deprecation|sunset)"?/i', $link) === 1) {
                $links[] = $link;
            }
        }
        $logger->warning(sprintf('WatchTower15 operation %s is deprecated or has a sunset date.', $operation), [
            'operation' => $operation,
            'deprecation' => $deprecation,
            'sunset' => $sunset,
            'link' => $links,
        ]);
    }

    /** @internal for tests */
    public static function reset(): void
    {
        self::$seen = [];
    }
}
