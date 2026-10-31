<?php

declare(strict_types=1);

namespace WatchTower15\Exception;

/** No answer: DNS, connect, TLS, a timeout, or a body past the size cap. */
class ApiConnectionException extends ApiException
{
    public function __construct(
        string $message,
        private readonly bool $wasSent = true,
        private readonly bool $responseTooLarge = false,
        private readonly string $reason = 'other',
        string $operation = '',
    ) {
        parent::__construct($message, null, null, null, null, [], [], $operation);
    }

    /** False when the request certainly never reached the server (DNS, connect, TLS, a timeout before the write). */
    public function wasSent(): bool
    {
        return $this->wasSent;
    }

    public function isResponseTooLarge(): bool
    {
        return $this->responseTooLarge;
    }

    /** One of `dns`, `connect`, `tls`, `timeout`, `too_large`, `other`. */
    public function reason(): string
    {
        return $this->reason;
    }

    /** @internal */
    public function forOperation(string $operation): self
    {
        $copy = new self($this->getMessage(), $this->wasSent, $this->responseTooLarge, $this->reason, $operation);

        return $copy;
    }
}
