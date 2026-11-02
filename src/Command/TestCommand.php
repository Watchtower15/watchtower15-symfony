<?php

declare(strict_types=1);

namespace WatchTower15\Symfony\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use WatchTower15\Client;
use WatchTower15\Credentials\CredentialFormat;
use WatchTower15\Symfony\ClientFactory;
use WatchTower15\Symfony\Metrics\PipeStatus;
use WatchTower15\Symfony\Support\UrlRule;

/**
 * Checks every credential the application configured (D11), each on a `Client` of its own that holds that credential and its URL
 * alone: the API key with `me()`, the metrics token with the source's listing, the logs token by its format (a log push is a write),
 * then what the metrics pipe and the log handler will do. Exit 2 when nothing is configured or a credential or URL is malformed
 * (the remote checks the others allow still run); else 1 when a check failed, was not reached or the store is refused; else 0.
 *
 * @internal
 */
#[AsCommand(name: 'watchtower15:test', description: 'Check the credentials, URLs and metrics pipe of the WatchTower15 bundle')]
final class TestCommand extends Command
{
    /**
     * @param array{api_key?: ?string, metrics_token?: ?string, logs_token?: ?string, api_url?: ?string, ingest_url?: ?string} $settings
     * @param array{allowed?: ?array<string, string>, heartbeat?: ?string, scheduler?: string, logs_handler?: string} $pipes
     */
    public function __construct(
        private readonly ClientFactory $factory,
        private readonly PipeStatus $status,
        #[\SensitiveParameter] private readonly array $settings,
        private readonly array $pipes = [],
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $say = static fn (string $line) => $output->writeln($line, OutputInterface::OUTPUT_PLAIN);
        $apiKey = self::text($this->settings['api_key'] ?? null);
        $metricsToken = self::text($this->settings['metrics_token'] ?? null);
        $logsToken = self::text($this->settings['logs_token'] ?? null);
        $apiUrl = self::text($this->settings['api_url'] ?? null);
        $ingestUrl = self::text($this->settings['ingest_url'] ?? null);
        $malformed = false;
        $failed = false;

        $apiProblem = UrlRule::problem($apiUrl, 'apiUrl');
        $ingestProblem = UrlRule::problem($ingestUrl, 'ingestUrl');
        foreach ([['apiUrl', $apiProblem], ['ingestUrl', $ingestProblem]] as [$argument, $problem]) {
            if ($problem !== null) {
                $say(UrlRule::key($argument) . ' is invalid: ' . $problem);
                $malformed = true;
            }
        }

        $configured = 0;

        if ($apiKey !== null) {
            ++$configured;
            if (! CredentialFormat::isApiKey($apiKey)) {
                $say('API key: malformed (' . CredentialFormat::mask($apiKey) . '), set in WT15_API_KEY or watchtower15.api_key');
                $malformed = true;
            } elseif ($apiProblem !== null) {
                $say('API key: format valid; not checked: watchtower15.api_url is invalid');
            } else {
                try {
                    $client = $this->factory->only(apiKey: $apiKey, apiUrl: $apiUrl);
                    $me = $client->me();
                    $say(sprintf(
                        'API key: ok (%s), %s in %s, scopes %s, %s%s',
                        $me->keyName,
                        $me->userName === '' ? 'user' : $me->userName,
                        $me->organisationName,
                        $me->scopes === [] ? 'none' : implode(' ', $me->scopes),
                        $me->keyExpiresAt === null ? 'no expiry' : 'expires ' . $me->keyExpiresAt->format('Y-m-d'),
                        self::skew($client),
                    ));
                } catch (\Throwable $e) {
                    $say('API key: failed (' . $e::class . ': ' . $e->getMessage() . ')');
                    $failed = true;
                }
            }
        }

        if ($metricsToken !== null) {
            ++$configured;
            if (! CredentialFormat::isMetricsToken($metricsToken)) {
                $say('metrics token: malformed (' . CredentialFormat::mask($metricsToken) . '), set in WT15_METRICS_TOKEN or watchtower15.metrics.token');
                $malformed = true;
            } elseif ($ingestProblem !== null) {
                $say('metrics token: format valid; not checked: watchtower15.ingest_url is invalid');
            } else {
                try {
                    $client = $this->factory->only(metricsToken: $metricsToken, ingestUrl: $ingestUrl);
                    $listing = $client->ingest->metrics->list();
                    $counts = [];
                    foreach ($listing->metrics as $metric) {
                        $counts[$metric->status] = ($counts[$metric->status] ?? 0) + 1;
                    }
                    ksort($counts);
                    $say(sprintf(
                        'metrics token: ok (%s), %d metric(s) [%s], %s of %s used%s',
                        substr(CredentialFormat::mask($metricsToken), -4),
                        count($listing->metrics),
                        $counts === [] ? 'none' : implode(', ', array_map(static fn (string $status, int $n): string => $status . ' ' . $n, array_keys($counts), $counts)),
                        $listing->used === null ? '?' : (string) $listing->used,
                        $listing->maxMetrics === null ? '?' : (string) $listing->maxMetrics,
                        self::skew($client),
                    ));
                } catch (\Throwable $e) {
                    $say('metrics token: failed (' . $e::class . ': ' . $e->getMessage() . ')');
                    $failed = true;
                }
            }
        }

        if ($logsToken !== null) {
            ++$configured;
            if (! CredentialFormat::isLogsToken($logsToken)) {
                $say('logs token: malformed (' . CredentialFormat::mask($logsToken) . '), set in WT15_LOGS_TOKEN or watchtower15.logs.token');
                $malformed = true;
            } else {
                $say('logs token: format valid; not checked remotely: a log push is a write, and log ingest has no read-only call');
            }
        }

        if ($configured === 0) {
            $say("nothing configured: set WT15_API_KEY, WT15_METRICS_TOKEN or WT15_LOGS_TOKEN in .env.local (the recipe's variables), or the watchtower15 keys in config/packages/watchtower15.yaml");
        }

        // The metrics pipe.
        $problems = $this->status->problems();
        $storeProblem = $this->status->storeProblem();
        if ($storeProblem !== null) {
            $say('metrics store refused: ' . $storeProblem);
            $failed = true;
        } else {
            $say(sprintf('metrics store: %s (scope %s)', $this->status->maskedStore(), $this->status->scopeName()));
        }
        foreach ($problems as $problem) {
            if ($problem !== $storeProblem) {
                $say('metrics pipe disabled: ' . $problem);
            }
        }
        $allowed = array_key_exists('allowed', $this->pipes) ? $this->pipes['allowed'] : [];
        $heartbeat = self::text($this->pipes['heartbeat'] ?? null);
        if ($allowed === null) {
            $say('allow-list: off (watchtower15.metrics.allow_any): any metric key is sent');
        } elseif ($allowed === []) {
            $say($heartbeat === null ? 'nothing allowed: no metric is sent' : sprintf('nothing allowed: only the heartbeat `%s` is sent', $heartbeat));
        } else {
            $say(sprintf('allow-list: %d metric(s)%s', count($allowed), $heartbeat === null ? '' : ', and the heartbeat `' . $heartbeat . '`'));
        }
        $say(sprintf(
            'flush paths: Scheduler %s; flush_after_response "%s" (%s)',
            $this->pipes['scheduler'] ?? 'not installed',
            $this->status->flushAfterResponse(),
            $this->afterResponse(),
        ));
        $say('logs handler: ' . ($this->pipes['logs_handler'] ?? 'not registered'));

        if ($configured === 0 || $malformed) {
            return 2;
        }

        return $failed ? 1 : 0;
    }

    private function afterResponse(): string
    {
        return match ($this->status->flushAfterResponse()) {
            'never' => 'no flush after a response or a command; the Scheduler or cron flushes',
            'always' => 'flushes after every finished response and command',
            default => 'flushes where the response is already finished (PHP-FPM, FrankenPHP, LiteSpeed), and an apcu:// store in every runtime',
        };
    }

    private static function text(#[\SensitiveParameter] mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private static function skew(Client $client): string
    {
        $info = $client->lastResponse();
        if ($info === null) {
            return '';
        }
        foreach ($info->headers as $name => $values) {
            if (strcasecmp((string) $name, 'Date') === 0 && isset($values[0])) {
                $at = strtotime($values[0]);

                return $at === false ? '' : sprintf('; clock skew %d s', abs(time() - $at));
            }
        }

        return '';
    }
}
