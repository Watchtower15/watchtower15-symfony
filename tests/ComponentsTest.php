<?php

declare(strict_types=1);

namespace WatchTower15\Symfony\Tests;

use GuzzleHttp\Psr7\Request as Psr7Request;
use Monolog\Handler\TestHandler;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use WatchTower15\Client;
use WatchTower15\Exception\ApiConnectionException;
use WatchTower15\Http\TransportOptions;
use WatchTower15\Metrics\Aggregator;
use WatchTower15\Metrics\StoreScope;
use WatchTower15\Store\ApcuStore;
use WatchTower15\Store\FileStore;
use WatchTower15\Store\InMemoryStore;
use WatchTower15\Store\RedisStore;
use WatchTower15\Symfony\Http\SymfonyTransport;
use WatchTower15\Symfony\Lifecycle\KernelTerminateStrategy;
use WatchTower15\Symfony\Metrics\PipeStatus;
use WatchTower15\Symfony\Metrics\RedisDrivers;
use WatchTower15\Symfony\Metrics\StoreFactory;
use WatchTower15\Symfony\Scheduler\FlushMetrics;
use WatchTower15\Symfony\Tests\Support\Credentials;
use WatchTower15\Symfony\Tests\Support\FixedRuntime;
use WatchTower15\Symfony\Tests\Support\KernelCase;
use WatchTower15\Symfony\Tests\Support\NoRedisDrivers;
use WatchTower15\Symfony\Tests\Support\TestHttp;

/** SY3 and SY5 to SY9 (plan WT15-SDK-00008 §9.1). */
final class ComponentsTest extends KernelCase
{
    private const ACCEPTED = ['status' => 'success', 'status_code' => 200, 'data' => ['accepted' => true, 'batch_id' => 'b0000000-0000-4000-8000-000000000001', 'points' => 1, 'rejected' => []]];

    // ── SY3: the transport ──────────────────────────────────────────────────────────────────────────────────────────

