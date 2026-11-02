<?php

declare(strict_types=1);

namespace WatchTower15\Api;

/**
 * What every generated model offers (plan WT15-SDK-00007 D7): built from the decoded answer, and giving back the array the
 * server sent, unknown fields included.
 */
interface Hydratable
{
    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
