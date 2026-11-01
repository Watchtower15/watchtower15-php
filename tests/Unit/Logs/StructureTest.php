<?php

declare(strict_types=1);

namespace WatchTower15\Tests\Unit\Logs;

use PHPUnit\Framework\TestCase;

/** LG14, LG15 and LG30: the core names no Monolog class outside its handler, composer.json only suggests it, and the shutdown push waits. */
final class StructureTest extends TestCase
{
    public function testLg14NoFileButTheHandlerNamesAMonologClass(): void
    {
        $root = dirname(__DIR__, 3) . '/src';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $checked = 0;
        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php' || str_contains($file->getPathname(), '/Logs/Monolog/')) {
                continue;
            }
            $checked++;
            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (is_array($token) && in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING], true)) {
                    self::assertStringStartsNotWith('Monolog\\', ltrim($token[1], '\\'), $file->getPathname());
                    self::assertStringStartsNotWith('Monolog\\', $token[1], $file->getPathname());
                }
            }
        }
        self::assertGreaterThan(40, $checked);
    }

    public function testLg15MonologIsASuggestionOfTheCoreOnly(): void
    {
        $json = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/composer.json'), true);
        self::assertIsArray($json);
        self::assertArrayHasKey('monolog/monolog', $json['suggest']);
        self::assertArrayNotHasKey('monolog/monolog', $json['require']);
        self::assertArrayNotHasKey('monolog/monolog', $json['require-dev'] ?? []);
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function modes(): iterable
    {
        yield 'the script ends with a line buffered' => ['end', ['request 2', 'deferred', 'pushed 3']];
        yield 'close() is called first' => ['close', ['request 2', 'closed 3']];
    }

    /**
     * @param list<string> $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function testLg30TheShutdownFlushGoesThroughTheStrategy(string $mode, array $expected): void
    {
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/../../Fixtures/logs-shutdown.php', $mode],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        proc_close($process);

        self::assertSame($expected, array_values(array_filter(explode("\n", $output), static fn (string $l): bool => $l !== '')), $output);
    }
}
