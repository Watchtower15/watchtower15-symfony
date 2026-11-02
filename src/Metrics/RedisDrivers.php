<?php

declare(strict_types=1);

namespace WatchTower15\Symfony\Metrics;

/**
 * Which Redis clients this PHP can use (D7): a seam so a test can set each case. A private service, replaced by the tests' kernel.
 *
 * @internal
 */
class RedisDrivers
{
    /** The loaded phpredis version, or null when `ext-redis` is not loaded. */
    public function phpredisVersion(): ?string
    {
        if (! class_exists(\Redis::class)) {
            return null;
        }
        $version = phpversion('redis');

        return $version === false ? null : $version;
    }

    public function predisInstalled(): bool
    {
        return class_exists(\Predis\Client::class);
    }
}
