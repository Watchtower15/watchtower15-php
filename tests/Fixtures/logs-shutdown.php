<?php

declare(strict_types=1);

/*
 * A child process of LG30: a BufferHandler over the WatchTower15 handler with a recording shutdown strategy.
 * Argument: `end` (the script ends with a line buffered) or `close` (it calls close() first).
 */

use Monolog\Handler\BufferHandler;
use Monolog\Level;
use Monolog\LogRecord;
use WatchTower15\Lifecycle\ShutdownStrategy;
use WatchTower15\Logs\Breaker\InMemoryBreakerStore;
use WatchTower15\Logs\Monolog\WatchTower15Handler;
use WatchTower15\Testing\FakeClient;

require __DIR__ . '/../../../../vendor/autoload.php';

$mode = $argv[1] ?? 'end';
$fake = new FakeClient();

$strategy = new class ($fake) implements ShutdownStrategy {
    public function __construct(private readonly FakeClient $fake)
    {
    }

    public function register(\Closure $callback): void
    {
        echo "deferred\n";
        $callback();
        echo 'pushed ' . count($this->fake->pushedLines()) . "\n";
    }
};

$handler = new WatchTower15Handler($fake, 'drill', null, new InMemoryBreakerStore(), $strategy);
$buffer = new BufferHandler($handler, 2, Level::Debug, true, true);
foreach (['one', 'two', 'three'] as $message) {
    $buffer->handle(new LogRecord(new DateTimeImmutable(), 'app', Level::Error, $message, [], []));
}
echo 'request ' . count($fake->pushedLines()) . "\n";
if ($mode === 'close') {
    $buffer->close();
    echo 'closed ' . count($fake->pushedLines()) . "\n";
}
