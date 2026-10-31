<?php

declare(strict_types=1);

namespace WatchTower15\Exception;

use WatchTower15\Ingest\IngestResult;

/**
 * A call WatchTower15 (or whatever answered in its place) did not accept. The base of the typed family.
 */
class ApiException extends \RuntimeException implements WatchTower15Exception
{
    private ?IngestResult $partialResult = null;

    /**
     * @param array<string, mixed>        $problem The decoded body (empty when it was not JSON).
     * @param array<array<string>> $headers
     */
    public function __construct(
        string $message,
        private readonly ?int $statusCode = null,
        private readonly ?string $errorCode = null,
        private readonly ?string $detail = null,
        private readonly ?string $requestId = null,
        private readonly array $problem = [],
        private readonly array $headers = [],
        private readonly string $operation = '',
        private readonly ?int $retryAfter = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    /** The problem's `code`, or the envelope's `data.code`. */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function getDetail(): ?string
    {
        return $this->detail;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    /** @return array<string, mixed> */
    public function getProblem(): array
    {
        return $this->problem;
    }

    /** @return array<array<string>> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /** Seconds the server asked the caller to wait, or null when it sent none. */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }

    public function getOperation(): string
    {
        return $this->operation;
    }

    /** What the chunks accepted before this failure hold, for a push that failed part way. */
    public function partialResult(): ?IngestResult
    {
        return $this->partialResult;
    }

    /** @internal */
    public function withPartialResult(IngestResult $result): static
    {
        $this->partialResult = $result;

        return $this;
    }
}
