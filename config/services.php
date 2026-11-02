<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use WatchTower15\Client;
use WatchTower15\Logs\Breaker\FileBreakerStore;
use WatchTower15\Logs\Monolog\WatchTower15Handler;
use WatchTower15\Metrics\Aggregator;
use WatchTower15\Metrics\BucketStore;
use WatchTower15\Metrics\Flusher;
use WatchTower15\Symfony\ClientFactory;
use WatchTower15\Symfony\Command\FlushCommand;
use WatchTower15\Symfony\Command\TestCommand;
use WatchTower15\Symfony\Http\SymfonyTransport;
use WatchTower15\Symfony\Lifecycle\KernelTerminateStrategy;
use WatchTower15\Symfony\Lifecycle\Runtime;
use WatchTower15\Symfony\Metrics\AggregatorFactory;
use WatchTower15\Symfony\Metrics\PipeStatus;
use WatchTower15\Symfony\Metrics\RedisDrivers;
use WatchTower15\Symfony\Metrics\StoreFactory;
use WatchTower15\Symfony\Scheduler\FlushMetrics;
use WatchTower15\Symfony\Scheduler\FlushMetricsHandler;
use WatchTower15\Symfony\Scheduler\FlushSchedule;

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service_closure;

/*
 * The bundle's services (plan WT15-SDK-00008 D4). Each is built by a factory at its first use, so booting the kernel makes no
 * network call, no store call and no log write. A value that may come from %env()% is a service argument, read when the service is built.
 */
