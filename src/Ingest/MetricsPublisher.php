<?php

declare(strict_types=1);

namespace WatchTower15\Ingest;

use WatchTower15\Http\RequestOptions;
use WatchTower15\Http\RetryPolicy;
use WatchTower15\Internal\Answer;
use WatchTower15\Internal\ChunkPusher;
use WatchTower15\Internal\ErrorMapper;
use WatchTower15\Internal\Json;
use WatchTower15\Internal\Requirement;
use WatchTower15\Internal\Secrets;
use WatchTower15\Internal\SecretsHandle;
use WatchTower15\Internal\Sender;

/**
 * Pushes custom metric points: `$wt15->ingest->metrics->push()` and `list()`.
 */
final class MetricsPublisher
{
    /** A point older than this many days is in the backend's backfill tier, where it demands an idempotency key. */
    public const BACKFILL_AGE_DAYS = 30;

    public const MAX_POINTS = 1000;

    public const MAX_BYTES = 1_000_000;

    private const PATH = '/api/metric-sources/ingest';

    private const TYPES = ['count', 'level'];

    /** @internal */
    public function __construct(
        private readonly Sender $sender,
        private readonly SecretsHandle $secrets,
        private readonly string $ingestUrl,
    ) {
    }

    /**
     * @param list<array<string, mixed>>               $points
     * @param RequestOptions|array<string, mixed>|null $options
     *
     * @throws InvalidPayloadException
     */
    public function push(array $points, ?string $idempotencyKey = null, RequestOptions|array|null $options = null): IngestResult
    {
        if ($points === []) {
            return IngestResult::empty('metrics');
        }
        Requirement::credential($this->secrets, Secrets::METRICS);
        $options = RequestOptions::from($options);
        $options->refuseIdempotencyKey('idempotencyKey');
        $now = $this->sender->now();
        $cutoff = $now->getTimestamp() - self::BACKFILL_AGE_DAYS * 86400;

        $encoded = [];
        $backfill = [];
        $index = 0;
        foreach ($points as $point) {
            /** @phpstan-ignore function.alreadyNarrowedType */
            if (! is_array($point)) {
                throw new InvalidPayloadException(sprintf('Point %d must be an array.', $index));
            }
            $hasUuid = isset($point['metric_uuid']) && $point['metric_uuid'] !== '';
            $type = $point['metric_type'] ?? null;
            if ($type === null && ! $hasUuid) {
                throw new InvalidPayloadException(sprintf('Point %d needs `metric_type` (`count` or `level`).', $index));
            }
            if ($type !== null && ! in_array($type, self::TYPES, true)) {
                throw new InvalidPayloadException(sprintf('Point %d has `metric_type` that is not `count` or `level`.', $index));
            }
            $recordedAt = $point['recorded_at'] ?? null;
            $backfill[] = self::seconds($recordedAt) !== null && self::seconds($recordedAt) < $cutoff;
            if ($recordedAt instanceof \DateTimeInterface) {
                $point['recorded_at'] = (int) $recordedAt->format('U') * 1000 + (int) $recordedAt->format('v');
            }
            try {
                $encoded[] = Json::encode($point);
            } catch (\JsonException $e) {
                throw new InvalidPayloadException(sprintf('Point %d cannot be encoded as JSON: %s.', $index, $e->getMessage()));
            }
            $index++;
        }

        return ChunkPusher::push(
            $this->sender,
            $this->secrets,
            'metrics',
            'points',
            'ingest.metrics.push',
            $this->ingestUrl . self::PATH,
            Secrets::METRICS,
            $encoded,
            $backfill,
            self::MAX_POINTS,
            self::MAX_BYTES,
            $idempotencyKey,
            $options,
        );
    }

    /**
     * The source's metrics, their status and the cap: a read-only `GET` on the ingest URL.
     *
     * @param RequestOptions|array<string, mixed>|null $options
     */
    public function list(RequestOptions|array|null $options = null): MetricSourceListing
    {
        Requirement::credential($this->secrets, Secrets::METRICS);
        /** @var Answer $answer */
        $answer = $this->sender->execute(
            ErrorMapper::INGEST,
            Secrets::METRICS,
            'ingest.metrics.list',
            'GET',
            $this->ingestUrl . self::PATH,
            RetryPolicy::Read,
            null,
            [],
            self::withoutKey($options),
        );

        return MetricSourceListing::fromData($answer->data());
    }

    /**
     * A time as the backend reads it: a date object, a number as an epoch (seconds below 100,000,000,000, else
     * milliseconds), a string of ten or more digits likewise, any other string as a UTC date. Unreadable is null.
     */
    private static function seconds(mixed $value): ?int
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }
        if (is_string($value) && preg_match('/\A\d{10,}\z/', $value) === 1) {
            $value = (int) $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) ($value >= 100_000_000_000 ? $value / 1000 : $value);
        }
        if (is_string($value) && trim($value) !== '') {
            $date = date_create_immutable($value, new \DateTimeZone('UTC'));

            return $date === false ? null : $date->getTimestamp();
        }

        return null;
    }

    /**
     * @param RequestOptions|array<string, mixed>|null $options
     */
    private static function withoutKey(RequestOptions|array|null $options): RequestOptions
    {
        $options = RequestOptions::from($options);
        $options->refuseIdempotencyKey();

        return $options;
    }
}
