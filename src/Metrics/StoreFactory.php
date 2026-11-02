<?php

declare(strict_types=1);

namespace WatchTower15\Symfony\Metrics;

use WatchTower15\Metrics\BucketStore;
use WatchTower15\Metrics\StoreScope;
use WatchTower15\Store\ApcuStore;
use WatchTower15\Store\FileStore;
use WatchTower15\Store\InMemoryStore;
use WatchTower15\Store\RedisStore;

/**
 * The metrics store, from a DSN (plan WT15-SDK-00008 D7): `redis://[user:password@]host[:port][/db]`, `rediss://…`,
 * `redis:///path/to.sock` (one plain client into the core's `RedisStore`, connected at its first command), `apcu://`,
 * `file://<absolute folder>`, `memory://`, or null/blank for a file under `var/`. Nothing here connects to anything.
 *
 * @internal
 */
final class StoreFactory
{
    public const CLUSTER_MESSAGE = 'Redis Cluster, RedisArray and Sentinel are not supported: point the store at one primary';

    public const DRIVER_MESSAGE = 'a redis:// store needs ext-redis 6.3 or later, or predis/predis';

    private const SCHEMES = ['redis', 'rediss', 'apcu', 'file', 'memory'];

    public function __construct(private readonly RedisDrivers $drivers, private readonly string $defaultDirectory)
    {
    }

