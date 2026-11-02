<?php

declare(strict_types=1);

namespace WatchTower15\Symfony\Metrics;

use WatchTower15\Metrics\StoreScope;
use WatchTower15\Symfony\Support\UrlRule;

/**
 * Whether the metrics pipe may buffer and send, read from the settings as they are at runtime (D4, D12): a value from the
 * environment is only known when a service is built. It builds no store and no client, so asking is free. A refused store or a
 * blank token disables the pipe, with its reason; nothing is thrown.
 *
 * @internal
 */
final class PipeStatus
{
    private readonly ?string $metricsToken;

    private readonly ?string $dsn;

    private readonly ?string $prefix;

    private readonly ?string $ingestUrl;

    private readonly string $flush;

    private readonly bool $enabled;

    public function __construct(
        private readonly StoreFactory $stores,
        #[\SensitiveParameter] mixed $metricsToken,
        mixed $enabled,
        #[\SensitiveParameter] mixed $store,
        mixed $prefix,
        mixed $flushAfterResponse,
        mixed $ingestUrl,
    ) {
        $this->metricsToken = self::text($metricsToken);
        $this->dsn = self::text($store);
        $this->prefix = self::text($prefix);
        $this->ingestUrl = self::text($ingestUrl);
        $flush = self::text($flushAfterResponse);
        $this->flush = $flush === null ? 'auto' : strtolower($flush);
        $on = is_bool($enabled) ? $enabled : filter_var(is_scalar($enabled) ? (string) $enabled : '', FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $this->enabled = $on ?? true;
    }

    private static function text(#[\SensitiveParameter] mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /** `metrics.enabled`, true by default. */
    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function hasToken(): bool
    {
        return $this->metricsToken !== null;
    }

    public function storeProblem(): ?string
    {
        return $this->stores->problem($this->dsn, $this->prefix, $this->flush);
    }

    public function urlProblem(): ?string
    {
        $problem = UrlRule::problem($this->ingestUrl, 'ingestUrl');

        return $problem === null ? null : 'watchtower15.ingest_url is invalid: ' . $problem;
    }

    /** True when increments may be buffered: enabled, a metrics token, and a store this bundle accepts. */
    public function canBuffer(): bool
    {
        return $this->enabled && $this->hasToken() && $this->storeProblem() === null;
    }

    /** True when a flush may be attempted: it can buffer and `ingest_url` passes the core's rule. */
    public function canSend(): bool
    {
        return $this->canBuffer() && $this->urlProblem() === null;
    }

    /** @return list<string> each reason the pipe cannot buffer or send, the DSN's password masked */
    public function problems(): array
    {
        $problems = [];
        if (! $this->enabled) {
            $problems[] = 'watchtower15.metrics.enabled is false';
        }
        if (! $this->hasToken()) {
            $problems[] = 'no metrics token is set (watchtower15.metrics.token, WT15_METRICS_TOKEN)';
        }
        $store = $this->storeProblem();
        if ($store !== null) {
            $problems[] = $store;
        }
        $url = $this->urlProblem();
        if ($url !== null) {
            $problems[] = $url;
        }

        return $problems;
    }

    public function scope(): ?StoreScope
    {
        return StoreFactory::scopeOf($this->dsn);
    }

    public function scopeName(): string
    {
        $scope = $this->scope();

        return $scope === null ? 'unknown' : $scope->name;
    }

    public function flushAfterResponse(): string
    {
        return $this->flush;
    }

    public function dsn(): ?string
    {
        return $this->dsn;
    }

    public function prefix(): ?string
    {
        return $this->prefix;
    }

    public function maskedStore(): string
    {
        return StoreFactory::mask($this->dsn);
    }
}
