<?php

declare(strict_types=1);

namespace WatchTower15\Symfony\Scheduler;

use Psr\Log\LoggerInterface;
use WatchTower15\Metrics\Aggregator;
use WatchTower15\Symfony\Metrics\PipeStatus;

/**
 * Handles the minute flush (D9): `flushIfDue()`, so N workers sharing a Redis store flush once a minute between them. While the
 * store or `ingest_url` is refused it logs the reason once per run on the `watchtower15` channel with no store call; while the pipe is
 * disabled it does nothing.
 *
 * @internal
 */
final class FlushMetricsHandler
{
    /** @param \Closure(): Aggregator $aggregator */
    public function __construct(
        private readonly PipeStatus $status,
        private readonly \Closure $aggregator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(FlushMetrics $message): void
    {
        if (! $this->status->enabled() || ! $this->status->hasToken()) {
            return;
        }
        if (! $this->status->canSend()) {
            $this->logger->warning('WatchTower15 metrics were not sent: ' . implode('; ', $this->status->problems()));

            return;
        }
        try {
            ($this->aggregator)()->flushIfDue();
        } catch (\Throwable) {
            // Never thrown (D-SDK12).
        }
    }
}
