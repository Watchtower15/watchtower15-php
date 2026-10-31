<?php

declare(strict_types=1);

namespace WatchTower15\Ingest;

use WatchTower15\Internal\SecretsHandle;
use WatchTower15\Internal\Sender;

/**
 * `$wt15->ingest`: the metric and log publishers.
 */
final readonly class IngestServices
{
    public MetricsPublisher $metrics;

    public LogsPublisher $logs;

    /** @internal */
    public function __construct(Sender $sender, SecretsHandle $secrets, string $ingestUrl)
    {
        $this->metrics = new MetricsPublisher($sender, $secrets, $ingestUrl);
        $this->logs = new LogsPublisher($sender, $secrets, $ingestUrl);
    }
}
