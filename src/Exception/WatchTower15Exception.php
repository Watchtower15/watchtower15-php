<?php

declare(strict_types=1);

namespace WatchTower15\Exception;

/**
 * Implemented by every exception the SDK throws for a call or a configuration, so one `catch` takes them all.
 */
interface WatchTower15Exception extends \Throwable
{
}
