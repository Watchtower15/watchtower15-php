<?php

declare(strict_types=1);

namespace WatchTower15\Internal;

use Psr\Http\Message\ResponseInterface;
use WatchTower15\Exception\ApiException;
use WatchTower15\Exception\AuthenticationException;
use WatchTower15\Exception\DailyLimitException;
use WatchTower15\Exception\InvalidRequestException;
use WatchTower15\Exception\LimitReachedException;
use WatchTower15\Exception\LogVolumeLimitException;
use WatchTower15\Exception\NotFoundException;
use WatchTower15\Exception\PayloadTooLargeException;
use WatchTower15\Exception\PaymentRequiredException;
use WatchTower15\Exception\PermissionException;
use WatchTower15\Exception\RateLimitException;
use WatchTower15\Exception\ServerException;
use WatchTower15\Exception\ServiceUnavailableException;
use WatchTower15\Exception\UnexpectedResponseException;

/**
 * Turns both WatchTower15 error shapes (problem+json and the house envelope) into the typed hierarchy.
 *
 * @internal
 */
final class ErrorMapper
{
    public const V1 = 'v1';

    public const INGEST = 'ingest';

    private function __construct()
    {
    }

    /**
     * Whether a 2xx answer has the success shape of its API.
     */
    public static function isSuccess(ResponseInterface $response, string $api): bool
    {
        $status = $response->getStatusCode();
        if ($status < 200 || $status > 299) {
            return false;
        }
        $body = Json::decodeObject((string) $response->getBody());
        if ($body === null) {
            return false;
        }

        return $api === self::INGEST ? ($body['status'] ?? null) === 'success' : array_key_exists('data', $body);
    }

    /**
     * The error code and its home in a body, without building an exception (for retry decisions).
     */
    public static function codeOf(ResponseInterface $response): ?string
    {
        $body = Json::decodeObject((string) $response->getBody());
        if ($body === null) {
            return null;
        }

        return self::codeFrom($body);
    }

    public static function map(ResponseInterface $response, string $api, string $operation): ApiException
    {
        $status = $response->getStatusCode();
        $headers = $response->getHeaders();
        $contentType = $response->getHeaderLine('Content-Type');
        $cfRay = $response->getHeaderLine('CF-Ray');
        $headerRequestId = $response->getHeaderLine('X-Request-Id');
        $retryAfter = self::retryAfter($response);

        if ($status >= 300 && $status <= 399) {
            $location = $response->getHeaderLine('Location');
            $host = $location !== '' ? parse_url($location, PHP_URL_HOST) : null;

            return new UnexpectedResponseException(
                sprintf('WatchTower15 API: redirect not followed (HTTP %d%s).', $status, is_string($host) && $host !== '' ? ', to ' . $host : ''),
                $status,
                $headers,
                $operation,
                $cfRay !== '' ? $cfRay : null,
                $contentType !== '' ? $contentType : null,
            );
        }

        $body = Json::decodeObject((string) $response->getBody());
        $isProblem = $body !== null && (
            str_contains(strtolower($contentType), 'application/problem+json')
            || (isset($body['code'], $body['status']) && is_string($body['code']) && is_int($body['status']))
        );
        $isEnvelope = $body !== null && ($body['status'] ?? null) === 'failure';

        if ($body === null || (! $isProblem && ! $isEnvelope)) {
            return new UnexpectedResponseException(
                sprintf(
                    'WatchTower15 API: an edge or proxy answered, not WatchTower15 (HTTP %d%s%s).',
                    $status,
                    $contentType !== '' ? ', ' . strtok($contentType, ';') : '',
                    $cfRay !== '' ? ', CF-Ray ' . $cfRay : '',
                ),
                $status,
                $headers,
                $operation,
                $cfRay !== '' ? $cfRay : null,
                $contentType !== '' ? $contentType : null,
                $headerRequestId !== '' ? $headerRequestId : null,
                $retryAfter,
            );
        }

        $code = self::codeFrom($body);
        $detail = null;
        if (isset($body['detail']) && is_string($body['detail'])) {
            $detail = $body['detail'];
        } elseif (isset($body['message']) && is_string($body['message'])) {
            $detail = $body['message'];
        }
        $requestId = isset($body['request_id']) && is_string($body['request_id']) && $body['request_id'] !== ''
            ? $body['request_id']
            : ($headerRequestId !== '' ? $headerRequestId : null);

        $parts = ['HTTP ' . $status];
        if ($code !== null) {
            $parts[] = $code;
        }
        if ($requestId !== null) {
            $parts[] = 'request ' . $requestId;
        }
        $message = sprintf('WatchTower15 API: %s (%s)', $detail ?? 'the request failed', implode(', ', $parts));

        $class = self::classFor($status, $code, $api, $body);

        return new $class($message, $status, $code, $detail, $requestId, $body, $headers, $operation, $retryAfter);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return class-string<ApiException>
     */
    private static function classFor(int $status, ?string $code, string $api, array $body): string
    {
        $ingest = $api === self::INGEST;

        return match (true) {
            $status === 400 => InvalidRequestException::class,
            $status === 401 => AuthenticationException::class,
            $status === 402 => PaymentRequiredException::class,
            $status === 403 => $ingest ? AuthenticationException::class : PermissionException::class,
            $status === 404 => NotFoundException::class,
            $status === 413 => PayloadTooLargeException::class,
            $status === 422 => ! $ingest && in_array($code, ['limit_reached', 'held_limit_reached'], true)
                ? LimitReachedException::class
                : InvalidRequestException::class,
            $status === 429 => match (true) {
                ! $ingest && $code === 'daily_limit_reached' => DailyLimitException::class,
                $ingest && $code === 'log_volume_limit_reached' => LogVolumeLimitException::class,
                default => RateLimitException::class,
            },
            $status === 503 => ServiceUnavailableException::class,
            $status >= 500 => ServerException::class,
            default => ApiException::class,
        };
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function codeFrom(array $body): ?string
    {
        if (isset($body['code']) && is_string($body['code'])) {
            return $body['code'];
        }
        $data = $body['data'] ?? null;
        if (is_array($data) && isset($data['code']) && is_string($data['code'])) {
            return $data['code'];
        }

        return null;
    }

    /** Seconds from a `Retry-After` delta or HTTP date, or null. */
    public static function retryAfter(ResponseInterface $response, ?\DateTimeImmutable $now = null): ?int
    {
        $value = trim($response->getHeaderLine('Retry-After'));
        if ($value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            return (int) $value;
        }
        $at = strtotime($value);
        if ($at === false) {
            return null;
        }
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return max(0, $at - $now->getTimestamp());
    }
}
