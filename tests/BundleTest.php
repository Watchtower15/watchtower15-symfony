<?php

declare(strict_types=1);

namespace WatchTower15\Symfony\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Yaml\Yaml;
use WatchTower15\Client;
use WatchTower15\ConfigurationException;
use WatchTower15\Metrics\Aggregator;
use WatchTower15\Metrics\BucketStore;
use WatchTower15\Metrics\Flusher;
use WatchTower15\Store\InMemoryStore;
use WatchTower15\Symfony\Metrics\PipeStatus;
use WatchTower15\Symfony\Tests\Support\Credentials;
use WatchTower15\Symfony\Tests\Support\KernelCase;
use WatchTower15\Symfony\Tests\Support\NoRedisDrivers;
use WatchTower15\Symfony\Tests\Support\TestHttp;

/** SY1, SY2, SY10 to SY14 (plan WT15-SDK-00008 §9.1). */
final class BundleTest extends KernelCase
{
    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function refusedAtCompileTime(): iterable
    {
        yield 'an unknown key' => [['bogus' => 1], 'bogus'];
        yield 'a store scheme the bundle does not know' => [['metrics' => ['store' => 'ftp://x']], 'ftp'];
        yield 'apcu with never' => [['metrics' => ['store' => 'apcu://', 'prefix' => 'p', 'flush_after_response' => 'never']], 'flush_after_response'];
        yield 'memory with never' => [['metrics' => ['store' => 'memory://', 'flush_after_response' => 'never']], 'flush_after_response'];
        yield 'redis without a prefix' => [['metrics' => ['store' => 'redis://h']], 'watchtower15.metrics.prefix'];
        yield 'apcu without a prefix' => [['metrics' => ['store' => 'apcu://']], 'watchtower15.metrics.prefix'];
        yield 'several hosts' => [['metrics' => ['store' => 'redis:?host[a:6379]&host[b:6379]', 'prefix' => 't']], 'Redis Cluster'];
        yield 'a cluster option' => [['metrics' => ['store' => 'redis://x?redis_cluster=1', 'prefix' => 't']], 'Redis Cluster'];
        yield 'a zero timeout' => [['timeout' => 0], 'watchtower15.timeout'];
        yield 'an app_info that is not name/version' => [['app_info' => 'not valid'], 'watchtower15.app_info'];
        yield 'flush_after_response from the environment' => [['metrics' => ['flush_after_response' => '%env(X)%']], 'watchtower15.metrics.flush_after_response'];
        yield 'a timeout from the environment' => [['timeout' => '%env(X)%'], 'watchtower15.timeout'];
        yield 'an allowed kind that is neither count nor level' => [['metrics' => ['allowed' => ['a' => 'gauge']]], 'watchtower15.metrics.allowed.a'];
    }

    public function testSy1NoConfigBootsAndTheDriverlessRedisCompilesAndIsRefusedAtRuntime(): void
    {
        $kernel = $this->boot();
        self::assertInstanceOf(Client::class, $kernel->getContainer()->get('watchtower15.client'));

        $driverless = $this->boot(['metrics' => ['token' => Credentials::metricsToken(), 'store' => 'redis://h', 'prefix' => 't']], ['redisDrivers' => NoRedisDrivers::class]);
        $status = $driverless->getContainer()->get('watchtower15.pipe_status');
        self::assertInstanceOf(PipeStatus::class, $status);
        self::assertFalse($status->canBuffer());
        self::assertStringContainsString('a redis:// store needs ext-redis 6.3 or later, or predis/predis', implode(' ', $status->problems()));
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('refusedAtCompileTime')]
    public function testSy1EachLiteralMistakeIsRefusedWhileTheContainerCompilesNamingTheKey(array $config, string $fragment): void
    {
        try {
            $this->boot($config);
            self::fail('the container must not compile');
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString($fragment, $e->getMessage());
        }
    }

    public function testSy2TheServicesAreAutowirableAndNothingHappensAtBoot(): void
    {
        $kernel = $this->boot([], ['expose' => false, 'services' => static function (\Symfony\Component\DependencyInjection\ContainerBuilder $container): void {
            $container->register('test.consumer', Support\Consumer::class)->setAutowired(true)->setPublic(true);
        }]);
        $container = $kernel->getContainer();

        self::assertSame([], TestHttp::$requests, 'booting makes no HTTP call');
        self::assertDirectoryDoesNotExist($kernel->getProjectDir() . '/var/watchtower15', 'booting makes no store call');
        self::assertTrue($container->has('watchtower15.client'), 'the application client is public');
        self::assertFalse($container->has('watchtower15.metrics.client'));
        self::assertFalse($container->has('watchtower15.logs.client'));

        $consumer = $container->get('test.consumer');
        self::assertInstanceOf(Support\Consumer::class, $consumer);
        self::assertInstanceOf(Client::class, $consumer->client);
        self::assertInstanceOf(Aggregator::class, $consumer->aggregator);
        self::assertInstanceOf(Flusher::class, $consumer->flusher);
        self::assertInstanceOf(BucketStore::class, $consumer->store);
        self::assertSame([], TestHttp::$requests, 'building the services makes no HTTP call either');
    }

