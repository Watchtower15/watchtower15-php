<?php

declare(strict_types=1);

namespace WatchTower15\Ingest;

final readonly class SourceMetric
{
    public function __construct(
        public string $uuid,
        public string $key,
        public string $type,
        public string $status,
    ) {
    }

    /** @return array{uuid: string, key: string, type: string, status: string} */
    public function toArray(): array
    {
        return ['uuid' => $this->uuid, 'key' => $this->key, 'type' => $this->type, 'status' => $this->status];
    }
}
