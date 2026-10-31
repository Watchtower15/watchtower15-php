<?php

declare(strict_types=1);

namespace WatchTower15\Http;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use WatchTower15\Exception\ApiConnectionException;

/**
 * The default transport: cURL, one reusable handle, per-request timeouts, no redirects, TLS always verified.
 */
final class CurlTransport implements Transport
{
    private ?\CurlHandle $handle = null;

    public function send(#[\SensitiveParameter] RequestInterface $request, TransportOptions $options): ResponseInterface
    {
        $handle = $this->handle ??= curl_init();
        curl_reset($handle);

        $statusLine = '';
        /** @var array<array<string>> $headers */
        $headers = [];
        $body = '';
        $tooLarge = false;
        $max = $options->maxResponseBytes;

        $curlOptions = $this->buildOptions($request, $options);
        $curlOptions[CURLOPT_HEADERFUNCTION] = static function ($ch, string $line) use (&$statusLine, &$headers): int {
            $length = strlen($line);
            $trimmed = trim($line);
            if ($trimmed === '') {
                return $length;
            }
            if (str_starts_with($trimmed, 'HTTP/')) {
                $statusLine = $trimmed;
                $headers = [];

                return $length;
            }
            $parts = explode(':', $trimmed, 2);
            if (count($parts) === 2) {
                $headers[trim($parts[0])][] = trim($parts[1]);
            }

            return $length;
        };
        $curlOptions[CURLOPT_WRITEFUNCTION] = static function ($ch, string $chunk) use (&$body, &$tooLarge, $max): int {
            if (strlen($body) + strlen($chunk) > $max) {
                $tooLarge = true;

                return 0;
            }
            $body .= $chunk;

            return strlen($chunk);
        };
        curl_setopt_array($handle, $curlOptions);

        $ok = curl_exec($handle);
        if ($ok === false) {
            $errno = curl_errno($handle);
            $pretransfer = (float) curl_getinfo($handle, CURLINFO_PRETRANSFER_TIME);
            $host = $request->getUri()->getHost();
            if ($tooLarge) {
                throw new ApiConnectionException('The response body is larger than the SDK accepts.', true, true, 'too_large');
            }
            [$reason, $sent] = $this->classify($errno, $pretransfer);

            throw new ApiConnectionException(
                sprintf('Could not get an answer from %s: %s (cURL error %d).', $host, curl_error($handle), $errno),
                wasSent: $sent,
                reason: $reason,
            );
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if ($status === 0 && preg_match('#^HTTP/\S+ (\d{3})#', $statusLine, $m) === 1) {
            $status = (int) $m[1];
        }

        return new Response($status, $headers, $body);
    }

    /**
     * The cURL options for a request (public so the option set is testable).
     *
     * @internal
     *
     * @return array<int, mixed>
     */
    public function buildOptions(#[\SensitiveParameter] RequestInterface $request, TransportOptions $options): array
    {
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $headers[] = $name . ': ' . $value;
            }
        }
        $headers[] = 'Expect:';

        $curl = [
            CURLOPT_URL => (string) $request->getUri(),
            CURLOPT_CUSTOMREQUEST => $request->getMethod(),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT_MS => max(1, (int) round($options->timeout * 1000)),
            CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) round($options->connectTimeout * 1000)),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_ENCODING => '',
            CURLOPT_RETURNTRANSFER => false,
        ];
        if (($options->timeout < 1 || $options->connectTimeout < 1) && PHP_OS_FAMILY !== 'Windows') {
            $curl[CURLOPT_NOSIGNAL] = true;
        }
        $body = (string) $request->getBody();
        if ($body !== '' || ! in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            $curl[CURLOPT_POSTFIELDS] = $body;
        }
        if ($request->getMethod() === 'HEAD') {
            $curl[CURLOPT_NOBODY] = true;
        }

        return $curl;
    }

    /**
     * @return array{0: string, 1: bool} The reason and whether the request may have been sent.
     */
    private function classify(int $errno, float $pretransfer): array
    {
        return match (true) {
            $errno === CURLE_COULDNT_RESOLVE_HOST || $errno === CURLE_COULDNT_RESOLVE_PROXY => ['dns', false],
            $errno === CURLE_COULDNT_CONNECT => ['connect', false],
            in_array($errno, [CURLE_SSL_CONNECT_ERROR, 51, 58, 59, 60, 64, 66, 77, 83, 90, 91], true) => ['tls', false],
            $errno === CURLE_OPERATION_TIMEOUTED => ['timeout', $pretransfer > 0],
            default => ['other', true],
        };
    }
}
