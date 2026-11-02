<?php

declare(strict_types=1);

namespace WatchTower15\Symfony\Tests\Support;

use WatchTower15\Symfony\Metrics\RedisDrivers;

/** No phpredis and no Predis: the third case of D7, set through the seam. */
final class NoRedisDrivers extends RedisDrivers
{
    public function phpredisVersion(): ?string
    {
        return null;
    }

    public function predisInstalled(): bool
    {
        return false;
    }
}
