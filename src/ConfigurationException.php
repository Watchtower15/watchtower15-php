<?php

declare(strict_types=1);

namespace WatchTower15;

use WatchTower15\Exception\WatchTower15Exception;

/** A configuration that cannot work: a bad option, a credential in the wrong slot, a missing credential. */
class ConfigurationException extends \InvalidArgumentException implements WatchTower15Exception
{
}
