<?php

declare(strict_types=1);

namespace WatchTower15\Logs\Breaker;

use WatchTower15\Internal\Clock;
use WatchTower15\Internal\SystemClock;

/**
 * The breaker in files, shared by every PHP-FPM worker of a host at the cost of one `stat` for a host with nothing open: the entry
 * is `wt15-logs-<key>.breaker`, one line `<until ms> <reason>`, and the report times `wt15-logs-<key>.reported`, one
 * `<reason> <ms>` line per reason. A file is written through a temporary file in the same directory and `rename()`d, with mode
 * 0644. `clear()` reads, compares and unlinks without a lock. A directory that cannot be read or written counts as closed.
 */
final class FileBreakerStore implements BreakerStore
{
    private const HOUR_MS = 3_600_000;

    private readonly string $directory;

    private readonly Clock $clock;

    public function __construct(?string $directory = null, ?Clock $clock = null)
    {
        $this->directory = rtrim($directory ?? sys_get_temp_dir(), '/');
        $this->clock = $clock ?? new SystemClock();
    }

    public function read(string $key): ?Breaker
    {
        try {
            $path = $this->path($key, 'breaker');
            if (! is_file($path)) {
                return null;
            }
            $entry = $this->parse($path);
            if ($entry === null) {
                return null;
            }
            $limit = $this->nowMs() + self::HOUR_MS;
            if ($entry->untilMs > $limit) {
                $entry = new Breaker($limit, $entry->reason);
                $this->write($path, $entry->untilMs . ' ' . $entry->reason . "\n");
            }

            return $entry;
        } catch (\Throwable) {
            return null;
        }
    }

    public function open(string $key, int $untilMs, string $reason): void
    {
        try {
            $untilMs = min($untilMs, $this->nowMs() + self::HOUR_MS);
            $path = $this->path($key, 'breaker');
            $current = is_file($path) ? $this->parse($path) : null;
            if ($current !== null && $current->untilMs > $untilMs) {
                return;
            }
            $this->write($path, $untilMs . ' ' . $reason . "\n");
        } catch (\Throwable) {
            // A store that cannot be written counts as closed.
        }
    }

    public function clear(string $key, int $untilMs): void
    {
        try {
            $path = $this->path($key, 'breaker');
            $current = is_file($path) ? $this->parse($path) : null;
            if ($current !== null && $current->untilMs === $untilMs) {
                @unlink($path);
            }
        } catch (\Throwable) {
            // Ignored.
        }
    }

    public function lastReported(string $key, string $reason): ?int
    {
        try {
            $path = $this->path($key, 'reported');
            if (! is_file($path)) {
                return null;
            }
            foreach (explode("\n", (string) file_get_contents($path)) as $line) {
                $parts = explode(' ', trim($line));
                if (count($parts) === 2 && $parts[0] === $reason && ctype_digit($parts[1])) {
                    return (int) $parts[1];
                }
            }
        } catch (\Throwable) {
            // Ignored.
        }

        return null;
    }

    public function markReported(string $key, string $reason, int $atMs): void
    {
        try {
            $path = $this->path($key, 'reported');
            $times = [];
            if (is_file($path)) {
                foreach (explode("\n", (string) file_get_contents($path)) as $line) {
                    $parts = explode(' ', trim($line));
                    if (count($parts) === 2 && ctype_digit($parts[1])) {
                        $times[$parts[0]] = $parts[1];
                    }
                }
            }
            $times[$reason] = (string) $atMs;
            $text = '';
            foreach ($times as $name => $at) {
                $text .= $name . ' ' . $at . "\n";
            }
            $this->write($path, $text);
        } catch (\Throwable) {
            // Ignored.
        }
    }

    private function path(string $key, string $extension): string
    {
        $safe = preg_match('/\A[a-z0-9]{1,64}\z/', $key) === 1 ? $key : hash('sha256', $key);

        return $this->directory . '/wt15-logs-' . $safe . '.' . $extension;
    }

    private function parse(string $path): ?Breaker
    {
        $text = @file_get_contents($path);
        if (! is_string($text)) {
            return null;
        }
        $parts = explode(' ', trim($text), 2);
        if (count($parts) !== 2 || ! ctype_digit($parts[0]) || $parts[1] === '') {
            return null;
        }

        return new Breaker((int) $parts[0], $parts[1]);
    }

    private function write(string $path, string $content): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            return;
        }
        $temporary = tempnam($directory, 'wt15-tmp');
        if ($temporary === false) {
            return;
        }
        if (@file_put_contents($temporary, $content) === false) {
            @unlink($temporary);

            return;
        }
        @chmod($temporary, 0644);
        if (! @rename($temporary, $path)) {
            @unlink($temporary);
        }
    }

    private function nowMs(): int
    {
        return (int) floor((float) $this->clock->now()->format('U.u') * 1000);
    }
}
