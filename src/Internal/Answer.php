<?php

declare(strict_types=1);

namespace WatchTower15\Internal;

use Psr\Http\Message\ResponseInterface;

/**
 * A successful exchange: the response and the decoded body.
 *
 * @internal
 */
final readonly class Answer
{
    /**
     * @param array<string, mixed> $body
     */
    public function __construct(public ResponseInterface $response, public array $body)
    {
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        $data = $this->body['data'] ?? [];

        /** @var array<string, mixed> */
        return is_array($data) ? $data : [];
    }
}
