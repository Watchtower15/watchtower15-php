<?php

declare(strict_types=1);

namespace WatchTower15\Store;

use WatchTower15\ConfigurationException;
use WatchTower15\Metrics\BucketStore;
use WatchTower15\Metrics\StoreScope;

/**
 * Minute buckets in APCu: one host's processes of one SAPI. APCu is not shared between PHP-FPM and the CLI, so the CLI flush
 * refuses this store. The prefix has no default: two PHP-FPM pools under one master share one APCu segment.
 */
final class ApcuStore implements BucketStore
{
    /**
     * @throws ConfigurationException when the prefix is empty
     */
    public function __construct(private readonly string $prefix)
    {
        if (trim($prefix) === '') {
            throw new ConfigurationException('ApcuStore needs a prefix, one per application: two PHP-FPM pools under one master share one APCu segment.');
        }
    }

    public function scope(): StoreScope
    {
        return StoreScope::Sapi;
    }

    public function increment(int $bucket, string $key, int $by, int $ttl): void
    {
        $this->enabled();
        $name = $this->bucketPrefix($bucket) . 'c:' . $key;
        apcu_add($name, 0, $ttl);
        apcu_inc($name, $by, $success);
        if (! $success) {
            throw new \RuntimeException('APCu did not accept the increment.');
        }
    }

    public function setLevel(int $bucket, string $key, int|float $value, int $ttl): void
    {
        $this->enabled();
        if (! apcu_store($this->bucketPrefix($bucket) . 'l:' . $key, (float) $value, $ttl)) {
            throw new \RuntimeException('APCu did not accept the level.');
        }
    }

    public function read(int $bucket): array
    {
        $this->enabled();
        $count = [];
        $level = [];
        $head = $this->bucketPrefix($bucket);
        foreach (new \APCUIterator('/^' . preg_quote($head, '/') . '/', APC_ITER_KEY | APC_ITER_VALUE) as $name => $entry) {
            $rest = substr((string) $name, strlen($head));
            $value = is_array($entry) ? ($entry['value'] ?? null) : null;
            if (str_starts_with($rest, 'c:') && is_numeric($value)) {
                $count[substr($rest, 2)] = (int) $value;
            } elseif (str_starts_with($rest, 'l:') && is_numeric($value)) {
                $level[substr($rest, 2)] = (float) $value;
            }
        }

        return ['count' => $count, 'level' => $level];
    }

    public function forget(int $bucket): void
    {
        $this->enabled();
        $head = $this->bucketPrefix($bucket);
        foreach (new \APCUIterator('/^' . preg_quote($head, '/') . '/', APC_ITER_KEY) as $name => $_) {
            apcu_delete((string) $name);
        }
    }

    public function lastFlushed(): ?int
    {
        $this->enabled();
        $last = apcu_fetch($this->prefix . ':last', $found);

        return $found && is_numeric($last) ? (int) $last : null;
    }

    public function markFlushed(int $bucket): void
    {
        $this->enabled();
        apcu_store($this->prefix . ':last', $bucket);
    }

    public function acquireLock(string $name, int $ttl): ?string
    {
        $this->enabled();
        $token = bin2hex(random_bytes(16));

        return apcu_add($this->prefix . ':lock:' . $name, $token, $ttl) ? $token : null;
    }

    public function releaseLock(string $name, string $token): void
    {
        $this->enabled();
        $key = $this->prefix . ':lock:' . $name;
        $held = apcu_fetch($key, $found);
        if ($found && $held === $token) {
            apcu_delete($key);
        }
    }

    public function claimOnce(string $name, int $ttl): bool
    {
        $this->enabled();

        return apcu_add($this->prefix . ':claim:' . $name, 1, $ttl);
    }

    public function addError(string $reason, int $by = 1): void
    {
        $this->enabled();
        $name = $this->prefix . ':errors:' . $reason;
        apcu_add($name, 0);
        apcu_inc($name, $by);
    }

    public function errors(): array
    {
        $this->enabled();
        $head = $this->prefix . ':errors:';
        $errors = [];
        foreach (new \APCUIterator('/^' . preg_quote($head, '/') . '/', APC_ITER_KEY | APC_ITER_VALUE) as $name => $entry) {
            $value = is_array($entry) ? ($entry['value'] ?? 0) : 0;
            if (is_numeric($value) && (int) $value > 0) {
                $errors[substr((string) $name, strlen($head))] = (int) $value;
            }
        }

        return $errors;
    }

    public function subtractErrors(array $counts): void
    {
        $this->enabled();
        foreach ($counts as $reason => $count) {
            apcu_dec($this->prefix . ':errors:' . $reason, $count);
        }
    }

    public function instanceId(): string
    {
        $this->enabled();
        $key = $this->prefix . ':instance';
        apcu_add($key, bin2hex(random_bytes(16)));
        $id = apcu_fetch($key);

        return is_string($id) ? $id : '';
    }

    private function bucketPrefix(int $bucket): string
    {
        return $this->prefix . ':b:60:' . $bucket . ':';
    }

    private function enabled(): void
    {
        if (! function_exists('apcu_enabled') || ! apcu_enabled()) {
            throw new \RuntimeException('APCu is not enabled');
        }
    }
}
