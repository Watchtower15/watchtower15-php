<?php

declare(strict_types=1);

namespace WatchTower15\Http;

use WatchTower15\ConfigurationException;

/**
 * Per-call options; null keeps the client's value.
 */
final readonly class RequestOptions
{
    private const KEYS = [
        'timeout' => 'timeout',
        'connect_timeout' => 'connectTimeout',
        'max_retries' => 'maxRetries',
        'max_retry_wait' => 'maxRetryWait',
    ];

    public function __construct(
        public ?float $timeout = null,
        public ?float $connectTimeout = null,
        public ?int $maxRetries = null,
        public ?float $maxRetryWait = null,
    ) {
        self::check('timeout', $timeout, static fn (float $v): bool => $v > 0 && $v <= 300, 'must be above 0 and at most 300 seconds');
        self::check('connectTimeout', $connectTimeout, static fn (float $v): bool => $v > 0, 'must be above 0');
        self::check('maxRetries', $maxRetries === null ? null : (float) $maxRetries, static fn (float $v): bool => $v >= 0 && $v <= 10, 'must be between 0 and 10');
        self::check('maxRetryWait', $maxRetryWait, static fn (float $v): bool => $v >= 0, 'must not be negative');
    }

    /**
     * @param RequestOptions|array<array-key, mixed>|null $options An array holds `timeout`, `connect_timeout`, `max_retries`, `max_retry_wait`.
     */
    public static function from(RequestOptions|array|null $options): self
    {
        if ($options instanceof self) {
            return $options;
        }
        if ($options === null || $options === []) {
            return new self();
        }
        $values = [];
        foreach ($options as $key => $value) {
            if (! is_string($key) || ! isset(self::KEYS[$key])) {
                throw new ConfigurationException(sprintf('Unknown request option `%s`; the options are %s.', (string) $key, implode(', ', array_keys(self::KEYS))));
            }
            $name = self::KEYS[$key];
            if ($value !== null && ! is_int($value) && ! is_float($value)) {
                throw new ConfigurationException(sprintf('The request option `%s` must be a number.', $key));
            }
            $values[$name] = $value;
        }
        try {
            return new self(
                timeout: isset($values['timeout']) ? (float) $values['timeout'] : null,
                connectTimeout: isset($values['connectTimeout']) ? (float) $values['connectTimeout'] : null,
                maxRetries: isset($values['maxRetries']) ? (int) $values['maxRetries'] : null,
                maxRetryWait: isset($values['maxRetryWait']) ? (float) $values['maxRetryWait'] : null,
            );
        } catch (ConfigurationException $e) {
            // Name the array's key rather than the named argument.
            $message = $e->getMessage();
            foreach (self::KEYS as $key => $name) {
                $message = str_replace('`' . $name . '`', '`' . $key . '`', $message);
            }
            throw new ConfigurationException($message, 0, $e);
        }
    }

    /**
     * @param callable(float): bool $ok
     */
    private static function check(string $name, ?float $value, callable $ok, string $rule): void
    {
        if ($value !== null && ! $ok($value)) {
            throw new ConfigurationException(sprintf('The request option `%s` %s.', $name, $rule));
        }
    }
}
