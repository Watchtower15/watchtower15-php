<?php

declare(strict_types=1);

namespace WatchTower15\Tests\Unit\Metrics\Store;

use WatchTower15\ConfigurationException;
use WatchTower15\Metrics\BucketStore;
use WatchTower15\Metrics\StoreScope;
use WatchTower15\Store\ApcuStore;

final class ApcuStoreTest extends BucketStoreContract
{
    private string $prefix;

    protected function setUp(): void
    {
        $this->prefix = 'wt15test' . bin2hex(random_bytes(6));
        if (! function_exists('apcu_enabled') || ! apcu_enabled()) {
            getenv('WT15_SDK_TEST_REQUIRE_SERVICES') === '1' ? self::fail('APCu is not enabled, and the services are required.') : self::markTestSkipped('APCu is not enabled');
        }
        apcu_clear_cache();
    }

    protected function tearDown(): void
    {
        if (function_exists('apcu_clear_cache')) {
            apcu_clear_cache();
        }
    }

    protected function store(): BucketStore
    {
        return new ApcuStore($this->prefix);
    }

    protected function sameBackend(): BucketStore
    {
        return new ApcuStore($this->prefix);
    }

    protected function otherPrefix(): BucketStore
    {
        return new ApcuStore($this->prefix . 'other');
    }

    protected function scope(): StoreScope
    {
        return StoreScope::Sapi;
    }

    protected function expireClaim(BucketStore $store, string $name): void
    {
        sleep(2);
    }

    protected function brokenStore(): ?BucketStore
    {
        return null;   // see testS11WithApcuDisabled: the extension is on here
    }

    public function testS8AnEmptyPrefixIsRefusedAtConstruction(): void
    {
        $this->expectException(ConfigurationException::class);
        new ApcuStore('');
    }

    public function testS11WithApcuDisabledEveryCallThrowsButConstructionAndScopeWork(): void
    {
        // A child process without ini files: APCu is not loaded there.
        $code = '<?php require ' . var_export(self::autoload(), true) . ';'
            . '$s = new WatchTower15\Store\ApcuStore("p"); echo $s->scope()->name, "|";'
            . 'foreach (["increment" => fn() => $s->increment(1,"a",1,60), "read" => fn() => $s->read(1), "lock" => fn() => $s->acquireLock("f",60),'
            . ' "claim" => fn() => $s->claimOnce("t",60), "error" => fn() => $s->addError("x"), "id" => fn() => $s->instanceId()] as $n => $f) {'
            . ' try { $f(); echo $n, ":no "; } catch (RuntimeException $e) { echo $n, ":", $e->getMessage(), " "; } }';
        $file = sys_get_temp_dir() . '/wt15-apcu-off-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($file, $code);
        $process = proc_open([PHP_BINARY, '-n', $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        proc_close($process);
        unlink($file);

        self::assertStringStartsWith('Sapi|', $out);
        foreach (['increment', 'read', 'lock', 'claim', 'error', 'id'] as $name) {
            self::assertStringContainsString($name . ':APCu is not enabled', $out);
        }
    }
}
