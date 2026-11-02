<?php

declare(strict_types=1);

namespace WatchTower15\Internal;

use WatchTower15\Exception\ApiException;
use WatchTower15\Http\RequestOptions;
use WatchTower15\Http\RetryPolicy;
use WatchTower15\Ingest\IngestResult;
use WatchTower15\Ingest\InvalidPayloadException;

/**
 * The shared push: cut chunks under the caps, gzip, key each chunk, send in order, fold the answers.
 *
 * @internal
 */
final class ChunkPusher
{
    /** Set false to switch gzip off (tests). */
    public static bool $gzip = true;

    private const KEY_ALPHABET = '/\A[\x21-\x7E]{1,100}\z/';

    private function __construct()
    {
    }

    /**
     * @param 'metrics'|'logs'  $kind
     * @param list<string>      $items    Each item, encoded once.
     * @param list<bool>        $backfill Whether the item is old enough to need a content key (metrics only).
     */
    public static function push(
        Sender $sender,
        SecretsHandle $secrets,
        string $kind,
        string $envelopeKey,
        string $operation,
        string $url,
        string $credential,
        array $items,
        array $backfill,
        int $maxItems,
        int $maxBytes,
        ?string $callerKey,
        ?RequestOptions $options,
    ): IngestResult {
        if ($items === []) {
            return IngestResult::empty($kind);
        }
        if ($callerKey !== null && preg_match(self::KEY_ALPHABET, $callerKey) !== 1) {
            throw new InvalidPayloadException('The idempotency key must be 1 to 100 printable ASCII characters, with no space.');
        }

        $open = '{"' . $envelopeKey . '":[';
        $overhead = strlen($open) + 2;
        foreach ($items as $index => $item) {
            if (strlen($item) + $overhead > $maxBytes) {
                throw new InvalidPayloadException(sprintf('Item %d is %d bytes encoded, over the %d-byte cap of one request.', $index, strlen($item), $maxBytes));
            }
        }

        // Cut the chunks.
        /** @var list<array{json: string, count: int, backfill: bool}> $chunks */
        $chunks = [];
        $current = [];
        $size = $overhead;
        $old = false;
        foreach ($items as $index => $item) {
            $add = strlen($item) + ($current === [] ? 0 : 1);
            if ($current !== [] && (count($current) >= $maxItems || $size + $add > $maxBytes)) {
                $chunks[] = ['json' => $open . implode(',', $current) . ']}', 'count' => count($current), 'backfill' => $old];
                $current = [];
                $size = $overhead;
                $old = false;
                $add = strlen($item);
            }
            $current[] = $item;
            $size += $add;
            $old = $old || ($backfill[$index] ?? false);
        }
        $chunks[] = ['json' => $open . implode(',', $current) . ']}', 'count' => count($current), 'backfill' => $old];

        $tokenHash = $sender->tokenHash($credential);
        $nonce = bin2hex(random_bytes(16));
        $total = count($chunks);

        $accepted = false;
        $count = 0;
        $sent = 0;
        $rejected = IngestResult::empty($kind)->rejected;
        $batchIds = [];
        $keys = [];
        $raw = [];
        $done = 0;
        $countField = $kind === 'metrics' ? 'points' : 'lines';

        foreach ($chunks as $index => $chunk) {
            if ($callerKey !== null) {
                $key = $total === 1 ? $callerKey : $callerKey . '/' . $index;
            } else {
                $material = $tokenHash . "\n" . $index . "\n" . hash('sha256', $chunk['json']);
                if (! $chunk['backfill']) {
                    $material .= "\n" . $nonce;
                }
                $key = 'wt15php-' . hash('sha256', $material);
            }
            $body = $chunk['json'];
            if (self::$gzip && function_exists('gzencode')) {
                $encoded = gzencode($body, 6);
                if ($encoded !== false) {
                    $body = $encoded;
                }
            }

            try {
                $answer = $sender->execute(
                    ErrorMapper::INGEST,
                    $credential,
                    $operation,
                    'POST',
                    $url,
                    RetryPolicy::IdempotentWrite,
                    $body,
                    ['Content-Type' => 'application/json', 'Idempotency-Key' => $key],
                    $options,
                );
            } catch (ApiException $e) {
                if ($done > 0) {
                    $e->withPartialResult(new IngestResult($kind, $accepted, $count, $sent, $rejected, $batchIds, $keys, $done, $raw));
                }

                throw $e;
            }

            $data = $answer->data();
            $done++;
            $sent += $chunk['count'];
            $accepted = $accepted || ($data['accepted'] ?? false) === true;
            $n = $data[$countField] ?? 0;
            $count += is_int($n) ? $n : 0;
            if (isset($data['batch_id']) && is_string($data['batch_id'])) {
                $batchIds[] = $data['batch_id'];
            }
            $keys[] = $key;
            $raw[] = $data;
            if ($kind === 'metrics' && is_array($data['rejected'] ?? null)) {
                foreach ($data['rejected'] as $reason => $value) {
                    if (is_string($reason) && is_int($value)) {
                        $rejected[$reason] = ($rejected[$reason] ?? 0) + $value;
                    }
                }
            }
        }

        return new IngestResult($kind, $accepted, $count, $sent, $rejected, $batchIds, $keys, $done, $raw);
    }
}
