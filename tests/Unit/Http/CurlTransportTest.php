<?php

declare(strict_types=1);

namespace WatchTower15\Tests\Unit\Http;

use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;
use WatchTower15\Exception\ApiConnectionException;
use WatchTower15\Http\CurlTransport;
use WatchTower15\Http\TransportOptions;

final class CurlTransportTest extends TestCase
{
    /** @var resource|null */
    private static $process = null;

    private static int $port = 0;

    public static function setUpBeforeClass(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($socket);
        self::$port = (int) substr((string) stream_socket_get_name($socket, false), strrpos((string) stream_socket_get_name($socket, false), ':') + 1);
        fclose($socket);
        $command = [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, __DIR__ . '/../../Fixtures/server.php'];
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['PHP_CLI_SERVER_WORKERS' => '4'] + getenv());
        self::assertIsResource($process);
        self::$process = $process;
        for ($i = 0; $i < 50; $i++) {
            $probe = @fsockopen('127.0.0.1', self::$port);
            if ($probe !== false) {
                fclose($probe);

                return;
            }
            usleep(100_000);
        }
        self::fail('the fixture server did not start');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$process !== null) {
            proc_terminate(self::$process);
            proc_close(self::$process);
        }
    }

    private function url(string $path): string
    {
        return 'http://127.0.0.1:' . self::$port . $path;
    }

    public function testH3ATimeoutFailsWithinTheBoundAndWasSent(): void
    {
        $started = microtime(true);
        try {
            (new CurlTransport())->send(new Request('GET', $this->url('/slow')), new TransportOptions(0.5, 0.5));
            self::fail('expected a timeout');
        } catch (ApiConnectionException $e) {
            self::assertLessThan(1.5, microtime(true) - $started);
            self::assertTrue($e->wasSent());
            self::assertSame('timeout', $e->reason());
        }
    }

    public function testH3AClosedPortFailsWithWasSentFalse(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        try {
            (new CurlTransport())->send(new Request('GET', 'http://' . $name . '/'), new TransportOptions(2.0, 1.0));
            self::fail('expected a connect failure');
        } catch (ApiConnectionException $e) {
            self::assertFalse($e->wasSent());
            self::assertNull($e->getPrevious());
        }
    }

    public function testH3ARedirectIsReturnedNotFollowed(): void
    {
        $response = (new CurlTransport())->send(new Request('GET', $this->url('/redirect')), new TransportOptions());

        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('elsewhere.example', $response->getHeaderLine('Location'));
    }

    public function testH3ABodyPastTheCapIsRefused(): void
    {
        try {
            (new CurlTransport())->send(new Request('GET', $this->url('/big')), new TransportOptions(5.0, 2.0, 1024 * 1024));
            self::fail('expected too large');
        } catch (ApiConnectionException $e) {
            self::assertTrue($e->isResponseTooLarge());
        }
    }

    public function testH3AnErrorStatusIsAResponseAndGzipIsDecoded(): void
    {
        $transport = new CurlTransport();

        self::assertSame(503, $transport->send(new Request('GET', $this->url('/unavailable')), new TransportOptions())->getStatusCode());
        self::assertSame('{"data":{"hello":"world"}}', (string) $transport->send(new Request('GET', $this->url('/gzip')), new TransportOptions())->getBody());
    }

    public function testHandleIsReusedAndBodiesAndHeadersAreSent(): void
    {
        $transport = new CurlTransport();
        $request = new Request('POST', $this->url('/echo'), ['Authorization' => 'Bearer abc', 'Content-Type' => 'application/json'], '{"x":1}');

        for ($i = 0; $i < 2; $i++) {
            $echo = json_decode((string) $transport->send($request, new TransportOptions())->getBody(), true);
            self::assertSame(['method' => 'POST', 'authorization' => 'Bearer abc', 'body' => '{"x":1}'], $echo);
        }
    }

    public function testH5TheOptionBuilder(): void
    {
        $transport = new CurlTransport();
        $request = new Request('POST', 'https://api.watchtower15.com/x', [], 'body');

        $normal = $transport->buildOptions($request, new TransportOptions(10.0, 2.0));
        self::assertTrue($normal[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(2, $normal[CURLOPT_SSL_VERIFYHOST]);
        self::assertFalse($normal[CURLOPT_FOLLOWLOCATION]);
        self::assertSame(CURLPROTO_HTTP | CURLPROTO_HTTPS, $normal[CURLOPT_PROTOCOLS]);
        self::assertSame(10_000, $normal[CURLOPT_TIMEOUT_MS]);
        self::assertSame(2_000, $normal[CURLOPT_CONNECTTIMEOUT_MS]);
        self::assertArrayNotHasKey(CURLOPT_NOSIGNAL, $normal);

        self::assertTrue($transport->buildOptions($request, new TransportOptions(0.5, 0.25))[CURLOPT_NOSIGNAL]);
        self::assertTrue($transport->buildOptions($request, new TransportOptions(5.0, 0.5))[CURLOPT_NOSIGNAL]);
        self::assertArrayNotHasKey(CURLOPT_NOSIGNAL, $transport->buildOptions($request, new TransportOptions(1.0, 1.0)));
    }
}
