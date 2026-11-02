<?php

declare(strict_types=1);

namespace WatchTower15\Internal;

/**
 * Builds the typed properties of the generated models from the decoded answer (plan WT15-SDK-00007 D7). Lenient on purpose:
 * a field the server leaves out or sends as another type becomes the type's empty value rather than an error, so a newer
 * or older API never breaks a caller's code; `toArray()` always keeps what the server sent.
 *
 * @internal
 */
final class Hydrate
{
    public static function string(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return is_scalar($value) ? (string) $value : '';
    }

    public static function int(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_numeric($value) ? (int) $value : 0;
    }

    public static function float(mixed $value): int|float
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        return is_numeric($value) ? $value + 0 : 0;
    }

    public static function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /** An instant on the wire is epoch milliseconds; the model holds it as a UTC `DateTimeImmutable`. */
    public static function instant(mixed $value): \DateTimeImmutable
    {
        $utc = new \DateTimeZone('UTC');
        if (is_int($value) || is_float($value) || (is_string($value) && preg_match('/\A-?\d+\z/', $value) === 1)) {
            $milliseconds = (int) $value;
            $seconds = intdiv($milliseconds, 1000);
            $rest = $milliseconds - $seconds * 1000;
            if ($rest < 0) {
                $seconds--;
                $rest += 1000;
            }
            $date = \DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', $seconds, $rest * 1000), $utc);

            return $date !== false ? $date->setTimezone($utc) : new \DateTimeImmutable('@0');
        }
        if (is_string($value)) {
            try {
                return (new \DateTimeImmutable($value))->setTimezone($utc);
            } catch (\Exception) {
                // falls through to the epoch
            }
        }

        return new \DateTimeImmutable('@0');
    }

    /** @return array<string, mixed> */
    public static function array(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $key => $item) {
            $out[(string) $key] = $item;
        }

        return $out;
    }

    /**
     * @template T
     * @param \Closure(mixed): T $each
     * @return list<T>
     */
    public static function list(mixed $value, \Closure $each): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [] as $item) {
            $out[] = $each($item);
        }

        return $out;
    }

    /**
     * @template T
     * @param \Closure(mixed): T $each
     * @return array<string, T>
     */
    public static function map(mixed $value, \Closure $each): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [] as $key => $item) {
            $out[(string) $key] = $each($item);
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public static function rows(mixed $value): array
    {
        return self::list($value, self::array(...));
    }

    /**
     * A series' points: `[epoch ms, value]`.
     *
     * @return list<array{0: int, 1: float|int|null}>
     */
    public static function points(mixed $value): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [] as $point) {
            if (! is_array($point)) {
                continue;
            }
            $second = $point[1] ?? null;
            $out[] = [0 => self::int($point[0] ?? 0), 1 => is_int($second) || is_float($second) ? $second : null];
        }

        return $out;
    }
}
