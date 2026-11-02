<?php

declare(strict_types=1);

namespace WatchTower15\Symfony\Tests\Support;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpKernel\Kernel;
use WatchTower15\Symfony\WatchTower15Bundle;

/**
 * A micro-kernel for the bundle's tests (plan WT15-SDK-00008 §9.1): FrameworkBundle, optionally MonologBundle, and the bundle, in its
 * own temporary project folder. Options: `monolog` (bool), `debug` (bool), `framework` (extra framework config), `monologConfig`,
 * `mockHttp` (default true: the bundle's client answers from TestHttp), `redisDrivers` (a class replacing the seam), `runtime`
 * (a class), `expose` (default true: every service public, so a test can read it), `inspect` (a callback given the builder before
 * private services are removed), `services` (a callback given the builder to add definitions).
 */
final class TestKernel extends Kernel
{
    private readonly string $dir;

    /**
     * @param array<string, mixed> $config the `watchtower15` tree
     * @param array<string, mixed> $options
     */
    public function __construct(private readonly array $config = [], private readonly array $options = [])
    {
        $this->dir = sys_get_temp_dir() . '/wt15-sy-' . bin2hex(random_bytes(6));
        (new Filesystem())->mkdir($this->dir);
        parent::__construct('test', (bool) ($options['debug'] ?? false));
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        if ($this->options['monolog'] ?? false) {
            yield new MonologBundle();
        }
        yield new WatchTower15Bundle();
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container): void {
            $framework = ['secret' => 'x', 'http_method_override' => false, 'php_errors' => ['log' => false]] + (array) ($this->options['framework'] ?? []);
            $container->loadFromExtension('framework', $framework);
            if ($this->options['monolog'] ?? false) {
                $container->loadFromExtension('monolog', (array) ($this->options['monologConfig'] ?? []));
            }
            $container->loadFromExtension('watchtower15', $this->config);
        });
    }

    protected function build(ContainerBuilder $container): void
    {
        $options = $this->options;
        $container->addCompilerPass(new class($options) implements CompilerPassInterface {
            /** @param array<string, mixed> $options */
            public function __construct(private readonly array $options)
            {
            }

            public function process(ContainerBuilder $container): void
            {
                if (($this->options['mockHttp'] ?? true) && $container->hasDefinition('watchtower15.http_client')) {
                    $client = $container->getDefinition('watchtower15.http_client');
                    $client->setClass(MockHttpClient::class)->setFactory(null)->setArguments([[TestHttp::class, 'respond']]);
                }
                foreach (['redisDrivers' => 'watchtower15.redis_drivers', 'runtime' => 'watchtower15.runtime'] as $option => $id) {
                    if (isset($this->options[$option]) && $container->hasDefinition($id)) {
                        $container->setDefinition($id, new Definition($this->options[$option], $option === 'runtime' ? [false] : []));
                    }
                }
                if (isset($this->options['services'])) {
                    ($this->options['services'])($container);
                }
            }
        }, PassConfig::TYPE_BEFORE_OPTIMIZATION, 1000);

        $container->addCompilerPass(new class($options) implements CompilerPassInterface {
            /** @param array<string, mixed> $options */
            public function __construct(private readonly array $options)
            {
            }

            public function process(ContainerBuilder $container): void
            {
                if (isset($this->options['inspect'])) {
                    ($this->options['inspect'])($container);
                }
                if ($this->options['expose'] ?? true) {
                    // Only what a test reads: publicising every service would keep decorators whose decorated service is absent.
                    $wanted = static fn (string $id): bool => preg_match('#^(watchtower15\.|monolog\.|test\.|WatchTower15\\\\)#', $id) === 1
                        || in_array($id, ['logger', 'services_resetter', 'data_collector.http_client', 'http_client', 'profiler', 'messenger.receiver_locator', 'messenger.transport.scheduler_watchtower15'], true);
                    foreach ($container->getDefinitions() as $id => $definition) {
                        if ($wanted((string) $id) && ! $definition->isAbstract()) {
                            $definition->setPublic(true);
                        }
                    }
                    foreach ($container->getAliases() as $id => $alias) {
                        if ($wanted((string) $id) && ($container->hasDefinition((string) $alias) || $container->hasAlias((string) $alias))) {
                            $alias->setPublic(true);
                        }
                    }
                }
            }
        }, PassConfig::TYPE_BEFORE_REMOVING, 1000);
    }

    public function getProjectDir(): string
    {
        return $this->dir;
    }

    public function getCacheDir(): string
    {
        return $this->dir . '/cache';
    }

    public function getLogDir(): string
    {
        return $this->dir . '/log';
    }

    public function remove(): void
    {
        $this->shutdown();
        (new Filesystem())->remove($this->dir);
    }
}