    public function testSy10EveryClientNamesSymfonyInItsUserAgent(): void
    {
        $kernel = $this->boot(['api_key' => Credentials::apiKey(), 'metrics' => ['token' => Credentials::metricsToken()], 'logs' => ['token' => Credentials::logsToken()]]);
        $container = $kernel->getContainer();
        TestHttp::queue(
            TestHttp::json(['data' => ['key' => ['uuid' => 'k', 'name' => 'n', 'scopes' => []], 'user' => ['name' => 'u'], 'organisation' => ['name' => 'o']]]),
            TestHttp::json(['status' => 'success', 'data' => ['metrics' => [], 'limits' => []]]),
            TestHttp::json(['status' => 'success', 'data' => ['accepted' => true, 'batch_id' => 'b', 'lines' => 1]]),
        );

        $container->get('watchtower15.client')->me();
        $container->get('watchtower15.metrics.client')->ingest->metrics->list();
        $container->get('watchtower15.logs.client')->ingest->logs->push([['message' => 'hello']]);

        self::assertCount(3, TestHttp::$requests);
        foreach (TestHttp::$requests as $request) {
            $agent = TestHttp::header($request['options'], 'User-Agent');
            self::assertNotNull($agent);
            self::assertMatchesRegularExpression('#php/\d+\.\d+.*symfony/' . preg_quote(Kernel::VERSION, '#') . '#', $agent);
        }
    }

    public function testSy11TheRecipeNamesTheBundleAndTheThreeVariablesAndItsConfigCompiles(): void
    {
        $directory = dirname(__DIR__, 3) . '/tools/flex-recipe/watchtower15/symfony/0.1';
        $manifest = json_decode((string) file_get_contents($directory . '/manifest.json'), true);
        self::assertIsArray($manifest);

        self::assertTrue(class_exists(array_key_first($manifest['bundles'])));
        $names = array_values(array_filter(array_keys($manifest['env']), static fn (string $name): bool => ! str_starts_with($name, '#')));
        self::assertSame(['WT15_API_KEY', 'WT15_METRICS_TOKEN', 'WT15_LOGS_TOKEN'], $names);
        $read = (string) file_get_contents(dirname(__DIR__, 3) . '/packages/sdk/src/Client.php');
        foreach ($names as $name) {
            self::assertStringContainsString($name, $read . (string) file_get_contents(dirname(__DIR__, 3) . '/packages/sdk/src/Internal/Environment.php'), "$name is read by Client::fromEnvironment()");
        }

        $yaml = Yaml::parseFile($directory . '/config/packages/watchtower15.yaml');
        $this->env('WT15_API_KEY', null);
        $this->env('WT15_METRICS_TOKEN', null);
        $this->env('WT15_LOGS_TOKEN', null);
        $kernel = $this->boot($yaml['watchtower15']);
        self::assertTrue($kernel->getContainer()->has('watchtower15.client'));
    }

    public function testSy12EveryPinnedComponentIsOnTheCellsLine(): void
    {
        $line = getenv('WT15_EXPECT_SYMFONY');
        if (! is_string($line) || $line === '') {
            self::markTestSkipped('WT15_EXPECT_SYMFONY is set by the cell script only');
        }
        foreach (['framework-bundle', 'http-kernel', 'dependency-injection', 'config', 'console', 'event-dispatcher', 'http-client', 'cache', 'yaml', 'messenger', 'scheduler'] as $component) {
            $version = \Composer\InstalledVersions::getPrettyVersion('symfony/' . $component);
            self::assertNotNull($version, $component);
            self::assertStringStartsWith($line === '8' ? 'v8.' : 'v' . $line . '.', $version, "symfony/$component is on the line $line");
        }
    }

    /** @return iterable<string, array{0: string, 1: ?string}> */
    public static function runtimeRefusals(): iterable
    {
        yield 'an unknown scheme' => ['WT15_METRICS_STORE', 'ftp://x'];
        yield 'a cluster' => ['WT15_METRICS_STORE', 'redis:?host[a:6379]&host[b:6379]'];
        yield 'redis without a prefix' => ['WT15_METRICS_STORE', 'redis://h'];
    }

    #[DataProvider('runtimeRefusals')]
    public function testSy13ARefusedStoreFromTheEnvironmentDisablesThePipeWithoutThrowing(string $variable, string $value): void
    {
        $this->env($variable, $value);
        $kernel = $this->boot(['metrics' => ['token' => Credentials::metricsToken(), 'store' => '%env(' . $variable . ')%', 'allow_any' => true]]);
        $container = $kernel->getContainer();

        $status = $container->get('watchtower15.pipe_status');
        self::assertFalse($status->canBuffer());
        self::assertNotSame([], $status->problems());
        $aggregator = $container->get('watchtower15.aggregator');
        $aggregator->increment('orders');
        self::assertInstanceOf(InMemoryStore::class, $aggregator->store(), 'no real store was built');
        self::assertSame([], TestHttp::$requests);
    }

