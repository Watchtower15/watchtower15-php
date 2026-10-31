<?php

declare(strict_types=1);

namespace WatchTower15\Tests\Support;

use Psr\Log\AbstractLogger;

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    public array $records = [];

    /** @param array<mixed> $context */
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }

    /** @return list<array{level: string, message: string, context: array<mixed>}> */
    public function level(string $level): array
    {
        return array_values(array_filter($this->records, static fn (array $r): bool => $r['level'] === $level));
    }
}
