<?php

declare(strict_types=1);

namespace WatchTower15\Internal;

/**
 * Unwinds a flush run when a store call throws (already counted).
 *
 * @internal
 */
final class StoreFailure extends \RuntimeException
{
}
