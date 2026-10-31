<?php

declare(strict_types=1);

namespace WatchTower15\Ingest;

/**
 * What a push sent and what the server accepted, summed over every chunk.
 */
final readonly class IngestResult
{
    /** Every reason metric ingest can reject a point for; each is present at 0 when none was. */
    public const METRIC_REJECTION_REASONS = [
        'over_metric_cap',
        'metric_create_busy',
        'bad_metric_key',
        'unknown_metric_uuid',
        'ambiguous_identifier',
        'future_timestamp',
        'too_old',
        'non_numeric',
        'negative_on_count_metric',
        'over_point_rate',
        'over_backfill_budget',
        'missing_idempotency_key',
        'metric_key_deleted',
        'metric_awaiting_approval',
        'bad_metric_type',
    ];

    /**
     * @param 'metrics'|'logs'                  $kind
     * @param array<string, int>                $rejected
     * @param list<string>                      $batchIds
     * @param list<string>                      $idempotencyKeys
     * @param list<array<string, mixed>>        $raw Each chunk's `data`.
     */
    public function __construct(
        public string $kind,
        public bool $accepted,
        public int $count,
        public int $sent,
        public array $rejected,
        public array $batchIds,
        public array $idempotencyKeys,
        public int $chunks,
        public array $raw,
    ) {
    }

    /** @param 'metrics'|'logs' $kind */
    public static function empty(string $kind): self
    {
        $rejected = [];
        if ($kind === 'metrics') {
            foreach (self::METRIC_REJECTION_REASONS as $reason) {
                $rejected[$reason] = 0;
            }
        }

        return new self($kind, false, 0, 0, $rejected, [], [], 0, []);
    }

    public function rejectedTotal(): int
    {
        return array_sum($this->rejected);
    }
}
