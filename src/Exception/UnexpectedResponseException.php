<?php

declare(strict_types=1);

namespace WatchTower15\Exception;

/** Something answered that is not WatchTower15's own answer: an edge page, a proxy, a redirect, an empty 200. */
class UnexpectedResponseException extends ApiException
{
    /**
     * @param array<array<string>> $headers
     */
    public function __construct(
        string $message,
        ?int $statusCode = null,
        array $headers = [],
        string $operation = '',
        private readonly ?string $cfRay = null,
        private readonly ?string $contentType = null,
        ?string $requestId = null,
        ?int $retryAfter = null,
    ) {
        parent::__construct($message, $statusCode, null, null, $requestId, [], $headers, $operation, $retryAfter);
    }

    public function cfRay(): ?string
    {
        return $this->cfRay;
    }

    public function contentType(): ?string
    {
        return $this->contentType;
    }
}
