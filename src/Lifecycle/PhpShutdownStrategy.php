<?php

declare(strict_types=1);

namespace WatchTower15\Lifecycle;

/**
 * Bare PHP: a shutdown function that runs last, after the response has been sent where the SAPI can do that.
 *
 * `register()` registers an outer function whose only work is to register the real callback again. PHP runs shutdown functions in
 * the order they were registered and runs one registered during shutdown after every one already queued, so the flush runs after the
 * shutdown functions the application registered later (a fatal-error page, an error reporter's own flush). The real callback first
 * ends the response: `fastcgi_finish_request()` (PHP-FPM, FrankenPHP), else `litespeed_finish_request()`, else nothing.
 */
final class PhpShutdownStrategy implements ShutdownStrategy
{
    public function __construct(private readonly bool $finishRequest = true)
    {
    }

    public function register(\Closure $callback): void
    {
        $finish = $this->finishRequest;
        register_shutdown_function(static function () use ($callback, $finish): void {
            register_shutdown_function(static function () use ($callback, $finish): void {
                try {
                    if ($finish) {
                        if (function_exists('fastcgi_finish_request')) {
                            fastcgi_finish_request();
                        } elseif (function_exists('litespeed_finish_request')) {
                            litespeed_finish_request();
                        }
                    }
                    $callback();
                } catch (\Throwable) {
                    // Nothing reaches the application.
                }
            });
        });
    }
}
