# watchtower15/symfony

The WatchTower15 PHP SDK's Symfony bundle. It turns the core SDK (`watchtower15/sdk`) into Symfony services: the application's
`WatchTower15\Client`, the metrics aggregator and its flush, a Monolog handler, a profiler-traced HTTP transport, and the
commands `watchtower15:test` and `watchtower15:flush`. Symfony `^6.4 || ^7.4 || ^8.0`, PHP `^8.3`.

Nothing is built, sent or logged when the kernel boots, and a mistyped credential never breaks the kernel or your logging.

This repository is a read-only split of the WatchTower15 PHP SDK: pull requests are not accepted. Documentation:
https://docs.watchtower15.com. Report a vulnerability to contact@watchtower15.com with the subject "Security".

## Install

```
composer require watchtower15/symfony
```

With Flex and the recipe (`composer config extra.symfony.allow-contrib true` first: Flex asks before it applies a contrib recipe
and skips it when it is not interactive), the bundle is registered, `config/packages/watchtower15.yaml` is added and
`WT15_API_KEY`, `WT15_METRICS_TOKEN` and `WT15_LOGS_TOKEN` appear in `.env`, all empty and all optional. Without the recipe, Flex
registers the bundle in `config/bundles.php` from its Composer type (`symfony-bundle`), and you create the config file yourself;
without Flex, add `WatchTower15\Symfony\WatchTower15Bundle::class => ['all' => true]` to `config/bundles.php`.

Then check the setup:

```
php bin/console watchtower15:test
```

## Configuration

Every key is optional. The config root is `watchtower15`.

```yaml
# config/packages/watchtower15.yaml
watchtower15:
    api_key: '%env(default::WT15_API_KEY)%'
    metrics:
        token: '%env(default::WT15_METRICS_TOKEN)%'
        store: '%env(default::WT15_METRICS_STORE)%'   # redis://redis:6379 for a fleet; unset: a file under var/
        prefix: shop                                   # required with redis:// and apcu://: one per application
        allowed:                                       # nothing else is sent
            orders.shipped: count
            queue.depth: level
    logs:
        token: '%env(default::WT15_LOGS_TOKEN)%'
```

| Key | Meaning | From `%env()%`? |
|---|---|---|
| `api_key` | The API key, for `WatchTower15\Client` | yes |
| `api_url`, `ingest_url` | Base URLs; unset is the SDK's default | yes |
| `metrics.token`, `logs.token` | The ingest tokens | yes |
| `metrics.enabled` | `true` by default | yes |
| `metrics.store`, `metrics.prefix` | The metrics store and its prefix (below) | yes |
| `timeout`, `connect_timeout`, `max_retries`, `app_info` | Passed to the application's client | no |
| `http_client` | A service id; default the bundle's own `watchtower15.http_client`; `null` uses the SDK's cURL transport | no |
| `metrics.allowed` | A map of metric key to `count` or `level` | no |
| `metrics.allow_any` | `true` sends any metric key (default `false`) | no |
| `metrics.heartbeat` | A metric key the flusher adds once a minute | no |
| `metrics.flush_after_response` | `auto`, `always` or `never` (below) | no |
| `metrics.schedule` | `true` registers the Scheduler message (below) | no |

A value from `%env()%` is read when a service is first built. A key that is not on the right is checked while the container compiles,
so a mistake fails `cache:clear` naming the key; a value from the environment cannot be checked then and is refused at run time
instead. Use `%env(default::NAME)%` so that an unset or empty variable is read as "not set"; a plain `%env(NAME)%` of an empty
variable gives `""`, which the SDK also reads as not set.

## The three clients

| Service | Holds | A bad value |
|---|---|---|
| `WatchTower15\Client` (`watchtower15.client`) | The API key, and the other credentials | Throws `WatchTower15\ConfigurationException` to the code that asks for it, and to nothing else |
| the metrics pipe's client | The metrics token alone | Never throws: the flush stops with a `config` count and keeps its buckets |
| the logs handler's client | The logs token alone | Counted `config` by the handler, never thrown |

`WatchTower15\Client`, `WatchTower15\Metrics\Aggregator`, `Flusher` and `BucketStore` are autowirable.

```php
public function __construct(private readonly \WatchTower15\Client $wt15, private readonly \WatchTower15\Metrics\Aggregator $metrics) {}
// …
$this->metrics->increment('orders.shipped');
$page = $this->wt15->servers->list(['health' => ['critical']]);
```

## The transport

The bundle sends through its own `watchtower15.http_client`, a Symfony HTTP client that never follows redirects and is tagged so the
profiler's HTTP panel traces every call. It is not your application's `http_client`, which may carry a second retry layer or default
headers. Symfony's HTTP client has no portable connect timeout, so the connect is bounded by the total.

⚠️ In a development profile the profiler stores each traced request's headers, `Authorization` included. Do not share profiles.

## The metrics store

