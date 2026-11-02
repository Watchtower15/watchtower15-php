<?php

declare(strict_types=1);

namespace WatchTower15\Metrics;

/**
 * What one flush did: one value the CLI prints, a bridge's command prints and a test asserts.
 */
final readonly class FlushReport
{
    /**
     * @param 'disabled'|'locked'|'flushed'  $status
     * @param array<string, int>             $rejected       Rejection reasons the server reported, summed (non-zero only).
     * @param ?string                        $stopReason     `auth`, `config`, `redirect`, `transport`, `store`, `internal` or `max_requests`.
     * @param array<string, int>             $errors         The error counters read by this run.
     * @param list<string>                   $configProblems
     */
    public function __construct(
        public string $status,
        public int $bucketsSent = 0,
        public int $bucketsEmpty = 0,
        public int $bucketsDropped = 0,
        public int $chunksDropped = 0,
        public int $requests = 0,
        public int $points = 0,
        public array $rejected = [],
        public ?string $stopReason = null,
        public array $errors = [],
        public array $configProblems = [],
        public bool $heartbeatAdded = false,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'buckets_sent' => $this->bucketsSent,
            'buckets_empty' => $this->bucketsEmpty,
            'buckets_dropped' => $this->bucketsDropped,
            'chunks_dropped' => $this->chunksDropped,
            'requests' => $this->requests,
            'points' => $this->points,
            'rejected' => $this->rejected,
            'stop_reason' => $this->stopReason,
            'errors' => $this->errors,
            'config_problems' => $this->configProblems,
            'heartbeat_added' => $this->heartbeatAdded,
        ];
    }
}