    public function testSy13AHostlessDriverAndBlankCredentialsFromThePlainEnvForm(): void
    {
        $this->env('WT15_METRICS_STORE', 'redis://h');
        $this->env('WT15_METRICS_PREFIX', 't');
        $this->env('WT15_API_KEY', '');
        $this->env('WT15_METRICS_TOKEN', '');
        $this->env('WT15_LOGS_TOKEN', '');
        $kernel = $this->boot(
            ['api_key' => '%env(WT15_API_KEY)%', 'metrics' => ['token' => '%env(WT15_METRICS_TOKEN)%', 'store' => '%env(WT15_METRICS_STORE)%', 'prefix' => '%env(WT15_METRICS_PREFIX)%'], 'logs' => ['token' => '%env(WT15_LOGS_TOKEN)%']],
            ['redisDrivers' => NoRedisDrivers::class],
        );
        $container = $kernel->getContainer();

        $status = $container->get('watchtower15.pipe_status');
        self::assertFalse($status->hasToken(), '"" is read as unset');
        self::assertFalse($status->canBuffer());
        self::assertInstanceOf(Client::class, $container->get('watchtower15.client'), '"" credentials are not given: the client builds');
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function malformedValues(): iterable
    {
        yield 'the API key' => ['WT15_API_KEY', 'not-a-key'];
        yield 'the metrics token' => ['WT15_METRICS_TOKEN', 'wt15ms_truncated'];
        yield 'the logs token' => ['WT15_LOGS_TOKEN', 'wt15ls_truncated'];
        yield 'api_url' => ['WT15_API_URL', 'ftp://nope'];
        yield 'ingest_url' => ['WT15_INGEST_URL', 'ftp://nope'];
    }

    #[DataProvider('malformedValues')]
    public function testSy14OneMalformedValueBreaksNothingButTheClientThatIsAskedForIt(string $variable, string $value): void
    {
        foreach (['WT15_API_KEY' => Credentials::apiKey(), 'WT15_METRICS_TOKEN' => Credentials::metricsToken(), 'WT15_LOGS_TOKEN' => Credentials::logsToken(), 'WT15_API_URL' => null, 'WT15_INGEST_URL' => null] as $name => $good) {
            $this->env($name, $name === $variable ? $value : $good);
        }
        $kernel = $this->boot(
            [
                'api_key' => '%env(default::WT15_API_KEY)%', 'api_url' => '%env(default::WT15_API_URL)%', 'ingest_url' => '%env(default::WT15_INGEST_URL)%',
                'metrics' => ['token' => '%env(default::WT15_METRICS_TOKEN)%', 'allow_any' => true], 'logs' => ['token' => '%env(default::WT15_LOGS_TOKEN)%'],
            ],
            ['monolog' => true, 'monologConfig' => ['handlers' => [
                'watchtower15' => ['type' => 'buffer', 'handler' => 'watchtower15_send', 'level' => 'warning', 'buffer_size' => 100, 'flush_on_overflow' => true, 'channels' => ['!watchtower15']],
                'watchtower15_send' => ['type' => 'service', 'id' => 'watchtower15.monolog_handler', 'nested' => true],
            ]]],
        );
        $container = $kernel->getContainer();

        // The kernel boots and handles a request.
        $response = $kernel->handle(\Symfony\Component\HttpFoundation\Request::create('/'));
        self::assertSame(404, $response->getStatusCode());
        // A warning on the app channel is logged, whatever the logs token or ingest_url, and nothing throws.
        $container->get('logger')->warning('a line');
        $container->get('services_resetter')->reset();
        // increment() does not throw.
        $aggregator = $container->get('watchtower15.aggregator');
        $aggregator->increment('orders');

        if ($variable === 'WT15_METRICS_TOKEN') {
            // A closed minute bucket (five minutes ago), so the flush has something to send.
            $closed = intdiv(time(), 60) - 5;
            $aggregator->store()->increment($closed, 'orders', 1, 7200);
            self::assertSame('config', $aggregator->flush()->stopReason, 'a malformed metrics token stops the flush on config');
            self::assertNotSame([], $aggregator->store()->read($closed), 'the bucket is kept');
        }
        if ($variable === 'WT15_INGEST_URL') {
            $status = $container->get('watchtower15.pipe_status');
            self::assertFalse($status->canSend());
            $tester = new \Symfony\Component\Console\Tester\CommandTester($container->get('watchtower15.command.flush'));
            self::assertSame(2, $tester->execute([]));
        }
        // WatchTower15\Client throws when asked for, in every case but the metrics token's; nothing else throws.
        try {
            self::assertInstanceOf(Client::class, $container->get('watchtower15.client'));
            self::assertSame('WT15_METRICS_TOKEN', $variable);
        } catch (ConfigurationException) {
            self::assertNotSame('WT15_METRICS_TOKEN', $variable);
        }
    }
}
