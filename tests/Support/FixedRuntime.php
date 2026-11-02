<?php

declare(strict_types=1);

namespace WatchTower15\Symfony\Tests\Support;

use WatchTower15\Symfony\Lifecycle\Runtime;

/** A runtime whose finish-response answer a test sets (D8). */
final class FixedRuntime extends Runtime
{
    public function __construct(private readonly bool $canFinish)
    {
    }

    public function canFinishResponse(): bool
    {
        return $this->canFinish;
    }
}
