<?php

declare(strict_types=1);

namespace WatchTower15\Store;

use WatchTower15\Metrics\BucketStore;
use WatchTower15\Metrics\StoreScope;

/**
 * Minute buckets in files under `flock`: one host's processes, for bare PHP with neither APCu nor Redis. Local filesystems only
 * (not NFS). The directory is created, mode 0770, at the store's first write, never by its constructor.
 */
final class FileStore implements BucketStore
{
    /** @var array<string, array{token: string, handle: resource}> */
    private array $locks = [];

    public function __construct(private readonly string $directory, private readonly string $prefix = 'wt15')
    {
    }

    public function scope(): StoreScope
    {
        return StoreScope::Host;
    }

    public function increment(int $bucket, string $key, int $by, int $ttl): void
    {
        $this->change($this->bucketFile($bucket), function (array $data) use ($key, $by, $ttl): array {
            $counts = self::map($data['c'] ?? null);
            $counts[$key] = self::int($counts[$key] ?? 0) + $by;
            $data['c'] = $counts;
            $data['expires_at'] = time() + $ttl;

            return $data;
        }, ['c' => [], 'l' => [], 'expires_at' => 0]);
    }

    public function setLevel(int $bucket, string $key, int|float $value, int $ttl): void
    {
        $this->change($this->bucketFile($bucket), function (array $data) use ($key, $value, $ttl): array {
            $levels = self::map($data['l'] ?? null);
            $levels[$key] = (float) $value;
            $data['l'] = $levels;
            $data['expires_at'] = time() + $ttl;

            return $data;
        }, ['c' => [], 'l' => [], 'expires_at' => 0]);
    }

    public function read(int $bucket): array
    {
        $file = $this->bucketFile($bucket);
        $empty = ['count' => [], 'level' => []];
        if (! is_file($file)) {
            return $empty;
        }
        $data = $this->decode((string) file_get_contents($file));
        if (array_key_exists('expires_at', $data) && self::int($data['expires_at']) <= time()) {
            @unlink($file);

            return $empty;
        }
        $count = [];
        foreach (self::map($data['c'] ?? null) as $key => $value) {
            $count[$key] = self::int($value);
        }
        $level = [];
        foreach (self::map($data['l'] ?? null) as $key => $value) {
            $level[$key] = is_numeric($value) ? (float) $value : 0.0;
        }

        return ['count' => $count, 'level' => $level];
    }

    public function forget(int $bucket): void
    {
        $file = $this->bucketFile($bucket);
        if (is_file($file) && ! @unlink($file)) {
            throw new \RuntimeException('The bucket file could not be removed.');
        }
    }

    public function lastFlushed(): ?int
    {
        $file = $this->directory . '/' . $this->prefix . '.state.json';
        if (! is_file($file)) {
            return null;
        }
        $last = $this->decode((string) file_get_contents($file))['last'] ?? null;

        return is_int($last) ? $last : null;
    }

    public function markFlushed(int $bucket): void
    {
        $this->change($this->directory . '/' . $this->prefix . '.state.json', static function (array $data) use ($bucket): array {
            $data['last'] = $bucket;

            return $data;
        }, []);
        $this->sweep();
    }

