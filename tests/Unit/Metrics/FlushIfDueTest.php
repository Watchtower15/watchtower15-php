<?php

declare(strict_types=1);

namespace WatchTower15\Tests\Unit\Metrics;

use PHPUnit\Framework\TestCase;
use WatchTower15\Lifecycle\ShutdownStrategy;
use WatchTower15\Metrics\Aggregator;
use WatchTower15\Store\FileStore;
use WatchTower15\Store\InMemoryStore;
use WatchTower15\Tests\Support\PipeHarness;
use WatchTower15\Tests\Support\SpyStore;

final class FlushIfDueTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/wt15-due-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    public function testP19ThreeProcessesFlushOncePerMinuteBetweenThem(): void
    {
        $h = new PipeHarness(store: new FileStore($this->directory));
        $two = new Aggregator($h->client, new FileStore($this->directory), clock: $h->clock);
        $three = new Aggregator($h->client, new FileStore($this->directory), clock: $h->clock);

        $first = [$h->aggregator->flushIfDue(), $two->flushIfDue(), $three->flushIfDue()];
        self::assertNotNull($first[0]);
        self::assertSame('flushed', $first[0]->status);
        self::assertNull($first[1]);
        self::assertNull($first[2]);

        $h->advance(60);
        $second = [$two->flushIfDue(), $h->aggregator->flushIfDue(), $three->flushIfDue()];
        self::assertNotNull($second[0]);
        self::assertNull($second[1]);
        self::assertNull($second[2]);
    }

    public function testP19AClaimThatThrowsGivesAStoreReportAndTakesNoLock(): void
    {
        $spy = new SpyStore(new InMemoryStore());
        $spy->throwOn['claimOnce'] = true;
        $h = new PipeHarness(store: $spy);

        $report = $h->aggregator->flushIfDue();

        self::assertNotNull($report);
        self::assertSame('flushed', $report->status);
        self::assertSame('store', $report->stopReason);
        self::assertSame(0, $spy->count('acquireLock'));
    }

    public function testP20RegisterShutdownFlushesFinallyForAProcessStoreAndIfDueForAnyOther(): void
    {
        $recording = new class () implements ShutdownStrategy {
            public ?\Closure $callback = null;

            public function register(\Closure $callback): void
            {
                $this->callback = $callback;
            }
        };

        $memory = new PipeHarness();
        $memory->aggregator->increment('a');          // in the open bucket
        $memory->accept();
        $memory->aggregator->registerShutdown($recording);
        self::assertNotNull($recording->callback);
        ($recording->callback)();
        self::assertSame(['a'], array_column($memory->sentPoints(), 'metric'), 'the open bucket is sent: a final flush');

        $recording->callback = null;
        $file = new PipeHarness(store: new FileStore($this->directory));
        $file->aggregator->registerShutdown($recording);
        self::assertNotNull($recording->callback);
        ($recording->callback)();
        self::assertSame([], $file->transport->requests());
        self::assertFalse((new FileStore($this->directory))->claimOnce('tick:' . $file->bucket(), 120), 'flushIfDue() claimed the minute');
    }

    public function testP20PhpShutdownStrategyUsesShutdownFunctionsAndRunsTheFlushLast(): void
    {
        $autoload = (new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName();
        self::assertIsString($autoload);
        $script = sys_get_temp_dir() . '/wt15-shutdown-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($script, '<?php require ' . var_export(dirname($autoload, 2) . '/autoload.php', true) . ';'
            . '(new WatchTower15\Lifecycle\PhpShutdownStrategy())->register(function () { echo "flush"; });'
            . 'register_shutdown_function(function () { echo "app,"; });'
            . 'echo function_exists("fastcgi_finish_request") ? "fpm," : "nofpm,", function_exists("litespeed_finish_request") ? "ls," : "nols,";');
        $process = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        proc_close($process);
        unlink($script);

        self::assertSame('nofpm,nols,app,flush', $out, 'neither finish function exists under the CLI, and the flush runs after the application\'s own');
    }
}
