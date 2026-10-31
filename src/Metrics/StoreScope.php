<?php

declare(strict_types=1);

namespace WatchTower15\Metrics;

/**
 * Who shares a store's buckets.
 */
enum StoreScope
{
    /** One process only. */
    case Process;

    /** One host's processes of one SAPI (APCu: PHP-FPM and the CLI each have their own segment). */
    case Sapi;

    /** Every process on one host. */
    case Host;

    /** Every process of the fleet. */
    case Fleet;
}
