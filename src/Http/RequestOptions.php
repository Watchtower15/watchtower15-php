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
        'idempotency_key' => 'idempotencyKey',
    ];

    public function __construct(
        public ?float $timeout = null,
        public ?float $connectTimeout = null,
        public ?int $maxRetries = null,
        public ?float $maxRetryWait = null,
        public ?string $idempotencyKey = null,
    ) {
        self::check('timeout', $timeout, static fn (float $v): bool => $v > 0 && $v <= 300, 'must be above 0 and at most 300 seconds');
        self::check('connectTimeout', $connectTimeout, static fn (float $v): bool => $v > 0, 'must be above 0');
        self::check('maxRetries', $maxRetries === null ? null : (float) $maxRetries, static fn (float $v): bool => $v >= 0 && $v <= 10, 'must be between 0 and 10');
        self::check('maxRetryWait', $maxRetryWait, static fn (float $v): bool => $v >= 0, 'must not be negative');
        if ($idempotencyKey !== null && preg_match('/\A[\x21-\x7e]{1,255}\z/', $idempotencyKey) !== 1) {
            throw new ConfigurationException('The request option `idempotencyKey` must be 1 to 255 visible ASCII characters.');
        }
    }

    /**
     * Refuses an `idempotency_key` before anything is sent: only a generated write and `request()` for a POST take one
     * (plan WT15-SDK-00007 D5). A push names its own argument instead.
     *
     * @param string|null $argument The argument that takes the key in this call's place, if it has one.
     */
    public function refuseIdempotencyKey(?string $argument = null): void
    {
        if ($this->idempotencyKey === null) {
            return;
        }
        throw new ConfigurationException($argument !== null
            ? sprintf('The request option `idempotency_key` is not taken by this call; pass the key as its `$%s` argument.', $argument)
            : 'The request option `idempotency_key` is only taken by a write; this call does not send one.');
    }

    /**
     * @param RequestOptions|array<array-key, mixed>|null $options An array holds `timeout`, `connect_timeout`, `max_retries`, `max_retry_wait`, and for a write `idempotency_key`.
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
            if ($name === 'idempotencyKey') {
                if ($value !== null && ! is_string($value)) {
                    throw new ConfigurationException('The request option `idempotency_key` must be a string.');
                }
            } elseif ($value !== null && ! is_int($value) && ! is_float($value)) {
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
                idempotencyKey: isset($values['idempotencyKey']) ? (string) $values['idempotencyKey'] : null,
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
