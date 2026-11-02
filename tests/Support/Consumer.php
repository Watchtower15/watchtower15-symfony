<?php

declare(strict_types=1);

namespace WatchTower15\Symfony\Tests\Support;

use WatchTower15\Client;
use WatchTower15\Metrics\Aggregator;
use WatchTower15\Metrics\BucketStore;
use WatchTower15\Metrics\Flusher;

/** An application service that asks for the four autowirable types (SY2). */
final class Consumer
{
    public function __construct(
        public readonly Client $client,
        public readonly Aggregator $aggregator,
        public readonly Flusher $flusher,
        public readonly BucketStore $store,
    ) {
    }
}
