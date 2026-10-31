<?php

declare(strict_types=1);

namespace WatchTower15\Cli;

use Psr\Http\Client\ClientInterface;
use WatchTower15\Client;
use WatchTower15\ConfigurationException;
use WatchTower15\Credentials\CredentialFormat;
use WatchTower15\Exception\ApiConnectionException;
use WatchTower15\Exception\ApiException;
use WatchTower15\Exception\UnexpectedResponseException;
use WatchTower15\Http\Transport;
use WatchTower15\Internal\Clock;
use WatchTower15\Internal\Environment;
use WatchTower15\Internal\Json;
use WatchTower15\Internal\SystemClock;
use WatchTower15\Metrics\Aggregator;
use WatchTower15\Metrics\FlushReport;
use WatchTower15\Metrics\StoreScope;
use WatchTower15\Package;

/**
 * `vendor/bin/watchtower15`: checks a configuration from the command line. It reads only the process environment.
 *
 * @internal
 */
final class Application
{
    private const CREDENTIALS = [
        'WT15_API_KEY' => ['apiKey', 'api_key'],
        'WT15_METRICS_TOKEN' => ['metricsToken', 'metrics_token'],
        'WT15_LOGS_TOKEN' => ['logsToken', 'logs_token'],
    ];

    private readonly Clock $clock;

    /**
     * @param array<string, string>                 $environment The process environment.
     * @param resource                              $stdout
     * @param resource                              $stderr
     */
    public function __construct(
        private readonly array $environment,
        private $stdout,
        private $stderr,
        private readonly Transport|ClientInterface|null $transport = null,
        ?Clock $clock = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
    }

    /**
     * @param list<string> $argv The arguments after the script name.
     */
    public function run(array $argv): int
    {
        $command = $argv[0] ?? 'help';
        $flags = array_slice($argv, 1);
        if ($command === '--version') {
            return $this->version();
        }
        if ($command === 'flush') {
            return $this->flush($flags);
        }
        if ($command !== 'test') {
            $this->err("Usage: watchtower15 test [--reachability] [--json]\n       watchtower15 flush --bootstrap=<file> [--json]\n       watchtower15 --version");

            return $command === 'help' || $command === '--help' ? 0 : 2;
        }
        foreach ($flags as $flag) {
            if (! in_array($flag, ['--reachability', '--json'], true)) {
                $this->err('Unknown option ' . $flag);

                return 2;
            }
        }
        $json = in_array('--json', $flags, true);

        return in_array('--reachability', $flags, true) ? $this->reachability($json) : $this->test($json);
    }

    /**
     * One flush of the aggregator a bootstrap file returns: the cron entry.
     *
     * @param list<string> $flags
     */
    private function flush(array $flags): int
    {
        $json = false;
        $bootstrap = null;
        foreach ($flags as $flag) {
            if ($flag === '--json') {
                $json = true;
            } elseif (str_starts_with($flag, '--bootstrap=')) {
                $bootstrap = substr($flag, strlen('--bootstrap='));
            } else {
                $this->err('Unknown option ' . $flag);

                return 2;
            }
        }
        if ($bootstrap === null || trim($bootstrap) === '') {
            $fromEnvironment = $this->environment['WT15_METRICS_BOOTSTRAP'] ?? '';
            $bootstrap = trim($fromEnvironment) === '' ? null : $fromEnvironment;
        }
        if ($bootstrap === null) {
            $this->err('flush needs --bootstrap=<file> (or WT15_METRICS_BOOTSTRAP): a PHP file that returns the Aggregator.');

            return 2;
        }
        if (! is_file($bootstrap) || ! is_readable($bootstrap)) {
            $this->err(sprintf('The bootstrap file %s does not exist or is not readable.', $bootstrap));

            return 2;
        }
        try {
            $aggregator = (static fn (string $file): mixed => require $file)($bootstrap);
        } catch (\Throwable $e) {
            $this->err(sprintf('The bootstrap file %s threw %s: %s', $bootstrap, $e::class, $e->getMessage()));

            return 2;
        }
        if (! $aggregator instanceof Aggregator) {
            $this->err(sprintf('The bootstrap file %s must return an Aggregator.', $bootstrap));

            return 2;
        }
        $scope = $aggregator->store()->scope();
        if ($scope === StoreScope::Process) {
            $this->err('An in-memory store lives in one process; there is nothing to flush from here.');

            return 2;
        }
        if ($scope === StoreScope::Sapi) {
            $this->err('APCu is not shared between the CLI and PHP-FPM: flush from requests with PhpShutdownStrategy, or use RedisStore or FileStore.');

            return 2;
        }

        $report = $aggregator->flush();
        if ($json) {
            $this->out(Json::encode($report->toArray()));
        } else {
            $this->printReport($report);
        }

        return $report->status === 'flushed' && $report->stopReason !== null && $report->stopReason !== 'max_requests' ? 1 : 0;
    }

