<?php

declare(strict_types=1);

namespace WatchTower15\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use WatchTower15\Cli\Application;
use WatchTower15\Metrics\Aggregator;
use WatchTower15\Store\ApcuStore;
use WatchTower15\Store\FileStore;
use WatchTower15\Store\InMemoryStore;
use WatchTower15\Testing\MockTransport;
use WatchTower15\Tests\Support\PipeHarness;

final class FlushCommandTest extends TestCase
{
    private string $directory;

    private string $bootstrap;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/wt15-flushcmd-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0770, true);
        $this->bootstrap = $this->directory . '/bootstrap.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wt15_test_aggregator']);
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    /**
     * @param array<string, string> $env
     * @param list<string>         $args
     *
     * @return array{int, string, string}
     */
    private function flush(array $args, array $env = []): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($err);
        $code = (new Application($env, $out, $err))->run(array_merge(['flush'], $args));
        rewind($out);
        rewind($err);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    private function returning(string $php): string
    {
        file_put_contents($this->bootstrap, '<?php ' . $php);

        return '--bootstrap=' . $this->bootstrap;
    }

    /** @param array<string, mixed> $aggregator */
    private function fileHarness(array $aggregator = [], string $sub = 'store'): PipeHarness
    {
        return new PipeHarness($aggregator, store: new FileStore($this->directory . '/' . $sub));
    }

    public function testL1TheRefusals(): void
    {
        [$code, , $err] = $this->flush([]);
        self::assertSame(2, $code);
        self::assertStringContainsString('--bootstrap', $err);
        self::assertStringContainsString('WT15_METRICS_BOOTSTRAP', $err);

        [$code, , $err] = $this->flush([$this->returning('return new WatchTower15\Client();')]);
        self::assertSame(2, $code);
        self::assertStringContainsString('must return an Aggregator', $err);

        [$code, , $err] = $this->flush(['--bootstrap=' . $this->directory . '/missing.php']);
        self::assertSame(2, $code);
        self::assertStringContainsString('missing.php', $err);

        [$code, , $err] = $this->flush([$this->returning('return new WatchTower15\Store\ApcuStore("");')]);
        self::assertSame(2, $code);
        self::assertStringContainsString('bootstrap.php', $err);
        self::assertStringContainsString('ConfigurationException', $err);
        self::assertStringContainsString('needs a prefix', $err);
    }

    public function testL1TheBootstrapPathCanComeFromTheEnvironment(): void
    {
        $this->returning('return new WatchTower15\Client();');

        [$code, , $err] = $this->flush([], ['WT15_METRICS_BOOTSTRAP' => $this->bootstrap]);

        self::assertSame(2, $code);
        self::assertStringContainsString('must return an Aggregator', $err);
    }

    public function testL2StoresThatCannotBeSeenFromTheCliAreRefused(): void
    {
        $GLOBALS['wt15_test_aggregator'] = new Aggregator((new PipeHarness())->client, new InMemoryStore());
        [$code, , $err] = $this->flush([$this->returning('return $GLOBALS["wt15_test_aggregator"];')]);
        self::assertSame(2, $code);
        self::assertStringContainsString('nothing to flush from here', $err);

        $GLOBALS['wt15_test_aggregator'] = new Aggregator((new PipeHarness())->client, new ApcuStore('p'));
        [$code, , $err] = $this->flush([$this->returning('return $GLOBALS["wt15_test_aggregator"];')]);
        self::assertSame(2, $code);
        self::assertStringContainsString('APCu is not shared between the CLI and PHP-FPM', $err);
    }

    public function testL2TheApcuStoreIsRefusedInAChildWithoutTheExtension(): void
    {
        $autoload = (new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName();
        self::assertIsString($autoload);
        file_put_contents($this->directory . '/prepend.php', "<?php \$GLOBALS['_composer_autoload_path'] = " . var_export(dirname($autoload, 2) . '/autoload.php', true) . ';');
        $this->returning('return new WatchTower15\Aggregator\Dummy();');
        file_put_contents($this->bootstrap, '<?php return new WatchTower15\Metrics\Aggregator(new WatchTower15\Client(), new WatchTower15\Store\ApcuStore("p"));');
        $process = proc_open(
            [PHP_BINARY, '-n', '-d', 'auto_prepend_file=' . $this->directory . '/prepend.php', dirname(__DIR__, 3) . '/bin/watchtower15', 'flush', '--bootstrap=' . $this->bootstrap],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $err = (string) stream_get_contents($pipes[2]);
        stream_get_contents($pipes[1]);
        $code = proc_close($process);

        self::assertSame(2, $code, $err);
        self::assertStringContainsString('APCu is not shared between the CLI and PHP-FPM', $err);
    }

    public function testL3AFlushIsReportedAndARefusalExitsOne(): void
    {
        $h = $this->fileHarness();
        $h->clock->now = new \DateTimeImmutable('@' . (PipeHarness::T0 - 50));
        $h->aggregator->increment('a');
        $h->clock->now = new \DateTimeImmutable('@' . (PipeHarness::T0 + 10));
        $h->accept();
        $GLOBALS['wt15_test_aggregator'] = $h->aggregator;

        [$code, $out] = $this->flush([$this->returning('return $GLOBALS["wt15_test_aggregator"];')]);

        self::assertSame(0, $code);
        self::assertStringContainsString('1 bucket(s) sent', $out);
        self::assertStringContainsString('1 request(s)', $out);
        self::assertStringContainsString('1 point(s)', $out);

        $h2 = $this->fileHarness([], 'store2');
        $h2->clock->now = new \DateTimeImmutable('@' . (PipeHarness::T0 + 20));
        $h2->store->increment(intdiv(PipeHarness::T0, 60) - 1, 'b', 1, 7200);
        $h2->transport->append(MockTransport::envelope(403, ['code' => 'forbidden'], 'No'));
        $GLOBALS['wt15_test_aggregator'] = $h2->aggregator;
        [$code, $out] = $this->flush([$this->returning('return $GLOBALS["wt15_test_aggregator"];')]);

        self::assertSame(1, $code);
        self::assertStringContainsString('Stopped: auth', $out);
    }

    public function testL4DisabledAndLockedExitZero(): void
    {
        $h = $this->fileHarness(['enabled' => false]);
        $GLOBALS['wt15_test_aggregator'] = $h->aggregator;
        [$code, $out] = $this->flush([$this->returning('return $GLOBALS["wt15_test_aggregator"];')]);
        self::assertSame(0, $code);
        self::assertStringContainsString('disabled', $out);

        $locked = $this->fileHarness();
        $token = $locked->store->acquireLock('flush', 120);
        self::assertIsString($token);
        $GLOBALS['wt15_test_aggregator'] = $locked->aggregator;
        [$code, $out] = $this->flush([$this->returning('return $GLOBALS["wt15_test_aggregator"];')]);
        self::assertSame(0, $code);
        self::assertStringContainsString('Another flush is running', $out);
    }

    public function testL5JsonOutput(): void
    {
        $h = $this->fileHarness(['enabled' => false]);
        $GLOBALS['wt15_test_aggregator'] = $h->aggregator;

        [$code, $out] = $this->flush(['--json', $this->returning('return $GLOBALS["wt15_test_aggregator"];')]);

        $decoded = json_decode($out, true);
        self::assertSame(0, $code);
        self::assertIsArray($decoded);
        self::assertSame('disabled', $decoded['status']);
        self::assertArrayHasKey('buckets_sent', $decoded);
    }
}
