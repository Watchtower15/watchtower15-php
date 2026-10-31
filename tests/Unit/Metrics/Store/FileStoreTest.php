<?php

declare(strict_types=1);

namespace WatchTower15\Tests\Unit\Metrics\Store;

use WatchTower15\Metrics\BucketStore;
use WatchTower15\Metrics\StoreScope;
use WatchTower15\Store\FileStore;

final class FileStoreTest extends BucketStoreContract
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/wt15-filestore-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    protected function store(): BucketStore
    {
        return new FileStore($this->directory);
    }

    protected function sameBackend(): BucketStore
    {
        return new FileStore($this->directory);
    }

    protected function otherPrefix(): BucketStore
    {
        return new FileStore($this->directory, 'other');
    }

    protected function scope(): StoreScope
    {
        return StoreScope::Host;
    }

    protected function expireClaim(BucketStore $store, string $name): void
    {
        file_put_contents($this->directory . '/wt15.claim.' . preg_replace('/[^A-Za-z0-9._-]/', '_', $name), (string) (time() - 10));
    }

    protected function bucketTtl(BucketStore $store, int $bucket): ?int
    {
        $data = json_decode((string) file_get_contents($this->directory . '/wt15.b60.' . $bucket . '.json'), true);

        return is_array($data) ? (int) $data['expires_at'] - time() : null;
    }

    protected function brokenStore(): ?BucketStore
    {
        // A path under a regular file can never be a directory.
        $file = tempnam(sys_get_temp_dir(), 'wt15');
        self::assertIsString($file);
        register_shutdown_function(static fn () => @unlink($file));

        return new FileStore($file . '/inside');
    }

    public function testTheDirectoryIsCreatedAtTheFirstWriteNotByTheConstructor(): void
    {
        $store = new FileStore($this->directory);
        self::assertDirectoryDoesNotExist($this->directory);
        $store->increment(1, 'a', 1, 60);

        self::assertDirectoryExists($this->directory);
    }

    public function testS6MarkFlushedSweepsExpiredClaimsAndBucketFiles(): void
    {
        $store = $this->store();
        $store->increment(1, 'a', 1, 60);   // creates the directory
        file_put_contents($this->directory . '/wt15.claim.tick_5', (string) (time() - 10));
        file_put_contents($this->directory . '/wt15.b60.3.json', json_encode(['c' => ['a' => 1], 'l' => [], 'expires_at' => time() - 10]));
        file_put_contents($this->directory . '/wt15.claim.tick_9', (string) (time() + 600));

        $store->markFlushed(8);

        self::assertFileDoesNotExist($this->directory . '/wt15.claim.tick_5');
        self::assertFileDoesNotExist($this->directory . '/wt15.b60.3.json');
        self::assertFileExists($this->directory . '/wt15.claim.tick_9');
        self::assertFileExists($this->directory . '/wt15.b60.1.json');
    }

    public function testAnExpiredBucketFileReadsAsEmptyAndIsDeleted(): void
    {
        $store = $this->store();
        $store->increment(1, 'a', 1, 60);
        file_put_contents($this->directory . '/wt15.b60.1.json', json_encode(['c' => ['a' => 1], 'l' => [], 'expires_at' => time() - 1]));

        self::assertSame(['count' => [], 'level' => []], $store->read(1));
        self::assertFileDoesNotExist($this->directory . '/wt15.b60.1.json');
    }

    public function testS9EightProcessesCountExactly(): void
    {
        $this->store()->increment(2, 'warm', 1, 60);   // the directory exists before the children race to make it
        $this->assertConcurrentIncrementsAreExact(['store' => 'file', 'directory' => $this->directory, 'prefix' => 'wt15'], $this->store());
    }
}