    private function printReport(FlushReport $report): void
    {
        if ($report->status === 'disabled') {
            $this->out('Metrics aggregation is disabled: nothing to flush.');

            return;
        }
        if ($report->status === 'locked') {
            $this->out('Another flush is running: nothing done.');

            return;
        }
        $this->out(sprintf(
            'Flushed: %d bucket(s) sent, %d empty, %d dropped; %d request(s), %d point(s) accepted, %d chunk(s) dropped.',
            $report->bucketsSent,
            $report->bucketsEmpty,
            $report->bucketsDropped,
            $report->requests,
            $report->points,
            $report->chunksDropped,
        ));
        if ($report->rejected !== []) {
            $this->out('Rejected by the server: ' . Json::encode($report->rejected));
        }
        if ($report->stopReason !== null) {
            $this->out('Stopped: ' . $report->stopReason);
        }
        if ($report->errors !== []) {
            $this->out('Errors counted: ' . Json::encode($report->errors));
        }
        foreach ($report->configProblems as $problem) {
            $this->out('Config: ' . $problem);
        }
    }

    private function version(): int
    {
        $client = new Client();
        $this->out($client->userAgent());

        return 0;
    }

    private function test(bool $json): int
    {
        $values = $this->environment;
        $configured = [];
        foreach (self::CREDENTIALS as $variable => [$parameter, $kind]) {
            $value = isset($values[$variable]) ? trim($values[$variable]) : '';
            if ($value === '') {
                continue;
            }
            $valid = match ($kind) {
                'api_key' => CredentialFormat::isApiKey($value),
                'metrics_token' => CredentialFormat::isMetricsToken($value),
                default => CredentialFormat::isLogsToken($value),
            };
            if (! $valid) {
                $actual = CredentialFormat::kindOf($value);
                $why = $actual !== null && $actual !== $kind
                    ? sprintf('this is %s: pass it as `%s`', CredentialFormat::describe($value), CredentialFormat::parameterFor($actual))
                    : sprintf('`%s` is not the shape of this credential', CredentialFormat::mask($value));
                $this->err(sprintf('%s is malformed: %s.', $variable, $why));

                return 2;
            }
            $configured[$kind] = true;
        }
        if ($configured === []) {
            $this->err('Nothing is configured. Set WT15_API_KEY, WT15_METRICS_TOKEN or WT15_LOGS_TOKEN. This command reads only the process environment, never an application\'s .env file, so export the variables first.');

            return 2;
        }
        try {
            $client = Client::fromValues($values, [], $this->transport);
        } catch (ConfigurationException $e) {
            $this->err($e->getMessage());

            return 2;
        }

        $checks = [];
        if (isset($configured['api_key'])) {
            $checks[] = $this->check('api_key', 'API key', $client, function () use ($client): string {
                $me = $client->me();

                return sprintf(
                    'key "%s", scopes [%s], organisation "%s", expires %s',
                    $me->keyName,
                    implode(', ', $me->scopes),
                    $me->organisationName,
                    $me->keyExpiresAt?->format('Y-m-d') ?? 'never',
                );
            });
        }
        if (isset($configured['metrics_token'])) {
            $checks[] = $this->check('metrics_token', 'Metrics token', $client, function () use ($client): string {
                $listing = $client->ingest->metrics->list();
                $byStatus = [];
                foreach ($listing->metrics as $metric) {
                    $byStatus[$metric->status] = ($byStatus[$metric->status] ?? 0) + 1;
                }
                ksort($byStatus);
                $parts = [];
                foreach ($byStatus as $status => $n) {
                    $parts[] = $n . ' ' . $status;
                }

                return sprintf(
                    'metrics: %s; %s of %s used',
                    $parts === [] ? 'none' : implode(', ', $parts),
                    $listing->used ?? '?',
                    $listing->maxMetrics ?? '?',
                );
            });
        }
        if (isset($configured['logs_token'])) {
            $checks[] = [
                'name' => 'logs_token',
                'label' => 'Logs token',
                'status' => 'not_checked_remotely',
                'text' => 'format valid; not checked remotely: log ingest has no read-only call, and a test push would store a line and mark the source as receiving',
            ];
        }

        $ok = true;
        foreach ($checks as $check) {
            if ($check['status'] !== 'ok' && $check['status'] !== 'not_checked_remotely') {
                $ok = false;
            }
        }
        $this->report($checks, $json, $ok);

        return $ok ? 0 : 1;
    }

