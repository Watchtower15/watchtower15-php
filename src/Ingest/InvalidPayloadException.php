<?php

declare(strict_types=1);

namespace WatchTower15\Ingest;

use WatchTower15\Exception\WatchTower15Exception;

/** A point or a line refused before anything was sent. */
class InvalidPayloadException extends \InvalidArgumentException implements WatchTower15Exception
{
}
