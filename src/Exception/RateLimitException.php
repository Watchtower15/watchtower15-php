<?php

declare(strict_types=1);

namespace WatchTower15\Exception;

/** 429 other than the two limits below. */
class RateLimitException extends ApiException
{
}