    /** @param list<MockResponse> $responses @param list<array{method: string, url: string, options: array<string, mixed>}> $seen */
    private function transport(array $responses, array &$seen): SymfonyTransport
    {
        return new SymfonyTransport(new MockHttpClient(static function (string $method, string $url, array $options) use (&$responses, &$seen) {
            $seen[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($responses) ?? new MockResponse('{}');
        }));
    }

    public function testSy3RequestsAreSentWithTheTotalAsBothTimeoutsAndNoRedirect(): void
    {
        $seen = [];
        $transport = $this->transport([new MockResponse('{"ok":1}', ['http_code' => 200, 'response_headers' => ['x-request-id: r1']]), new MockResponse('', ['http_code' => 202])], $seen);
        $options = new TransportOptions(timeout: 7.5, connectTimeout: 2.0);

        $get = $transport->send(new Psr7Request('GET', 'https://rest.example.test/v1/me', ['Authorization' => 'Bearer SECRETVALUE']), $options);
        $post = $transport->send(new Psr7Request('POST', 'https://api.example.test/ingest', ['Content-Type' => 'application/json', 'Idempotency-Key' => 'k1'], '{"a":1}'), $options);

        self::assertSame(200, $get->getStatusCode());
        self::assertSame('{"ok":1}', (string) $get->getBody());
        self::assertSame('r1', $get->getHeaderLine('X-Request-Id'));
        self::assertSame(202, $post->getStatusCode());
        self::assertSame('GET', $seen[0]['method']);
        self::assertSame('https://rest.example.test/v1/me', $seen[0]['url']);
        self::assertSame('POST', $seen[1]['method']);
        self::assertSame('{"a":1}', $seen[1]['options']['body']);
        self::assertSame('k1', TestHttp::header($seen[1]['options'], 'Idempotency-Key'));
        self::assertSame('Bearer SECRETVALUE', TestHttp::header($seen[0]['options'], 'Authorization'));
        foreach ($seen as $call) {
            self::assertSame(7.5, (float) $call['options']['timeout']);
            self::assertSame(7.5, (float) $call['options']['max_duration']);
            self::assertSame(0, $call['options']['max_redirects']);
        }
    }

    public function testSy3ARedirectAndErrorStatusesAreReturnedAsResponses(): void
    {
        $seen = [];
        $transport = $this->transport([
            new MockResponse('', ['http_code' => 302, 'response_headers' => ['location: https://elsewhere.test/']]),
            new MockResponse('{"code":"not_found"}', ['http_code' => 404]),
            new MockResponse('down', ['http_code' => 503]),
        ], $seen);
        $request = new Psr7Request('GET', 'https://rest.example.test/v1/x');

        $redirect = $transport->send($request, new TransportOptions());
        $missing = $transport->send($request, new TransportOptions());
        $down = $transport->send($request, new TransportOptions());

        self::assertSame(302, $redirect->getStatusCode());
        self::assertSame('https://elsewhere.test/', $redirect->getHeaderLine('Location'));
        self::assertSame(404, $missing->getStatusCode());
        self::assertSame(503, $down->getStatusCode());
        self::assertCount(3, $seen, 'the 302 was not followed');
    }

    public function testSy3ATransportFailureIsAnApiConnectionExceptionWithNoPreviousAndNoRequestHeader(): void
    {
        $seen = [];
        $transport = $this->transport([new MockResponse('', ['error' => 'could not resolve host'])], $seen);

        try {
            $transport->send(new Psr7Request('GET', 'https://rest.example.test/v1/me', ['Authorization' => 'Bearer SECRETVALUE']), new TransportOptions());
            self::fail('expected an ApiConnectionException');
        } catch (ApiConnectionException $e) {
            self::assertTrue($e->wasSent());
            self::assertFalse($e->isResponseTooLarge());
            self::assertNull($e->getPrevious());
            self::assertStringContainsString('could not resolve host', $e->getMessage());
            self::assertStringNotContainsString('SECRETVALUE', $e->getMessage());
            self::assertStringNotContainsString('Authorization', $e->getMessage());
        }
    }

    public function testSy3ABodyOverTheCapIsCancelledWhetherOrNotItsSizeIsDeclared(): void
    {
        $seen = [];
        $declared = new MockResponse(str_repeat('x', 100), ['response_headers' => ['content-length: 100']]);
        $undeclared = new MockResponse(str_repeat('y', 100));
        $transport = $this->transport([$declared, $undeclared], $seen);

        foreach (['declared', 'undeclared'] as $case) {
            try {
                $transport->send(new Psr7Request('GET', 'https://rest.example.test/v1/big'), new TransportOptions(maxResponseBytes: 10));
                self::fail("the $case large body must be cancelled");
            } catch (ApiConnectionException $e) {
                self::assertTrue($e->isResponseTooLarge(), $case);
                self::assertNull($e->getPrevious());
            }
        }
    }

    // ── SY5: the store from a DSN ───────────────────────────────────────────────────────────────────────────────────

    private function redisHost(): string
    {
        $host = getenv('WT15_SDK_TEST_REDIS_HOST');
        if (! is_string($host) || $host === '') {
            if (getenv('WT15_SDK_TEST_REQUIRE_SERVICES') === '1') {
                self::fail('WT15_SDK_TEST_REDIS_HOST is not set, and the services are required');
            }
            self::markTestSkipped('no Redis service');
        }

        return $host;
    }

    private function redisDatabase(string $host, int $database): \Redis
    {
        $redis = new \Redis();
        $redis->connect($host, 6379, 2.0);
        $redis->select($database);

        return $redis;
    }

    public function testSy5RedisThroughPhpredisAndThroughPredisBothUseDatabaseTwoAndScopeFleet(): void
    {
        $host = $this->redisHost();
        foreach (['phpredis' => new RedisDrivers(), 'predis' => new class extends RedisDrivers {
            public function phpredisVersion(): ?string
            {
                return null;
            }
        }] as $driver => $drivers) {
            $check = $this->redisDatabase($host, 2);
            $check->flushDB();
            $factory = new StoreFactory($drivers, sys_get_temp_dir());

            $store = $factory->create('redis://' . $host . '/2', 't');

            self::assertInstanceOf(RedisStore::class, $store, $driver);
            self::assertSame(StoreScope::Fleet, $store->scope(), $driver);
            self::assertSame(StoreScope::Fleet, StoreFactory::scopeOf('redis://' . $host . '/2'));
            $store->increment(100, 'orders', 1, 60);
            self::assertGreaterThan(0, $check->dbSize(), "$driver wrote to database 2");
            $check->flushDB();
        }
    }

    public function testSy5NoUsableRedisDriverIsRefusedByCreateAndByThePipeStatusAtRuntimeOnly(): void
    {
        foreach ([new NoRedisDrivers(), new class extends RedisDrivers {
            public function phpredisVersion(): ?string
            {
                return '6.2.0';
            }

            public function predisInstalled(): bool
            {
                return false;
            }
        }] as $drivers) {
            $factory = new StoreFactory($drivers, sys_get_temp_dir());

            self::assertNull(StoreFactory::literalProblem('redis://h', 't'), 'the compile-time rules do not look for a driver');
            self::assertSame('watchtower15.metrics.store: a redis:// store needs ext-redis 6.3 or later, or predis/predis', $factory->problem('redis://h', 't'));
            $status = new PipeStatus($factory, Credentials::metricsToken(), true, 'redis://h', 't', 'auto', null);
            self::assertFalse($status->canBuffer());
            try {
                $factory->create('redis://h', 't');
                self::fail('create() must refuse');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('ext-redis 6.3 or later', $e->getMessage());
            }
        }
    }

    public function testSy5TlsIsSetAClosedPortBuildsNoConnectionAndThePasswordIsNeverShown(): void
    {
        $factory = new StoreFactory(new RedisDrivers(), sys_get_temp_dir());

        $predisOnly = new StoreFactory(new class extends RedisDrivers {
            public function phpredisVersion(): ?string
            {
                return null;
            }
        }, sys_get_temp_dir());
        $tls = $predisOnly->create('rediss://redis.example.test:6380/1', 't');
        self::assertInstanceOf(RedisStore::class, $tls);
        $client = $this->clientOf($tls);
        self::assertInstanceOf(\Predis\Client::class, $client);
        $parameters = $client->getConnection()->getParameters();
        self::assertSame('tls', $parameters->scheme);
        self::assertSame(6380, (int) $parameters->port);
        self::assertSame(1, (int) $parameters->database);
        self::assertInstanceOf(RedisStore::class, $factory->create('rediss://redis.example.test:6380/1', 't'), 'phpredis builds it too, connecting at the first command');

        $dsn = 'redis://u:secret@127.0.0.1:1';
        $store = $factory->create($dsn, 't');
        self::assertInstanceOf(RedisStore::class, $store, 'built with no connection');
        $aggregator = new Aggregator(client: new Client(), store: $store, allowList: null);
        $aggregator->increment('orders');   // the failure is counted by the core, not thrown
        self::assertStringNotContainsString('secret', StoreFactory::mask($dsn));
        self::assertStringContainsString('***', StoreFactory::mask($dsn));
        self::assertNull($factory->problem($dsn, 't'));
    }

    public function testSy5TheOtherSchemesAndTheDefault(): void
    {
        $factory = new StoreFactory(new RedisDrivers(), '/var/app/var/watchtower15');
        $folder = sys_get_temp_dir() . '/wt15-sy5-' . bin2hex(random_bytes(4));

        self::assertInstanceOf(ApcuStore::class, $factory->create('apcu://', 'p'));
        self::assertSame(StoreScope::Sapi, StoreFactory::scopeOf('apcu://'));
        $file = $factory->create('file://' . $folder, null);
        self::assertInstanceOf(FileStore::class, $file);
        self::assertSame(StoreScope::Host, $file->scope());
        self::assertSame($folder, $this->propertyOf($file, 'directory'));
        self::assertInstanceOf(InMemoryStore::class, $factory->create('memory://', null));
        self::assertSame(StoreScope::Process, StoreFactory::scopeOf('memory://'));
        $default = $factory->create(null, null);
        self::assertInstanceOf(FileStore::class, $default);
        self::assertSame('/var/app/var/watchtower15', $this->propertyOf($default, 'directory'));
        self::assertSame(StoreScope::Host, StoreFactory::scopeOf(null));
        self::assertSame(StoreScope::Host, StoreFactory::scopeOf('  '));
        self::assertNull(StoreFactory::scopeOf('ftp://x'));
        self::assertSame(StoreScope::Fleet, StoreFactory::scopeOf('rediss://h'));
    }

    private function propertyOf(object $object, string $name): mixed
    {
        $property = new \ReflectionProperty($object, $name);

        return $property->getValue($object);
    }

    private function clientOf(RedisStore $store): ?object
    {
        $adapter = $this->propertyOf($store, 'redis');
        foreach ((new \ReflectionObject($adapter))->getProperties() as $property) {
            $value = $property->getValue($adapter);
            if ($value instanceof \Redis || $value instanceof \Predis\ClientInterface) {
                return $value;
            }
        }

        return null;
    }

    // ── SY6: the terminate strategy ─────────────────────────────────────────────────────────────────────────────────

    private function strategy(bool $canFinish, string $dsn = 'file:///tmp/wt15-sy6', string $flush = 'auto', ?string $token = null, int &$runs = 0): KernelTerminateStrategy
    {
        $status = new PipeStatus(new StoreFactory(new RedisDrivers(), '/tmp/wt15-sy6'), $token ?? Credentials::metricsToken(), true, $dsn, $dsn === 'apcu://' ? 'p' : 'p', $flush, null);
        $strategy = new KernelTerminateStrategy(new FixedRuntime($canFinish), $status);
        $strategy->register(static function () use (&$runs): void {
            ++$runs;
        });

        return $strategy;
    }

    private function terminate(): TerminateEvent
    {
        return new TerminateEvent($this->createStub(HttpKernelInterface::class), Request::create('/'), new Response());
    }

    private function consoleTerminate(string $name): ConsoleTerminateEvent
    {
        return new ConsoleTerminateEvent(new Command($name), new ArrayInput([]), new NullOutput(), 0);
    }

    public function testSy6AutoFlushesAfterAFinishedResponseAndForApcuInEveryRuntime(): void
    {
        $runs = 0;
        $this->strategy(true, runs: $runs)->onKernelTerminate($this->terminate());
        self::assertSame(1, $runs, 'where the response is finished');

        $runs = 0;
        $this->strategy(false, runs: $runs)->onKernelTerminate($this->terminate());
        self::assertSame(0, $runs, 'where the client would wait');

        $runs = 0;
        $this->strategy(false, 'apcu://', runs: $runs)->onKernelTerminate($this->terminate());
        self::assertSame(1, $runs, 'an apcu:// store is flushed from requests in every runtime');
    }

    public function testSy6AlwaysAndNeverAndTheConsole(): void
    {
        $runs = 0;
        $always = $this->strategy(false, flush: 'always', runs: $runs);
        $always->onKernelTerminate($this->terminate());
        $always->onConsoleTerminate($this->consoleTerminate('app:other'));
        $always->onConsoleTerminate($this->consoleTerminate('watchtower15:flush'));
        self::assertSame(2, $runs, 'always: both, but not the flush command that has just flushed');

        $runs = 0;
        $auto = $this->strategy(false, runs: $runs);
        $auto->onConsoleTerminate($this->consoleTerminate('app:other'));
        $auto->onConsoleTerminate($this->consoleTerminate('watchtower15:flush'));
        self::assertSame(1, $runs, 'auto: another command, not watchtower15:flush');

        $runs = 0;
        $never = $this->strategy(true, flush: 'never', runs: $runs);
        $never->onKernelTerminate($this->terminate());
        $never->onConsoleTerminate($this->consoleTerminate('app:other'));
        self::assertSame(0, $runs);
    }

    public function testSy6NothingRunsWithoutACallbackOrWhileThePipeCannotSendAndAThrowIsNotThrown(): void
    {
        $status = new PipeStatus(new StoreFactory(new RedisDrivers(), '/tmp/wt15-sy6'), Credentials::metricsToken(), true, 'file:///tmp/wt15-sy6', 'p', 'always', null);
        $unregistered = new KernelTerminateStrategy(new FixedRuntime(true), $status);
        $unregistered->onKernelTerminate($this->terminate());   // an aggregator was never built: nothing to run, no store call
        self::assertSame([], TestHttp::$requests);

        $runs = 0;
        $this->strategy(true, token: '   ', runs: $runs)->onKernelTerminate($this->terminate());
        self::assertSame(0, $runs, 'no metrics token: canSend() is false');

        $throwing = new KernelTerminateStrategy(new FixedRuntime(true), $status);
        $throwing->register(static function (): void {
            throw new \RuntimeException('boom');
        });
        $throwing->onKernelTerminate($this->terminate());
        $this->addToAssertionCount(1);
    }

    public function testSy6TwoTerminatesInOneMinuteSendOnceThroughTheAggregatorsClaim(): void
    {
        $kernel = $this->boot(['metrics' => ['token' => Credentials::metricsToken(), 'allow_any' => true, 'flush_after_response' => 'always']]);
        $container = $kernel->getContainer();
        $aggregator = $container->get('watchtower15.aggregator');
        $aggregator->store()->increment(intdiv(time(), 60) - 5, 'orders', 1, 7200);
        TestHttp::queue(TestHttp::json(self::ACCEPTED));
        $strategy = $container->get('watchtower15.terminate_strategy');

        $strategy->onKernelTerminate($this->terminate());
        $afterFirst = count(TestHttp::$requests);
        $strategy->onKernelTerminate($this->terminate());

        self::assertSame(1, $afterFirst);
        self::assertCount(1, TestHttp::$requests, 'the second terminate in the minute sends nothing');
    }

    // ── SY7: the Scheduler ──────────────────────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function schedulerFramework(): array
    {
        return ['messenger' => ['enabled' => true], 'scheduler' => ['enabled' => true]];
    }

    /** @param array<string, mixed> $metrics */
    private function schedule(array $metrics, array $framework = [], bool $literalOnly = false): int
    {
        $kernel = $this->boot(['metrics' => ['token' => Credentials::metricsToken(), 'allow_any' => true] + $metrics], ['framework' => $framework + $this->schedulerFramework()]);

        return count($kernel->getContainer()->get('watchtower15.scheduler.schedule')->getSchedule()->getRecurringMessages());
    }

    public function testSy7AFileOrRedisStoreIsFlushedEveryMinuteAnApcuOrMemoryStoreIsNot(): void
    {
        self::assertSame(1, $this->schedule([]), 'the default file store');
        self::assertSame(1, $this->schedule(['store' => 'redis://h', 'prefix' => 't']));
        self::assertSame(0, $this->schedule(['store' => 'apcu://', 'prefix' => 't']));
        self::assertSame(0, $this->schedule(['store' => 'memory://']));

        foreach (['file:///tmp/wt15-sy7' => 1, 'redis://h' => 1, 'apcu://' => 0, 'memory://' => 0] as $dsn => $expected) {
            $this->env('WT15_SY7_STORE', $dsn);
            $this->env('WT15_SY7_PREFIX', 't');
            self::assertSame($expected, $this->schedule(['store' => '%env(WT15_SY7_STORE)%', 'prefix' => '%env(WT15_SY7_PREFIX)%']), $dsn . ' through the environment');
        }
    }

    public function testSy7TheSchedulerSwitchesTurnTheReceiverOff(): void
    {
        $on = $this->boot(['metrics' => ['token' => Credentials::metricsToken()]], ['framework' => $this->schedulerFramework()]);
        self::assertTrue($on->getContainer()->has('messenger.transport.scheduler_watchtower15'));

        $off = $this->boot(['metrics' => ['token' => Credentials::metricsToken(), 'schedule' => false]], ['framework' => $this->schedulerFramework()]);
        self::assertFalse($off->getContainer()->has('messenger.transport.scheduler_watchtower15'));
        self::assertFalse($off->getContainer()->has('watchtower15.scheduler.schedule'));

        // With the framework's switch off nothing can run the schedule. Symfony 8.1 still registers the receiver's definition for a
        // tagged provider, but no transport factory supports it; older lines register none. Either way, no receiver can be built.
        $frameworkOff = $this->boot(['metrics' => ['token' => Credentials::metricsToken()]], ['framework' => ['messenger' => ['enabled' => true], 'scheduler' => ['enabled' => false]]]);
        $built = false;
        if ($frameworkOff->getContainer()->has('messenger.transport.scheduler_watchtower15')) {
            try {
                $frameworkOff->getContainer()->get('messenger.transport.scheduler_watchtower15');
                $built = true;
            } catch (\Throwable) {
                // expected: no transport factory supports the schedule:// DSN
            }
        }
        self::assertFalse($built, 'the framework\'s switch is off: no scheduler_watchtower15 receiver can be built');
    }

    public function testSy7TheHandlerFlushesOnceAMinuteAndLogsARefusedStoreWithoutAStoreCall(): void
    {
        $kernel = $this->boot(['metrics' => ['token' => Credentials::metricsToken(), 'allow_any' => true]], ['framework' => $this->schedulerFramework()]);
        $container = $kernel->getContainer();
        $container->get('watchtower15.aggregator')->store()->increment(intdiv(time(), 60) - 5, 'orders', 1, 7200);
        TestHttp::queue(TestHttp::json(self::ACCEPTED));
        $handler = $container->get('watchtower15.scheduler.handler');

        $handler(new FlushMetrics());
        $handler(new FlushMetrics());

        self::assertCount(1, TestHttp::$requests, 'the second run in the minute sends nothing');

        // A refused store from the environment: one warning per run on the watchtower15 channel, no store call.
        $this->env('WT15_SY7_STORE', 'ftp://x');
        TestHttp::reset();
        $refused = $this->boot(
            ['metrics' => ['token' => Credentials::metricsToken(), 'store' => '%env(WT15_SY7_STORE)%']],
            ['framework' => $this->schedulerFramework(), 'monolog' => true, 'monologConfig' => ['handlers' => ['spy' => ['type' => 'test', 'channels' => ['watchtower15']]]]],
        );
        $spy = $refused->getContainer()->get('monolog.handler.spy');
        self::assertInstanceOf(TestHandler::class, $spy);
        $refused->getContainer()->get('watchtower15.scheduler.handler')(new FlushMetrics());

        self::assertCount(1, $spy->getRecords());
        self::assertSame('warning', strtolower($spy->getRecords()[0]->level->getName()));
        self::assertSame([], TestHttp::$requests);
        self::assertDirectoryDoesNotExist($refused->getProjectDir() . '/var/watchtower15');
    }

    // ── SY8: Monolog ────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function monologConfig(): array
    {
        return ['handlers' => [
            'watchtower15' => ['type' => 'buffer', 'handler' => 'watchtower15_send', 'level' => 'warning', 'buffer_size' => 100, 'flush_on_overflow' => true, 'channels' => ['!watchtower15']],
            'watchtower15_send' => ['type' => 'service', 'id' => 'watchtower15.monolog_handler', 'nested' => true],
        ]];
    }

    public function testSy8TheHandlerIsBuiltWithClosuresAndTheBufferResetsIntoOnePush(): void
    {
        $definition = null;
        $buffer = null;
        $kernel = $this->boot(
            ['logs' => ['token' => Credentials::logsToken()]],
            ['monolog' => true, 'monologConfig' => $this->monologConfig(), 'inspect' => static function (\Symfony\Component\DependencyInjection\ContainerBuilder $container) use (&$definition, &$buffer): void {
                $definition = $container->getDefinition('watchtower15.monolog_handler');
                $buffer = $container->getDefinition('monolog.handler.watchtower15');
            }],
        );
        $container = $kernel->getContainer();

        self::assertNotNull($definition);
        self::assertCount(4, $definition->getArguments(), 'client, stream, selfLogger and breakerStore; no shutdownStrategy');
        self::assertInstanceOf(\Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument::class, $definition->getArgument(0));
        self::assertInstanceOf(\Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument::class, $definition->getArgument(2));
        $breaker = $definition->getArgument(3);
        self::assertInstanceOf(\Symfony\Component\DependencyInjection\Definition::class, $breaker);
        self::assertSame(\WatchTower15\Logs\Breaker\FileBreakerStore::class, $breaker->getClass());
        self::assertStringEndsWith('/cache/watchtower15', (string) $breaker->getArgument(0));
        self::assertNotNull($buffer);
        self::assertTrue($buffer->hasTag('kernel.reset'));

        self::assertInstanceOf(\WatchTower15\Logs\Monolog\WatchTower15Handler::class, $container->get('watchtower15.monolog_handler'));
        self::assertFalse($container->initialized('watchtower15.logs.client'), 'no client is built until a flush');
        self::assertSame($container->get('monolog.logger.watchtower15'), $container->get('watchtower15.logger'), 'the channel is the SDK\'s logger');

        TestHttp::queue(TestHttp::json(['status' => 'success', 'data' => ['accepted' => true, 'batch_id' => 'b0000000-0000-4000-8000-000000000002', 'lines' => 1]]));
        $container->get('logger')->warning('a buffered line');
        self::assertSame([], TestHttp::$requests, 'buffered, not sent');
        $container->get('services_resetter')->reset();

        self::assertCount(1, TestHttp::$requests, 'one push');
        self::assertStringEndsWith('/api/log-sources/ingest', TestHttp::$requests[0]['url']);
    }

    public function testSy8WithoutMonologBundleThereIsNoHandlerAndTheLoggerIsNull(): void
    {
        $kernel = $this->boot();
        $container = $kernel->getContainer();

        self::assertFalse($container->has('watchtower15.monolog_handler'));
        self::assertInstanceOf(NullLogger::class, $container->get('watchtower15.logger'));
    }

    // ── SY9: the commands ───────────────────────────────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $config @param array<string, mixed> $options */
    private function command(string $name, array $config = [], array $options = []): CommandTester
    {
        $kernel = $this->boot($config, $options);

        return new CommandTester($kernel->getContainer()->get('watchtower15.command.' . $name));
    }

    private function me(): MockResponse
    {
        return TestHttp::json(['data' => ['key' => ['uuid' => 'k', 'name' => 'CI key', 'scopes' => ['servers.view'], 'expires_at' => null], 'user' => ['name' => 'Ada'], 'organisation' => ['name' => 'Demo']]]);
    }

    private function listing(): MockResponse
    {
        return TestHttp::json(['status' => 'success', 'data' => ['metrics' => [['uuid' => 'm1', 'key' => 'orders', 'type' => 'count', 'status' => 'active']], 'limits' => ['max_metrics' => 25, 'used' => 1]]]);
    }

    public function testSy9TestWithNothingSetExitsTwoNamingTheThreeVariables(): void
    {
        $tester = $this->command('test');

        self::assertSame(2, $tester->execute([]));
        self::assertStringContainsString('nothing configured: set WT15_API_KEY, WT15_METRICS_TOKEN or WT15_LOGS_TOKEN', $tester->getDisplay());
        self::assertSame([], TestHttp::$requests);
    }

    public function testSy9TestWithEveryCredentialAnsweredIsGreen(): void
    {
        $tester = $this->command('test', ['api_key' => Credentials::apiKey(), 'metrics' => ['token' => Credentials::metricsToken()], 'logs' => ['token' => Credentials::logsToken()]]);
        TestHttp::queue($this->me(), $this->listing());

        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        $display = $tester->getDisplay();
        self::assertStringContainsString('API key: ok (CI key)', $display);
        self::assertStringContainsString('metrics token: ok', $display);
        self::assertStringContainsString('logs token: format valid; not checked remotely', $display);
        self::assertStringContainsString('metrics store: file://var/watchtower15 (default) (scope Host)', $display);
        self::assertStringContainsString('nothing allowed: no metric is sent', $display);
    }

    public function testSy9TestFailuresMalformedValuesAndARefusedStore(): void
    {
        $failed = $this->command('test', ['api_key' => Credentials::apiKey()]);
        TestHttp::queue(TestHttp::json(['type' => 'about:blank', 'title' => 'Unauthorized', 'status' => 401, 'code' => 'unauthorized', 'detail' => 'No.'], 401));
        self::assertSame(1, $failed->execute([]));
        self::assertStringContainsString('API key: failed', $failed->getDisplay());

        TestHttp::reset();
        $malformed = $this->command('test', ['api_key' => Credentials::apiKey(), 'logs' => ['token' => 'wt15ls_short']]);
        TestHttp::queue($this->me());
        self::assertSame(2, $malformed->execute([]));
        self::assertStringContainsString('logs token: malformed (', $malformed->getDisplay());
        self::assertStringContainsString('API key: ok', $malformed->getDisplay(), 'the other checks still run');

        TestHttp::reset();
        $url = $this->command('test', ['api_key' => Credentials::apiKey(), 'metrics' => ['token' => Credentials::metricsToken()], 'ingest_url' => 'ftp://nope']);
        TestHttp::queue($this->me());
        self::assertSame(2, $url->execute([]));
        self::assertStringContainsString('watchtower15.ingest_url is invalid', $url->getDisplay());
        self::assertStringContainsString('metrics token: format valid; not checked: watchtower15.ingest_url is invalid', $url->getDisplay());
        self::assertStringContainsString('API key: ok', $url->getDisplay());

        TestHttp::reset();
        $this->env('WT15_SY9_STORE', 'ftp://x');
        $refused = $this->command('test', ['api_key' => Credentials::apiKey(), 'metrics' => ['token' => Credentials::metricsToken(), 'store' => '%env(WT15_SY9_STORE)%']]);
        TestHttp::queue($this->me(), $this->listing());
        self::assertSame(1, $refused->execute([]));
        self::assertStringContainsString('metrics store refused: watchtower15.metrics.store: the scheme "ftp"', $refused->getDisplay());
    }

    public function testSy9FlushIsDisabledOrRefusedWithoutAStoreCall(): void
    {
        foreach ([['metrics' => ['token' => Credentials::metricsToken(), 'enabled' => false]], ['metrics' => []]] as $config) {
            $tester = $this->command('flush', $config);
            self::assertSame(0, $tester->execute([]));
            self::assertSame(0, $tester->execute([]), 'twice');
            self::assertStringContainsString('disabled', $tester->getDisplay());
        }

        $this->env('WT15_SY9_STORE', 'ftp://x');
        $kernel = $this->boot(['metrics' => ['token' => Credentials::metricsToken(), 'store' => '%env(WT15_SY9_STORE)%']]);
        $tester = new CommandTester($kernel->getContainer()->get('watchtower15.command.flush'));
        self::assertSame(2, $tester->execute([]));
        self::assertStringContainsString('Nothing was sent:', $tester->getDisplay());
        self::assertDirectoryDoesNotExist($kernel->getProjectDir() . '/var/watchtower15', 'no store call');
        self::assertSame([], TestHttp::$requests);

        $apcu = $this->command('flush', ['metrics' => ['token' => Credentials::metricsToken(), 'store' => 'apcu://', 'prefix' => 'p']]);
        self::assertSame(2, $apcu->execute([]));
        self::assertStringContainsString('APCu is not shared between the CLI and PHP-FPM', $apcu->getDisplay());
        $memory = $this->command('flush', ['metrics' => ['token' => Credentials::metricsToken(), 'store' => 'memory://']]);
        self::assertSame(2, $memory->execute([]));
        self::assertStringContainsString('memory:// lives in one process', $memory->getDisplay());
    }

    public function testSy9FlushSendsAClosedBucketReportsAStopAndTakesTheClaimWithIfDue(): void
    {
        $kernel = $this->boot(['metrics' => ['token' => Credentials::metricsToken(), 'allow_any' => true]]);
        $container = $kernel->getContainer();
        $store = $container->get('watchtower15.bucket_store');
        $tester = new CommandTester($container->get('watchtower15.command.flush'));

        $store->increment(intdiv(time(), 60) - 6, 'orders', 1, 7200);
        TestHttp::queue(TestHttp::json(self::ACCEPTED));
        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        self::assertStringContainsString('Flushed: 1 bucket(s) sent', $tester->getDisplay());

        // A flush marks every closed minute as done, so the failing send gets a kernel (and a store) of its own.
        $failing = $this->boot(['metrics' => ['token' => Credentials::metricsToken(), 'allow_any' => true]]);
        $failing->getContainer()->get('watchtower15.bucket_store')->increment(intdiv(time(), 60) - 5, 'orders', 1, 7200);
        TestHttp::queue(TestHttp::json(['code' => 'service_unavailable', 'status' => 503], 503));
        $failTester = new CommandTester($failing->getContainer()->get('watchtower15.command.flush'));
        self::assertSame(1, $failTester->execute([]), $failTester->getDisplay());
        self::assertStringContainsString('Stopped: transport', $failTester->getDisplay());

        // --if-due: the first run takes the minute's claim and sends; the second prints "Not due" and sends nothing.
        $fresh = $this->boot(['metrics' => ['token' => Credentials::metricsToken(), 'allow_any' => true]]);
        TestHttp::reset();
        $fresh->getContainer()->get('watchtower15.bucket_store')->increment(intdiv(time(), 60) - 5, 'orders', 1, 7200);
        $dueTester = new CommandTester($fresh->getContainer()->get('watchtower15.command.flush'));
        TestHttp::queue(TestHttp::json(self::ACCEPTED));
        self::assertSame(0, $dueTester->execute(['--if-due' => true]), $dueTester->getDisplay());
        $afterFirst = count(TestHttp::$requests);
        self::assertSame(0, $dueTester->execute(['--if-due' => true]));
        self::assertStringContainsString("Not due: another process has claimed this minute's flush.", $dueTester->getDisplay());
        self::assertSame(1, $afterFirst);
        self::assertCount(1, TestHttp::$requests);
    }
}