    /** The scheme of a non-blank DSN, lower-case, or null for a blank one. */
    private static function schemeOf(#[\SensitiveParameter] ?string $dsn): ?string
    {
        if ($dsn === null || trim($dsn) === '') {
            return null;
        }

        return preg_match('#^([A-Za-z][A-Za-z0-9+.-]*):#', trim($dsn), $m) === 1 ? strtolower($m[1]) : '';
    }

    /** The scope of the store a DSN names, read from the scheme alone with no store built; null for a scheme this bundle refuses. */
    public static function scopeOf(#[\SensitiveParameter] ?string $dsn): ?StoreScope
    {
        return match (self::schemeOf($dsn)) {
            null, 'file' => StoreScope::Host,
            'redis', 'rediss' => StoreScope::Fleet,
            'apcu' => StoreScope::Sapi,
            'memory' => StoreScope::Process,
            default => null,
        };
    }

    /**
     * The rules that read only the configuration: the scheme, a cluster, the prefix, `never` with a store nothing else flushes.
     * Decided at compile time for a literal value and at runtime for one from the environment. The driver check is not here.
     *
     * @return ?string why the store is refused, naming the key; never the DSN's password
     */
    public static function literalProblem(#[\SensitiveParameter] ?string $dsn, ?string $prefix, string $flushAfterResponse = 'auto'): ?string
    {
        $scheme = self::schemeOf($dsn);
        if ($scheme === null) {
            return null;
        }
        $dsn = trim((string) $dsn);
        if (! in_array($scheme, self::SCHEMES, true)) {
            return sprintf('watchtower15.metrics.store: the scheme "%s" is not supported (use redis://, rediss://, apcu://, file:// or memory://)', $scheme);
        }
        $needsPrefix = in_array($scheme, ['redis', 'rediss', 'apcu'], true);
        if (in_array($scheme, ['redis', 'rediss'], true)
            && (preg_match('#^rediss?:\?#i', $dsn) === 1 || str_contains($dsn, 'host[') || stripos($dsn, 'redis_cluster') !== false || stripos($dsn, 'redis_sentinel') !== false || stripos($dsn, 'redisarray') !== false)) {
            return 'watchtower15.metrics.store: ' . self::CLUSTER_MESSAGE;
        }
        if ($needsPrefix && ($prefix === null || trim($prefix) === '')) {
            return sprintf('watchtower15.metrics.prefix is required with %s:// (one per application: two applications on one store would share buckets)', $scheme);
        }
        if (in_array($scheme, ['apcu', 'memory'], true) && $flushAfterResponse === 'never') {
            return sprintf('watchtower15.metrics.flush_after_response is "never", and nothing else would ever flush a %s:// store', $scheme);
        }
        if ($scheme === 'file' && ! str_starts_with((string) preg_replace('#^file://#i', '', $dsn), '/')) {
            return 'watchtower15.metrics.store: a file:// store takes an absolute folder (file:///var/www/shop/var/watchtower15)';
        }

        return null;
    }

    /** The configuration rules and the driver check (at runtime only: a container is often compiled where the runtime's extensions are absent). */
    public function problem(#[\SensitiveParameter] ?string $dsn, ?string $prefix, string $flushAfterResponse = 'auto'): ?string
    {
        $problem = self::literalProblem($dsn, $prefix, $flushAfterResponse);
        if ($problem !== null) {
            return $problem;
        }
        if (in_array(self::schemeOf($dsn), ['redis', 'rediss'], true) && ! $this->redisAvailable()) {
            return 'watchtower15.metrics.store: ' . self::DRIVER_MESSAGE;
        }

        return null;
    }

    private function redisAvailable(): bool
    {
        $version = $this->drivers->phpredisVersion();

        return ($version !== null && version_compare($version, '6.3.0', '>=')) || $this->drivers->predisInstalled();
    }

    /**
     * @throws \InvalidArgumentException when the store is refused (callers ask `problem()` first)
     */
    public function create(#[\SensitiveParameter] ?string $dsn, ?string $prefix): BucketStore
    {
        $problem = $this->problem($dsn, $prefix);
        if ($problem !== null) {
            throw new \InvalidArgumentException($problem);
        }
        $prefix = $prefix === null || trim($prefix) === '' ? null : trim($prefix);

        return match (self::schemeOf($dsn)) {
            null => new FileStore($this->defaultDirectory, $prefix ?? 'wt15'),
            'file' => new FileStore(rtrim((string) preg_replace('#^file://#i', '', trim((string) $dsn)), '/') ?: '/', $prefix ?? 'wt15'),
            'apcu' => new ApcuStore((string) $prefix),
            'memory' => new InMemoryStore(),
            default => new RedisStore($this->redisClient(trim((string) $dsn)), (string) $prefix),
        };
    }

    private function redisClient(#[\SensitiveParameter] string $dsn): \Redis|\Predis\ClientInterface
    {
        $tls = str_starts_with(strtolower($dsn), 'rediss:');
        $parts = parse_url($dsn);
        $parts = $parts === false ? [] : $parts;
        $host = isset($parts['host']) ? (string) $parts['host'] : null;
        $path = isset($parts['path']) ? (string) $parts['path'] : '';
        $socket = $host === null && $path !== '' ? $path : null;
        $database = $host !== null && preg_match('#^/(\d+)$#', $path, $m) === 1 ? (int) $m[1] : null;
        $user = isset($parts['user']) && $parts['user'] !== '' ? rawurldecode((string) $parts['user']) : null;
        $password = isset($parts['pass']) ? rawurldecode((string) $parts['pass']) : null;
        $port = isset($parts['port']) ? (int) $parts['port'] : 6379;

        $version = $this->drivers->phpredisVersion();
        if ($version !== null && version_compare($version, '6.3.0', '>=')) {
            $options = ['connectTimeout' => 1.0, 'readTimeout' => 1.0];
            if ($socket !== null) {
                $options += ['host' => $socket, 'port' => -1];
            } else {
                $options += ['host' => ($tls ? 'tls://' : '') . $host, 'port' => $port];
                if ($tls) {
                    $options['ssl'] = ['verify_peer' => true, 'verify_peer_name' => true];
                }
            }
            if ($password !== null) {
                $options['auth'] = $user !== null ? [$user, $password] : $password;
            }
            if ($database !== null) {
                $options['database'] = $database;
            }

            /** @phpstan-ignore argument.type (the stubs type the options narrower than phpredis 6.3 reads them) */
            return new \Redis($options);
        }

        $options = ['timeout' => 1.0, 'read_write_timeout' => 1.0];
        if ($socket !== null) {
            $options += ['scheme' => 'unix', 'path' => $socket];
        } else {
            $options += ['scheme' => $tls ? 'tls' : 'tcp', 'host' => $host, 'port' => $port];
        }
        if ($password !== null) {
            $options['password'] = $password;
            if ($user !== null) {
                $options['username'] = $user;
            }
        }
        if ($database !== null) {
            $options['database'] = $database;
        }

        return new \Predis\Client($options);
    }

    /** The DSN with its password replaced by `***`; never the whole DSN of a store that carries one. */
    public static function mask(#[\SensitiveParameter] ?string $dsn): string
    {
        if ($dsn === null || trim($dsn) === '') {
            return 'file://var/watchtower15 (default)';
        }

        return (string) preg_replace('#(://[^:/@?\#]*:)[^@/?\#]*@#', '$1***@', trim($dsn));
    }
}
