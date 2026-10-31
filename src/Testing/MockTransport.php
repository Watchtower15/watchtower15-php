<?php

declare(strict_types=1);

namespace WatchTower15\Testing;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use WatchTower15\Http\Transport;
use WatchTower15\Http\TransportOptions;
use WatchTower15\Ingest\IngestResult;
use WatchTower15\Internal\Json;

/**
 * A transport that answers from a queue and records what it was asked, for tests that must not touch a network.
 */
final class MockTransport implements Transport
{
    /** @var list<ResponseInterface|\Throwable> */
    private array $queue = [];

    /** @var list<RecordedRequest> */
    private array $requests = [];

    /** @var (callable(RequestInterface): ?ResponseInterface)|null */
    private $fallback = null;

    public function append(ResponseInterface|\Throwable ...$responses): self
    {
        foreach ($responses as $response) {
            $this->queue[] = $response;
        }

        return $this;
    }

    /**
     * Answers a request when nothing is queued; return null to leave it unanswered.
     *
     * @param callable(RequestInterface): ?ResponseInterface $fallback
     */
    public function fallback(callable $fallback): self
    {
        $this->fallback = $fallback;

        return $this;
    }

    public function send(#[\SensitiveParameter] RequestInterface $request, TransportOptions $options): ResponseInterface
    {
        $this->requests[] = RecordedRequest::from($request, $options);
        if ($this->queue !== []) {
            $next = array_shift($this->queue);
            if ($next instanceof \Throwable) {
                throw $next;
            }

            return $next;
        }
        if ($this->fallback !== null) {
            $response = ($this->fallback)($request);
            if ($response !== null) {
                return $response;
            }
        }

        throw new \LogicException(sprintf('MockTransport has no answer queued for %s %s.', $request->getMethod(), $request->getUri()->getPath()));
    }

    /** @return list<RecordedRequest> */
    public function requests(): array
    {
        return $this->requests;
    }

    /** @param array<string, mixed>|list<mixed> $data
     * @param array<string, string>        $headers
     */
    public static function json(array $data, int $status = 200, array $headers = []): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'] + $headers, Json::encode($data));
    }

    /**
     * A `/v1` problem+json answer.
     *
     * @param array<string, mixed>  $extra
     * @param array<string, string> $headers
     */
    public static function problem(int $status, string $code, string $detail = 'Problem.', array $extra = [], array $headers = []): Response
    {
        $body = ['type' => 'https://docs.watchtower15.com/errors/' . $code, 'title' => $code, 'status' => $status, 'code' => $code, 'detail' => $detail, 'request_id' => 'req-' . $code] + $extra;

        return new Response($status, ['Content-Type' => 'application/problem+json', 'X-Request-Id' => 'req-' . $code] + $headers, Json::encode($body));
    }

    /**
     * A house-envelope answer (ingest's shape).
     *
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     * @param array<string, mixed>  $errors
     */
    public static function envelope(int $status, array $data = [], string $message = 'ok', array $headers = [], array $errors = []): Response
    {
        $body = [
            'status' => $status >= 200 && $status < 300 ? 'success' : 'failure',
            'status_code' => $status,
            'data' => $data,
            'request_id' => 'req-envelope',
            'timestamp' => 1_798_761_600_000,
            'message' => $message,
        ];
        if ($errors !== []) {
            $body['errors'] = $errors;
        }

        return new Response($status, ['Content-Type' => 'application/json', 'X-Request-Id' => 'req-envelope'] + $headers, Json::encode($body));
    }

    /** @param array<string, int> $rejected Reasons by count; every other reason is 0. */
    public static function metricsAccepted(int $points, array $rejected = [], string $batchId = 'b0000000-0000-4000-8000-000000000001'): Response
    {
        return self::envelope(200, [
            'accepted' => $points > 0,
            'batch_id' => $batchId,
            'points' => $points,
            'rejected' => $rejected + array_fill_keys(IngestResult::METRIC_REJECTION_REASONS, 0),
        ]);
    }

    public static function logsAccepted(int $lines, string $batchId = 'b0000000-0000-4000-8000-000000000002'): Response
    {
        return self::envelope(200, ['accepted' => $lines > 0, 'batch_id' => $batchId, 'lines' => $lines]);
    }
}