`metrics.store` is a DSN. Symfony's cache contracts have no increment, so the bundle builds the SDK's own stores:

| DSN | Store | Shared by |
|---|---|---|
| unset | a file under `var/watchtower15` | FPM and the CLI on one host |
| `file:///absolute/folder` | a file store | one host |
| `redis://[user:password@]host[:port][/db]`, `rediss://…` (TLS), `redis:///path/to.sock` | Redis, one plain client, connected at its first command | a fleet |
| `apcu://` | APCu | one SAPI only |
| `memory://` | memory | one process |

- **Redis** needs `ext-redis` 6.3 or later, or `predis/predis`, and **one primary**: Redis Cluster, `RedisArray` and Sentinel are refused. The
  driver is checked at run time, so a container compiled where the extension is absent still works where it runs.
- **`metrics.prefix` is required with `redis://` and `apcu://`**, one per application: two applications on one Redis database would
  share buckets, and the first flusher would send the other's keys under its own token.
- ⚠️ **APCu is per SAPI**: a CLI process, the Scheduler's worker and cron cannot see what PHP-FPM wrote to APCu, so an `apcu://` store is
  flushed from requests only. Under Apache's `mod_php`, where no finish function exists, at most one response a minute per host waits
  for that flush; use a `file://` store there.
- **A Messenger worker** keeps the default `file://` store, flushed by the Scheduler or cron.
- **`memory://` is for console commands and tests only**: on the web side it is sent at each finished response, and where no finish
  function exists nothing would ever flush it.
- A store the bundle refuses (an unknown scheme, a cluster, a missing prefix) disables the metrics pipe, with its reason, and throws
  nothing: nothing is buffered, `watchtower15:test` and `watchtower15:flush` print the reason, and a password in a DSN is shown as `***`.

## Flushing

- **After the response.** `flush_after_response: auto` flushes on `kernel.terminate` where the response is already finished (PHP-FPM,
  FrankenPHP and LiteSpeed, decided by `fastcgi_finish_request()` and `litespeed_finish_request()`, never by the SAPI's name), for an
  `apcu://` store in every runtime, and on `console.terminate` of every command but `watchtower15:flush`. `always` does so in every
  runtime; `never` on neither. One flush a minute runs per shared store, whoever claims it first.
- **Every minute.** With `symfony/scheduler`, the bundle registers the schedule `watchtower15`; run it as the web server's user:
  `php bin/console messenger:consume scheduler_watchtower15`. Several workers on one Redis store flush once a minute between them. It
  needs the framework's `scheduler` to be enabled, and is empty for an `apcu://` or `memory://` store. Without the Scheduler, in the web
  server's user's crontab on each host:

  ```
  * * * * * cd /app && php bin/console watchtower15:flush --if-due
  ```

  The files a file store writes carry the process's umask: a cron job or worker running as another user than PHP-FPM cannot write
  FPM's files, so run them all as the same user.

## Logs

With MonologBundle (and Monolog 3), the service `watchtower15.monolog_handler` is the SDK's handler, built from closures so a bad logs
token is counted and never thrown while a logger is built. The SDK's own warnings go to the `watchtower15` channel. Put the handler
inside a `buffer` handler, because it sends one push per batch, and exclude the channel `watchtower15`:

```yaml
# config/packages/monolog.yaml
monolog:
    handlers:
        watchtower15:
            type: buffer
            handler: watchtower15_send
            level: warning
            buffer_size: 100
            flush_on_overflow: true
            channels: ['!watchtower15']
        watchtower15_send:
            type: service
            id: watchtower15.monolog_handler
            nested: true
```

The end-of-request push runs through PHP's shutdown, after the response. A Messenger worker, or a worker-mode runtime such as
FrankenPHP's, resets the buffer handler (`kernel.reset`) after each unit of work, which sends it. With MonologBundle on Monolog 2 there
is no handler service, and `watchtower15:test` says so.

## Commands

- `php bin/console watchtower15:test` checks each configured credential on a client of its own: the API key with `me()`, the metrics
  token with the source's listing, the logs token by its format (a log push is a write). It then prints the store and its scope, the
  allow-list, the flush paths and the log handler. Exit 2 when nothing is configured or a credential or URL is malformed (the checks
  the others allow still run); else 1 when a check failed or the store is refused; else 0.
- `php bin/console watchtower15:flush` sends the closed minute buckets now. It prints "disabled" (exit 0) while metrics are off or
  have no token, refuses a store it cannot read from the CLI or a refused configuration (exit 2, with no store call), and otherwise
  flushes and prints the report (exit 1 when the flush stopped on a failure). `--if-due` takes the same minute claim as the Scheduler
  and `kernel.terminate` and prints "Not due" when another process holds it: this is the cron line's form.

## Security

API keys and tokens are personal: keep them in the environment or a secrets store, never in code. The bundle never prints a credential or
a DSN's password.

MIT licence.
