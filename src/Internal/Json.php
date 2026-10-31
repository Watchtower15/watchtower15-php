<?php

declare(strict_types=1);

namespace WatchTower15\Internal;

/** @internal */
final class Json
{
    private function __construct()
    {
    }

    /**
     * @throws \JsonException
     */
    public static function encode(mixed $value, bool $substituteInvalidUtf8 = false): string
    {
        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if ($substituteInvalidUtf8) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }

        return json_encode($value, $flags);
    }

    /**
     * @throws \JsonException
     */
    public static function decode(string $json): mixed
    {
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * A decoded object, or null when the text is not a JSON object.
     *
     * @return array<string, mixed>|null
     */
    public static function decodeObject(string $json): ?array
    {
        try {
            $value = self::decode($json);
        } catch (\JsonException) {
            return null;
        }
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            return null;
        }

        /** @var array<string, mixed> $value */
        return $value;
    }
}