    private function reachability(bool $json): int
    {
        $apiUrl = $this->environment['WT15_API_URL'] ?? '';
        $ingestUrl = $this->environment['WT15_INGEST_URL'] ?? '';
        $arguments = [];
        if (trim($apiUrl) !== '') {
            $arguments['apiUrl'] = $apiUrl;
        }
        if (trim($ingestUrl) !== '') {
            $arguments['ingestUrl'] = $ingestUrl;
        }
        try {
            if ($this->transport !== null) {
                $arguments['httpClient'] = $this->transport;
            }
            $client = new Client(...$arguments);
        } catch (ConfigurationException $e) {
            $this->err('A URL variable is malformed: ' . $e->getMessage());

            return 2;
        }

        $targets = [
            ['api', 'API', $client->apiUrl() . '/me'],
            ['ingest', 'Ingest', $client->ingestUrl() . '/api/metric-sources/ingest'],
        ];
        $checks = [];
        $ok = true;
        foreach ($targets as [$name, $label, $url]) {
            $host = (string) parse_url($url, PHP_URL_HOST);
            try {
                $probe = $client->sender()->probe('cli.reachability.' . $name, $url);
                $status = $probe->response->getStatusCode();
                $body = Json::decodeObject((string) $probe->response->getBody());
                if (($status === 401 || $status === 403) && $body !== null) {
                    $checks[] = ['name' => $name, 'label' => $label, 'status' => 'ok', 'text' => sprintf('reached WatchTower15 (%s, HTTP %d)', $host, $status)];
                } else {
                    $ray = $probe->response->getHeaderLine('CF-Ray');
                    $ok = false;
                    $checks[] = [
                        'name' => $name,
                        'label' => $label,
                        'status' => 'proxy',
                        'text' => sprintf('an edge or proxy answered (%s, HTTP %d, %s%s)', $host, $status, $probe->response->getHeaderLine('Content-Type') ?: 'no content type', $ray !== '' ? ', CF-Ray ' . $ray : ''),
                    ];
                }
            } catch (ApiConnectionException $e) {
                $ok = false;
                $checks[] = ['name' => $name, 'label' => $label, 'status' => 'not_reached', 'text' => sprintf('not reached (%s): %s', $e->reason(), $host)];
            }
        }
        $this->report($checks, $json, $ok);

        return $ok ? 0 : 1;
    }

    /**
     * @param callable(): string $call
     *
     * @return array{name: string, label: string, status: string, text: string}
     */
    private function check(string $name, string $label, Client $client, callable $call): array
    {
        try {
            $text = $call();
            $skew = $this->skew($client);

            return ['name' => $name, 'label' => $label, 'status' => 'ok', 'text' => $text . ($skew !== null ? sprintf('; clock skew %d s', $skew) : '')];
        } catch (ApiConnectionException $e) {
            return ['name' => $name, 'label' => $label, 'status' => 'not_reached', 'text' => sprintf('not reached (%s): %s', $e->reason(), $e->getMessage())];
        } catch (UnexpectedResponseException $e) {
            return [
                'name' => $name,
                'label' => $label,
                'status' => 'proxy',
                'text' => sprintf('an edge or proxy answered (%s%s)', $e->getStatusCode() ?? '?', $e->cfRay() !== null ? ', CF-Ray ' . $e->cfRay() : ''),
            ];
        } catch (ApiException $e) {
            return [
                'name' => $name,
                'label' => $label,
                'status' => 'refused',
                'text' => sprintf('refused by WatchTower15 (%s)%s%s', $e->getStatusCode() ?? '?', $e->getErrorCode() !== null ? ' ' . $e->getErrorCode() : '', $e->getRequestId() !== null ? ', request ' . $e->getRequestId() : ''),
            ];
        } catch (\Throwable $e) {
            return ['name' => $name, 'label' => $label, 'status' => 'failed', 'text' => 'failed: ' . $e::class];
        }
    }

    private function skew(Client $client): ?int
    {
        $info = $client->lastResponse();
        if ($info === null) {
            return null;
        }
        foreach ($info->headers as $name => $values) {
            if (strcasecmp($name, 'Date') === 0 && isset($values[0])) {
                $at = strtotime($values[0]);

                return $at === false ? null : abs($this->clock->now()->getTimestamp() - $at);
            }
        }

        return null;
    }

    /**
     * @param list<array{name: string, label: string, status: string, text: string}> $checks
     */
    private function report(array $checks, bool $json, bool $ok): void
    {
        if ($json) {
            $this->out(Json::encode(['ok' => $ok, 'checks' => $checks]));

            return;
        }
        foreach ($checks as $check) {
            $mark = $check['status'] === 'ok' ? 'ok  ' : ($check['status'] === 'not_checked_remotely' ? 'note' : 'FAIL');
            $this->out(sprintf('[%s] %s: %s', $mark, $check['label'], $check['text']));
        }
        $this->out($ok ? 'All checks passed.' : 'A check failed.');
    }

    private function out(string $line): void
    {
        fwrite($this->stdout, $line . "\n");
    }

    private function err(string $line): void
    {
        fwrite($this->stderr, $line . "\n");
    }
}
