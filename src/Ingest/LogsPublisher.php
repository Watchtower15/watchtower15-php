<?php

declare(strict_types=1);

namespace WatchTower15\Ingest;

use WatchTower15\Http\RequestOptions;
use WatchTower15\Internal\ChunkPusher;
use WatchTower15\Internal\Json;
use WatchTower15\Internal\Requirement;
use WatchTower15\Internal\Secrets;
use WatchTower15\Internal\SecretsHandle;
use WatchTower15\Internal\Sender;
use WatchTower15\Internal\Utf8;

/**
 * Pushes log lines: `$wt15->ingest->logs->push()`.
 */
final class LogsPublisher
{
    public const MAX_LINES = 1000;

    public const MAX_BYTES = 5_000_000;

    /** The server cuts `message` and `raw_line` here. */
    public const MAX_TEXT_BYTES = 65_536;

    private const PATH = '/api/log-sources/ingest';

    /** @internal */
    public function __construct(
        private readonly Sender $sender,
        private readonly SecretsHandle $secrets,
        private readonly string $ingestUrl,
    ) {
    }

    /**
     * @param list<array<string, mixed>>               $lines
     * @param RequestOptions|array<string, mixed>|null $options
     *
     * @throws InvalidPayloadException
     */
    public function push(array $lines, ?string $idempotencyKey = null, RequestOptions|array|null $options = null): IngestResult
    {
        if ($lines === []) {
            return IngestResult::empty('logs');
        }
        Requirement::credential($this->secrets, Secrets::LOGS);
        $options = RequestOptions::from($options);
        $options->refuseIdempotencyKey('idempotencyKey');

        $encoded = [];
        $index = 0;
        foreach ($lines as $line) {
            /** @phpstan-ignore function.alreadyNarrowedType */
            if (! is_array($line)) {
                throw new InvalidPayloadException(sprintf('Line %d must be an array.', $index));
            }
            $message = $line['message'] ?? null;
            if (! is_string($message) || trim($message) === '') {
                throw new InvalidPayloadException(sprintf('Line %d needs a `message` that is not blank.', $index));
            }
            $line['message'] = Utf8::cut($message, self::MAX_TEXT_BYTES);
            if (isset($line['raw_line']) && is_string($line['raw_line'])) {
                $line['raw_line'] = Utf8::cut($line['raw_line'], self::MAX_TEXT_BYTES);
            }
            if (($line['logged_at'] ?? null) instanceof \DateTimeInterface) {
                $at = \DateTimeImmutable::createFromInterface($line['logged_at'])->setTimezone(new \DateTimeZone('UTC'));
                $line['logged_at'] = $at->format('Y-m-d\TH:i:s.v\Z');
            }
            try {
                $encoded[] = Json::encode($line, true);
            } catch (\JsonException $e) {
                throw new InvalidPayloadException(sprintf('Line %d cannot be encoded as JSON: %s.', $index, $e->getMessage()));
            }
            $index++;
        }

        return ChunkPusher::push(
            $this->sender,
            $this->secrets,
            'logs',
            'lines',
            'ingest.logs.push',
            $this->ingestUrl . self::PATH,
            Secrets::LOGS,
            $encoded,
            [],
            self::MAX_LINES,
            self::MAX_BYTES,
            $idempotencyKey,
            $options,
        );
    }
}
