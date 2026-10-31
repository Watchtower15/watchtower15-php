<?php

declare(strict_types=1);

namespace WatchTower15\Tests\Unit\Cli;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use WatchTower15\Cli\Application;
use WatchTower15\Exception\ApiConnectionException;
use WatchTower15\Testing\MockTransport;
use WatchTower15\Tests\Support\FakeClock;
use WatchTower15\Tests\Support\Harness;
use WatchTower15\Tests\Support\Keys;

final class ApplicationTest extends TestCase
{
    /**
     * @param array<string, string> $env
     * @param list<string>          $args
     *
     * @return array{int, string, string}
     */
    private function runApp(array $env, array $args, MockTransport $transport): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($err);
        $app = new Application($env, $out, $err, $transport, new FakeClock(new \DateTimeImmutable('2026-10-31T12:00:03Z')));
        $code = $app->run($args);
        rewind($out);
        rewind($err);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    /** @return array<string, string> */
    private static function allCredentials(): array
    {
        return ['WT15_API_KEY' => Keys::apiKey(), 'WT15_METRICS_TOKEN' => Keys::metrics(), 'WT15_LOGS_TOKEN' => Keys::logs()];
    }

    private static function listing(): Response
    {
        return MockTransport::envelope(200, ['metrics' => [['uuid' => 'm1', 'key' => 'a.b', 'type' => 'count', 'status' => 'active'], ['uuid' => 'm2', 'key' => 'c.d', 'type' => 'level', 'status' => 'pending']], 'limits' => ['max_metrics' => 25, 'used' => 2]], 'ok', ['Date' => 'Sat, 31 Oct 2026 12:00:00 GMT']);
    }

    public function testL1NothingConfiguredExitsTwo(): void
    {
        [$code, , $err] = $this->runApp([], ['test'], new MockTransport());

        self::assertSame(2, $code);
        foreach (['WT15_API_KEY', 'WT15_METRICS_TOKEN', 'WT15_LOGS_TOKEN', 'process environment', 'export'] as $needle) {
            self::assertStringContainsString($needle, $err);
        }
    }

    public function testL2AFullTestPasses(): void
    {
        $transport = new MockTransport();
        $transport->append(Harness::meBody()->withHeader('Date', 'Sat, 31 Oct 2026 12:00:00 GMT'), self::listing());

        [$code, $out] = $this->runApp(self::allCredentials(), ['test'], $transport);

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('CI key', $out);
        self::assertStringContainsString('tags.view', $out);
        self::assertStringContainsString('Demo', $out);
        self::assertStringContainsString('1 active', $out);
        self::assertStringContainsString('1 pending', $out);
        self::assertStringContainsString('2 of 25 used', $out);
        self::assertStringContainsString('clock skew 3 s', $out);
        self::assertStringContainsString('not checked remotely', $out);
        foreach ([Keys::apiKey(), Keys::metrics(), Keys::logs()] as $secret) {
            self::assertStringNotContainsString(substr($secret, 7, 40), $out);
        }
    }

    public function testL3RefusalsAreReportedByKind(): void
    {
        $transport = new MockTransport();
        $transport->append(MockTransport::envelope(403, ['code' => 'forbidden'], 'No.'));
        [$code, $out] = $this->runApp(['WT15_METRICS_TOKEN' => Keys::metrics()], ['test'], $transport);
        self::assertSame(1, $code);
        self::assertStringContainsString('refused by WatchTower15 (403)', $out);

        $transport = new MockTransport();
        $transport->append(new Response(403, ['Content-Type' => 'text/html', 'CF-Ray' => 'abc-LHR'], '<html>'));
        [$code, $out] = $this->runApp(['WT15_METRICS_TOKEN' => Keys::metrics()], ['test'], $transport);
        self::assertSame(1, $code);
        self::assertStringContainsString('an edge or proxy answered (403, CF-Ray abc-LHR)', $out);
    }

    public function testL4AMisplacedCredentialExitsTwo(): void
    {
        [$code, , $err] = $this->runApp(['WT15_METRICS_TOKEN' => Keys::logs()], ['test'], new MockTransport());

        self::assertSame(2, $code);
        self::assertStringContainsString('WT15_METRICS_TOKEN', $err);
        self::assertStringContainsString('log source token', $err);
        self::assertStringNotContainsString(substr(Keys::logs(), 7, 40), $err);
    }

    public function testL5JsonOutput(): void
    {
        $transport = new MockTransport();
        $transport->append(Harness::meBody(), self::listing());

        [$code, $out] = $this->runApp(self::allCredentials(), ['test', '--json'], $transport);

        $decoded = json_decode($out, true);
        self::assertSame(0, $code);
        self::assertIsArray($decoded);
        self::assertTrue($decoded['ok']);
        self::assertSame(['api_key', 'metrics_token', 'logs_token'], array_column($decoded['checks'], 'name'));
    }

