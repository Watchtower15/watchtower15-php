<?php

declare(strict_types=1);

namespace WatchTower15\Exception;

/** 429 `daily_limit_reached`: waiting a minute does not help. */
class DailyLimitException extends RateLimitException
{
}
