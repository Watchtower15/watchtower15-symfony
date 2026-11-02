<?php

declare(strict_types=1);

namespace WatchTower15\Symfony\Metrics;

use Psr\Log\LoggerInterface;
use WatchTower15\Client;
use WatchTower15\Metrics\Aggregator;
use WatchTower15\Store\InMemoryStore;
use WatchTower15\Symfony\Lifecycle\KernelTerminateStrategy;

/**
 * Builds the application's `Aggregator` when it is first asked for (D4): the store from the DSN as it is at runtime, the
 * allow-list, the heartbeat, and the after-response strategy, registered once. While the pipe cannot buffer, the aggregator is
 * disabled over an in-memory store that is never written to.
 *
 * @internal
 */
final class AggregatorFactory
{
    /**
     * @param array<string, 'count'|'level'>|null $allowList null sends any metric (`allow_any`)
     */
    public function __construct(
        private readonly StoreFactory $stores,
        private readonly PipeStatus $status,
        private readonly KernelTerminateStrategy $strategy,
        private readonly LoggerInterface $logger,
        private readonly ?array $allowList,
        private readonly ?string $heartbeat,
    ) {
    }

    public function create(Client $metricsClient): Aggregator
    {
        $buffer = $this->status->canBuffer();
        $aggregator = new Aggregator(
            client: $metricsClient,
            store: $buffer ? $this->stores->create($this->status->dsn(), $this->status->prefix()) : new InMemoryStore(),
            allowList: $this->allowList,
            heartbeat: $this->heartbeat,
            enabled: $buffer,
            logger: $this->logger,
        );
        $aggregator->registerShutdown($this->strategy);

        return $aggregator;
    }
}
