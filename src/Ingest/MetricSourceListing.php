<?php

declare(strict_types=1);

namespace WatchTower15\Ingest;

/**
 * The token's source: its metrics, their status and the organisation's metric cap.
 */
final readonly class MetricSourceListing
{
    /**
     * @param list<SourceMetric>   $metrics
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public array $metrics,
        public ?int $maxMetrics,
        public ?int $used,
        private array $raw = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     *
     * @internal
     */
    public static function fromData(array $data): self
    {
        $metrics = [];
        foreach (is_array($data['metrics'] ?? null) ? $data['metrics'] : [] as $metric) {
            if (! is_array($metric)) {
                continue;
            }
            $metrics[] = new SourceMetric(
                self::text($metric, 'uuid'),
                self::text($metric, 'key'),
                self::text($metric, 'type'),
                self::text($metric, 'status'),
            );
        }
        $limits = is_array($data['limits'] ?? null) ? $data['limits'] : [];
        $max = $limits['max_metrics'] ?? null;
        $used = $limits['used'] ?? null;

        return new self($metrics, is_int($max) ? $max : null, is_int($used) ? $used : null, $data);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }

    /**
     * @param array<array-key, mixed> $from
     */
    private static function text(array $from, string $key): string
    {
        $value = $from[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }
}