    public function acquireLock(string $name, int $ttl): ?string
    {
        $this->ensureDirectory();
        $path = $this->directory . '/' . $this->prefix . '.lock.' . $this->safe($name);
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            throw new \RuntimeException('The lock file could not be opened.');
        }
        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }
        $token = bin2hex(random_bytes(16));
        $this->locks[$name] = ['token' => $token, 'handle' => $handle];

        return $token;
    }

    public function releaseLock(string $name, string $token): void
    {
        if (isset($this->locks[$name]) && hash_equals($this->locks[$name]['token'], $token)) {
            flock($this->locks[$name]['handle'], LOCK_UN);
            fclose($this->locks[$name]['handle']);
            unset($this->locks[$name]);
        }
    }

    public function claimOnce(string $name, int $ttl): bool
    {
        $this->ensureDirectory();
        $guard = @fopen($this->directory . '/' . $this->prefix . '.claim-guard', 'c');
        if ($guard === false || ! flock($guard, LOCK_EX)) {
            throw new \RuntimeException('The claim guard could not be locked.');
        }
        try {
            $path = $this->directory . '/' . $this->prefix . '.claim.' . $this->safe($name);
            if (is_file($path)) {
                $expiry = (int) trim((string) @file_get_contents($path));
                if ($expiry > time()) {
                    return false;
                }
                @unlink($path);
            }
            $handle = @fopen($path, 'x');
            if ($handle === false) {
                return false;
            }
            fwrite($handle, (string) (time() + $ttl));
            fclose($handle);

            return true;
        } finally {
            flock($guard, LOCK_UN);
            fclose($guard);
        }
    }

    public function addError(string $reason, int $by = 1): void
    {
        $this->change($this->directory . '/' . $this->prefix . '.errors.json', static function (array $data) use ($reason, $by): array {
            $data[$reason] = self::int($data[$reason] ?? 0) + $by;

            return $data;
        }, []);
    }

    public function errors(): array
    {
        $file = $this->directory . '/' . $this->prefix . '.errors.json';
        if (! is_file($file)) {
            return [];
        }
        $errors = [];
        foreach ($this->decode((string) file_get_contents($file)) as $reason => $count) {
            if (self::int($count) > 0) {
                $errors[(string) $reason] = self::int($count);
            }
        }

        return $errors;
    }

    public function subtractErrors(array $counts): void
    {
        $this->change($this->directory . '/' . $this->prefix . '.errors.json', static function (array $data) use ($counts): array {
            foreach ($counts as $reason => $count) {
                $left = self::int($data[$reason] ?? 0) - $count;
                if ($left > 0) {
                    $data[$reason] = $left;
                } else {
                    unset($data[$reason]);
                }
            }

            return $data;
        }, []);
    }

    public function instanceId(): string
    {
        $id = '';
        $this->change($this->directory . '/' . $this->prefix . '.state.json', static function (array $data) use (&$id): array {
            if (! isset($data['instance']) || ! is_string($data['instance']) || $data['instance'] === '') {
                $data['instance'] = bin2hex(random_bytes(16));
            }
            $id = $data['instance'];

            return $data;
        }, []);

        return $id;
    }

    /**
     * Open, lock, read, change, truncate, write, flush, unlock.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $change
     * @param array<string, mixed>                                 $initial
     */
    private function change(string $file, callable $change, array $initial): void
    {
        $this->ensureDirectory();
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('A store file could not be opened.');
        }
        try {
            if (! flock($handle, LOCK_EX)) {
                throw new \RuntimeException('A store file could not be locked.');
            }
            $data = $this->decode((string) stream_get_contents($handle));
            $data = $change($data + $initial);
            rewind($handle);
            ftruncate($handle, 0);
            if (fwrite($handle, json_encode($data, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)) === false) {
                throw new \RuntimeException('A store file could not be written.');
            }
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        if ($json === '') {
            return [];
        }
        $data = json_decode($json, true);

        /** @var array<string, mixed> */
        return is_array($data) ? $data : [];
    }

    /** Deletes the expired bucket files and the expired claim files. */
    private function sweep(): void
    {
        $now = time();
        foreach (glob($this->directory . '/' . $this->prefix . '.b60.*.json') ?: [] as $file) {
            $data = $this->decode((string) @file_get_contents($file));
            if (isset($data['expires_at']) && self::int($data['expires_at']) <= $now) {
                @unlink($file);
            }
        }
        foreach (glob($this->directory . '/' . $this->prefix . '.claim.*') ?: [] as $file) {
            if ((int) trim((string) @file_get_contents($file)) <= $now) {
                @unlink($file);
            }
        }
    }

    private function bucketFile(int $bucket): string
    {
        return $this->directory . '/' . $this->prefix . '.b60.' . $bucket . '.json';
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @return array<string, mixed>
     */
    private static function map(mixed $value): array
    {
        /** @var array<string, mixed> */
        return is_array($value) ? $value : [];
    }

    private function safe(string $name): string
    {
        return (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
    }

    private function ensureDirectory(): void
    {
        if (! is_dir($this->directory) && ! @mkdir($this->directory, 0770, true) && ! is_dir($this->directory)) {
            throw new \RuntimeException('The store directory could not be created.');
        }
    }
}
