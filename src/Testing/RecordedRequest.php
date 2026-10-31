<?php

declare(strict_types=1);

namespace WatchTower15\Testing;

use Psr\Http\Message\RequestInterface;
use WatchTower15\Credentials\CredentialFormat;
use WatchTower15\Http\TransportOptions;
use WatchTower15\Internal\Json;

/**
 * A request a {@see MockTransport} saw: `Authorization` masked, the body gunzipped and decoded.
 */
final readonly class RecordedRequest
{
    /**
     * @param array<array<string>> $headers
     */
    public function __construct(
        public string $method,
        public string $uri,
        public array $headers,
        public mixed $body,
        public string $rawBody,
        public TransportOptions $options,
    ) {
    }

    /** @internal */
    public static function from(#[\SensitiveParameter] RequestInterface $request, TransportOptions $options): self
    {
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[$name] = strcasecmp($name, 'Authorization') === 0
                ? array_map(static fn (string $v): string => 'Bearer ' . CredentialFormat::mask(preg_replace('/^Bearer\s+/i', '', $v) ?? ''), $values)
                : array_values($values);
        }
        $raw = (string) $request->getBody();
        $text = $raw;
        if (str_starts_with($raw, "\x1f\x8b") && function_exists('gzdecode')) {
            $decoded = gzdecode($raw);
            $text = $decoded === false ? $raw : $decoded;
        }
        $body = $text === '' ? null : (Json::decodeObject($text) ?? $text);

        return new self($request->getMethod(), (string) $request->getUri(), $headers, $body, $raw, $options);
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $values) {
            if (strcasecmp($key, $name) === 0) {
                return $values[0] ?? null;
            }
        }

        return null;
    }

    public function path(): string
    {
        return (string) parse_url($this->uri, PHP_URL_PATH);
    }
}
