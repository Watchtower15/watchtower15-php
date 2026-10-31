<?php

declare(strict_types=1);

namespace WatchTower15\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * What the last HTTP exchange answered: status, headers, request id and the rate limit.
 */
final readonly class ResponseInfo
{
    /**
     * @param array<array<string>> $headers
     */
    public function __construct(
        public int $statusCode,
        public array $headers,
        public ?string $requestId,
        public string $operation,
        public bool $replayed,
        public ?RateLimitState $rateLimit,
    ) {
    }

    /** @internal */
    public static function fromResponse(ResponseInterface $response, string $operation): self
    {
        $requestId = $response->getHeaderLine('X-Request-Id');
        $limit = $response->getHeaderLine('X-RateLimit-Limit');
        $rateLimit = null;
        if ($limit !== '' && is_numeric($limit)) {
            $remaining = $response->getHeaderLine('X-RateLimit-Remaining');
            $reset = $response->getHeaderLine('X-RateLimit-Reset');
            $rateLimit = new RateLimitState(
                (int) $limit,
                is_numeric($remaining) ? (int) $remaining : 0,
                is_numeric($reset) ? (new \DateTimeImmutable('@' . (int) $reset))->setTimezone(new \DateTimeZone('UTC')) : null,
            );
        }

        return new self(
            $response->getStatusCode(),
            $response->getHeaders(),
            $requestId !== '' ? $requestId : null,
            $operation,
            strtolower($response->getHeaderLine('Idempotent-Replayed')) === 'true',
            $rateLimit,
        );
    }
}