    public function testL6ReachabilityNeedsNoCredentialAndNeverReadsThem(): void
    {
        $unauthorised = MockTransport::problem(401, 'unauthorized');
        $forbidden = MockTransport::envelope(403, ['code' => 'forbidden'], 'No.');

        $transport = new MockTransport();
        $transport->append($unauthorised, $forbidden);
        [$code, $out] = $this->runApp([], ['test', '--reachability'], $transport);
        self::assertSame(0, $code, $out);
        self::assertSame(2, substr_count($out, 'reached WatchTower15'));
        foreach ($transport->requests() as $request) {
            self::assertNull($request->header('Authorization'));
        }
        self::assertStringEndsWith('/v1/me', $transport->requests()[0]->uri);
        self::assertStringEndsWith('/api/metric-sources/ingest', $transport->requests()[1]->uri);

        $transport = new MockTransport();
        $transport->append($unauthorised, new Response(403, ['Content-Type' => 'text/html', 'CF-Ray' => 'abc'], '<html>'));
        [$code, $out] = $this->runApp([], ['test', '--reachability'], $transport);
        self::assertSame(1, $code);
        self::assertStringContainsString('an edge or proxy answered', $out);
        self::assertStringContainsString('api.watchtower15.com', $out);

        $transport = new MockTransport();
        $transport->append($unauthorised, $forbidden);
        [$code] = $this->runApp(['WT15_API_KEY' => 'wt15ak_garbage'], ['test', '--reachability'], $transport);
        self::assertSame(0, $code);
    }

    public function testL7Version(): void
    {
        [$code, $out] = $this->runApp([], ['--version'], new MockTransport());

        self::assertSame(0, $code);
        self::assertStringStartsWith('watchtower15-php/', $out);
        self::assertMatchesRegularExpression('#^watchtower15-php/\S+ php/\d+\.\d+$#', trim($out));
    }

    public function testL9UnreachableHostsAreReportedAndTheOthersStillRun(): void
    {
        $transport = new MockTransport();
        $transport->append(MockTransport::problem(401, 'unauthorized'), new ApiConnectionException('refused', false, false, 'connect'));
        [$code, $out] = $this->runApp([], ['test', '--reachability'], $transport);
        self::assertSame(1, $code);
        self::assertStringContainsString('reached WatchTower15', $out);
        self::assertStringContainsString('not reached (connect): api.watchtower15.com', $out);

        $transport = new MockTransport();
        $timeout = new ApiConnectionException('slow', true, false, 'timeout');
        $transport->append($timeout, $timeout, $timeout, self::listing());
        [$code, $out] = $this->runApp(['WT15_API_KEY' => Keys::apiKey(), 'WT15_METRICS_TOKEN' => Keys::metrics()], ['test'], $transport);
        self::assertSame(1, $code);
        self::assertStringContainsString('not reached (timeout)', $out);
        self::assertStringContainsString('2 of 25 used', $out);
    }

    private static function realAutoload(): string
    {
        $file = (new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName();
        self::assertIsString($file);

        return dirname($file, 2) . '/autoload.php';
    }

    /** @return array{int, string} */
    private static function php(string $script, string $prepend = ''): array
    {
        $args = [PHP_BINARY];
        if ($prepend !== '') {
            $args[] = '-d';
            $args[] = 'auto_prepend_file=' . $prepend;
        }
        array_push($args, $script, '--version');
        $process = proc_open($args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['PATH' => (string) getenv('PATH')]);
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out];
    }

    public function testL8TheBinScriptFindsTheAutoloaderBothWays(): void
    {
        $bin = dirname(__DIR__, 3) . '/bin/watchtower15';
        self::assertTrue(is_executable($bin), 'bin/watchtower15 is mode 755');

        $tmp = sys_get_temp_dir() . '/wt15-bin-' . bin2hex(random_bytes(4));
        mkdir($tmp, 0777, true);
        file_put_contents($tmp . '/prepend.php', "<?php \$GLOBALS['_composer_autoload_path'] = " . var_export(self::realAutoload(), true) . ';');
        [$code, $out] = self::php($bin, $tmp . '/prepend.php');
        self::assertSame(0, $code);
        self::assertStringStartsWith('watchtower15-php/', $out);

        mkdir($tmp . '/vendor/watchtower15/sdk/bin', 0777, true);
        copy($bin, $tmp . '/vendor/watchtower15/sdk/bin/watchtower15');
        file_put_contents($tmp . '/vendor/autoload.php', '<?php require ' . var_export(self::realAutoload(), true) . ';');
        [$code, $out] = self::php($tmp . '/vendor/watchtower15/sdk/bin/watchtower15');
        self::assertSame(0, $code);
        self::assertStringStartsWith('watchtower15-php/', $out);

        foreach ([$tmp . '/vendor/watchtower15/sdk/bin/watchtower15', $tmp . '/vendor/autoload.php', $tmp . '/prepend.php'] as $file) {
            unlink($file);
        }
        rmdir($tmp . '/vendor/watchtower15/sdk/bin');
        rmdir($tmp . '/vendor/watchtower15/sdk');
        rmdir($tmp . '/vendor/watchtower15');
        rmdir($tmp . '/vendor');
        rmdir($tmp);
    }
}
