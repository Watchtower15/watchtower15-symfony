<?php

declare(strict_types=1);

namespace WatchTower15\Symfony\Command;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use WatchTower15\Metrics\Aggregator;
use WatchTower15\Metrics\FlushReport;
use WatchTower15\Metrics\StoreScope;
use WatchTower15\Symfony\Metrics\PipeStatus;

/**
 * Sends the closed minute buckets of the metrics pipe now (D11), for cron and by hand. `--if-due` takes the same minute claim as the
 * Scheduler's handler and `kernel.terminate`, so hosts sharing a Redis store send once a minute between them. It makes no store call
 * while the pipe is disabled or refused. Exit 0 flushed, disabled, locked or not due; 1 when the flush stopped on a failure; 2 when
 * nothing could be sent from here.
 *
 * @internal
 */
#[AsCommand(name: 'watchtower15:flush', description: 'Send the closed minute buckets of the metrics pipe to WatchTower15 now')]
final class FlushCommand extends Command
{
    /** @param \Closure(): Aggregator $aggregator */
    public function __construct(
        private readonly PipeStatus $status,
        private readonly \Closure $aggregator,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('if-due', null, InputOption::VALUE_NONE, 'Flush only if this minute has not been claimed by another process (the cron line)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $say = static fn (string $line) => $output->writeln($line, OutputInterface::OUTPUT_PLAIN);

        if (! $this->status->enabled() || ! $this->status->hasToken()) {
            $say('WatchTower15 metrics are disabled: ' . implode('; ', array_slice($this->status->problems(), 0, 2)) . '. Nothing to flush.');

            return Command::SUCCESS;
        }
        if (! $this->status->canSend()) {
            $reason = implode('; ', $this->status->problems());
            $say('Nothing was sent: ' . $reason);
            $this->logger->warning('WatchTower15 metrics were not sent: ' . $reason);

            return 2;
        }
        $scope = $this->status->scope();
        if ($scope === StoreScope::Process) {
            $say('Nothing was sent: memory:// lives in one process: there is nothing to flush from here; use a file:// or redis:// store.');

            return 2;
        }
        if ($scope === StoreScope::Sapi) {
            $say('Nothing was sent: APCu is not shared between the CLI and PHP-FPM: an apcu:// store flushes from requests (flush_after_response), or use a file:// or redis:// store.');

            return 2;
        }

        try {
            $aggregator = ($this->aggregator)();
            $report = $input->getOption('if-due') ? $aggregator->flushIfDue() : $aggregator->flush();
        } catch (\Throwable $e) {
            $say('The flush failed: ' . $e::class . ': ' . $e->getMessage());
            $this->logger->warning('WatchTower15 metrics flush failed.', ['exception' => $e::class . ': ' . $e->getMessage()]);

            return 1;
        }
        if ($report === null) {
            $say("Not due: another process has claimed this minute's flush.");

            return Command::SUCCESS;
        }
        $this->print($report, $say);
        foreach ($report->configProblems as $problem) {
            $say('Configuration problem: ' . $problem);
        }

        return $report->status === 'flushed' && $report->stopReason !== null && $report->stopReason !== 'max_requests' ? 1 : 0;
    }

    private function print(FlushReport $report, callable $say): void
    {
        if ($report->status === 'disabled') {
            $say('WatchTower15 metrics are disabled: nothing to flush.');

            return;
        }
        if ($report->status === 'locked') {
            $say('Another flush is running: nothing done.');

            return;
        }
        $say(sprintf(
            'Flushed: %d bucket(s) sent, %d empty, %d dropped; %d request(s), %d point(s) accepted, %d chunk(s) dropped.',
            $report->bucketsSent,
            $report->bucketsEmpty,
            $report->bucketsDropped,
            $report->requests,
            $report->points,
            $report->chunksDropped,
        ));
        if ($report->rejected !== []) {
            $say('Rejected by the server: ' . json_encode($report->rejected));
        }
        if ($report->stopReason !== null) {
            $say('Stopped: ' . $report->stopReason);
        }
        if ($report->errors !== []) {
            $parts = [];
            foreach ($report->errors as $reason => $count) {
                $parts[] = $reason . ' ' . $count;
            }
            $say('Counted since the last flush: ' . implode(', ', $parts));
        }
    }
}