return static function (ContainerConfigurator $container, ContainerBuilder $builder): void {
    $services = $container->services();
    $bundles = array_values($builder->hasParameter('kernel.bundles') ? (array) $builder->getParameter('kernel.bundles') : []);
    $hasMonolog = in_array('Symfony\Bundle\MonologBundle\MonologBundle', $bundles, true);
    $monolog3 = class_exists(\Monolog\LogRecord::class);

    // The SDK's own logger: the `watchtower15` channel with MonologBundle, else nothing.
    if ($hasMonolog) {
        $services->alias('watchtower15.logger', 'monolog.logger.watchtower15');
    } else {
        $services->set('watchtower15.logger', NullLogger::class);
    }

    $services->set('watchtower15.redis_drivers', RedisDrivers::class);
    $services->set('watchtower15.runtime', Runtime::class);
    $services->set('watchtower15.store_factory', StoreFactory::class)
        ->args([service('watchtower15.redis_drivers'), '%kernel.project_dir%/var/watchtower15']);
    $services->set('watchtower15.pipe_status', PipeStatus::class)->args([
        service('watchtower15.store_factory'),
        '%watchtower15.metrics.token%',
        '%watchtower15.metrics.enabled%',
        '%watchtower15.metrics.store%',
        '%watchtower15.metrics.prefix%',
        '%watchtower15.metrics.flush_after_response%',
        '%watchtower15.ingest_url%',
    ]);
    $services->set('watchtower15.terminate_strategy', KernelTerminateStrategy::class)
        ->args([service('watchtower15.runtime'), service('watchtower15.pipe_status')])
        ->tag('kernel.event_subscriber');

    // The transport: the bundle's own Symfony client, traced by the profiler, or the core's CurlTransport when `http_client` is null.
    $services->set('watchtower15.http_client', HttpClientInterface::class)
        ->factory([HttpClient::class, 'create'])
        ->args([['max_redirects' => 0]])
        ->tag('http_client.client');
    $transport = $builder->getParameter('watchtower15.http_client');
    if ($transport !== null) {
        $services->set('watchtower15.transport', SymfonyTransport::class)->args([service((string) $transport)]);
    }
    $services->set('watchtower15.client_factory', ClientFactory::class)
        ->args([$transport !== null ? service('watchtower15.transport') : null, service('watchtower15.logger')]);

    // The three clients: the application's (public, throws to whoever asks), and the pipes' own.
    $services->set('watchtower15.client', Client::class)
        ->public()
        ->factory([service('watchtower15.client_factory'), 'application'])
        ->args([
            '%watchtower15.api_key%',
            '%watchtower15.metrics.token%',
            '%watchtower15.logs.token%',
            '%watchtower15.api_url%',
            '%watchtower15.ingest_url%',
            '%watchtower15.timeout%',
            '%watchtower15.connect_timeout%',
            '%watchtower15.max_retries%',
            '%watchtower15.app_info%',
        ]);
    $services->alias(Client::class, 'watchtower15.client');
    $services->set('watchtower15.metrics.client', Client::class)
        ->factory([service('watchtower15.client_factory'), 'metrics'])
        ->args(['%watchtower15.metrics.token%', '%watchtower15.ingest_url%']);
    $services->set('watchtower15.logs.client', Client::class)
        ->factory([service('watchtower15.client_factory'), 'logs'])
        ->args(['%watchtower15.logs.token%', '%watchtower15.ingest_url%']);

    // The metrics pipe.
    $services->set('watchtower15.aggregator_factory', AggregatorFactory::class)->args([
        service('watchtower15.store_factory'),
        service('watchtower15.pipe_status'),
        service('watchtower15.terminate_strategy'),
        service('watchtower15.logger'),
        '%watchtower15.metrics.allow_list%',
        '%watchtower15.metrics.heartbeat%',
    ]);
    $services->set('watchtower15.aggregator', Aggregator::class)
        ->factory([service('watchtower15.aggregator_factory'), 'create'])
        ->args([service('watchtower15.metrics.client')]);
    $services->alias(Aggregator::class, 'watchtower15.aggregator');
    $services->set('watchtower15.flusher', Flusher::class)->factory([service('watchtower15.aggregator'), 'flusher']);
    $services->alias(Flusher::class, 'watchtower15.flusher');
    $services->set('watchtower15.bucket_store', BucketStore::class)->factory([service('watchtower15.aggregator'), 'store']);
    $services->alias(BucketStore::class, 'watchtower15.bucket_store');

    // The minute flush (D9): only where the Scheduler is installed and asked for.
    $scheduler = 'not installed';
    if (interface_exists(ScheduleProviderInterface::class)) {
        $scheduler = $builder->getParameter('watchtower15.metrics.schedule') === true ? 'on (messenger:consume scheduler_watchtower15)' : 'off (metrics.schedule is false)';
        if ($builder->getParameter('watchtower15.metrics.schedule') === true) {
            $services->set('watchtower15.scheduler.schedule', FlushSchedule::class)
                ->args([service('watchtower15.pipe_status')])
                ->tag('scheduler.schedule_provider', ['name' => 'watchtower15']);
            $services->set('watchtower15.scheduler.handler', FlushMetricsHandler::class)
                ->args([service('watchtower15.pipe_status'), service_closure('watchtower15.aggregator'), service('watchtower15.logger')])
                ->tag('messenger.message_handler', ['handles' => FlushMetrics::class]);
        }
    }

    // The log handler (D10): closures only, so a malformed logs token or URL is the shipper's `config` count, never an exception
    // while MonologBundle builds a channel's logger. No `shutdownStrategy`: the core's PhpShutdownStrategy pushes after the response.
    $logsHandler = 'not registered: MonologBundle is not registered';
    if ($hasMonolog && ! $monolog3) {
        $logsHandler = 'not registered: it needs Monolog 3 (Monolog\\LogRecord was not found)';
    } elseif ($hasMonolog) {
        $logsHandler = 'registered: watchtower15.monolog_handler';
        $services->set('watchtower15.monolog_handler', WatchTower15Handler::class)->args([
            service_closure('watchtower15.logs.client'),
            null,
            service_closure('monolog.logger.watchtower15'),
            inline_service(FileBreakerStore::class)->args(['%kernel.cache_dir%/watchtower15']),
        ]);
    }

    // The commands (D11).
    $services->set('watchtower15.command.test', TestCommand::class)
        ->args([
            service('watchtower15.client_factory'),
            service('watchtower15.pipe_status'),
            [
                'api_key' => '%watchtower15.api_key%',
                'metrics_token' => '%watchtower15.metrics.token%',
                'logs_token' => '%watchtower15.logs.token%',
                'api_url' => '%watchtower15.api_url%',
                'ingest_url' => '%watchtower15.ingest_url%',
            ],
            [
                'allowed' => '%watchtower15.metrics.allow_list%',
                'heartbeat' => '%watchtower15.metrics.heartbeat%',
                'scheduler' => $scheduler,
                'logs_handler' => $logsHandler,
            ],
        ])
        ->tag('console.command');
    $services->set('watchtower15.command.flush', FlushCommand::class)
        ->args([service('watchtower15.pipe_status'), service_closure('watchtower15.aggregator'), service('watchtower15.logger')])
        ->tag('console.command');
};
