<?php

declare(strict_types=1);

namespace WatchTower15\Symfony;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use WatchTower15\Symfony\Metrics\StoreFactory;

/**
 * The WatchTower15 bundle (plan WT15-SDK-00008 D2, D3): config root `watchtower15`. Every key is optional. The credentials, the two
 * URLs and the metrics `enabled`, `store` and `prefix` may come from `%env()%` and are read when a service is first built; every
 * other key is a literal, checked here while the container compiles, so a mistake fails `cache:clear` with its message and no
 * pipe client can throw on it. Nothing is built, sent or logged at boot.
 */
final class WatchTower15Bundle extends AbstractBundle
{
    protected string $extensionAlias = 'watchtower15';

    public function configure(DefinitionConfigurator $definition): void
    {
        // `variableNode` for the keys that may come from `%env()%`: a scalar node refuses `%env(default::NAME)%` on Symfony 6.4 and 7.4, whose
        // `default` processor provides `array` among its types. The literal keys are scalar nodes, checked in loadExtension().
        $definition->rootNode()
            ->children()
                ->variableNode('api_key')->defaultNull()->end()
                ->variableNode('api_url')->defaultNull()->end()
                ->variableNode('ingest_url')->defaultNull()->end()
                ->scalarNode('timeout')->defaultNull()->end()
                ->scalarNode('connect_timeout')->defaultNull()->end()
                ->scalarNode('max_retries')->defaultNull()->end()
                ->scalarNode('app_info')->defaultNull()->end()
                ->scalarNode('http_client')->defaultValue('watchtower15.http_client')->end()
                ->arrayNode('metrics')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->variableNode('token')->defaultNull()->end()
                        ->variableNode('enabled')->defaultValue(true)->end()
                        ->variableNode('store')->defaultNull()->end()
                        ->variableNode('prefix')->defaultNull()->end()
                        ->arrayNode('allowed')->normalizeKeys(false)->useAttributeAsKey('metric')->scalarPrototype()->end()->end()
                        ->scalarNode('allow_any')->defaultValue(false)->end()
                        ->scalarNode('heartbeat')->defaultNull()->end()
                        ->scalarNode('flush_after_response')->defaultValue('auto')->end()
                        ->scalarNode('schedule')->defaultValue(true)->end()
                    ->end()
                ->end()
                ->arrayNode('logs')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->variableNode('token')->defaultNull()->end()
                    ->end()
                ->end()
            ->end();
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if ($builder->hasExtension('monolog')) {
            $container->extension('monolog', ['channels' => ['watchtower15']]);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $metrics = (array) $config['metrics'];
        $logs = (array) $config['logs'];
        $allowList = $this->checkLiterals($config, $metrics, $builder);

        $parameters = $container->parameters();
        $parameters->set('watchtower15.api_key', $config['api_key']);
        $parameters->set('watchtower15.api_url', $config['api_url']);
        $parameters->set('watchtower15.ingest_url', $config['ingest_url']);
        $parameters->set('watchtower15.timeout', $config['timeout']);
        $parameters->set('watchtower15.connect_timeout', $config['connect_timeout']);
        $parameters->set('watchtower15.max_retries', $config['max_retries']);
        $parameters->set('watchtower15.app_info', $config['app_info']);
        $parameters->set('watchtower15.http_client', $config['http_client']);
        $parameters->set('watchtower15.logs.token', $logs['token']);
        $parameters->set('watchtower15.metrics.token', $metrics['token']);
        $parameters->set('watchtower15.metrics.enabled', $metrics['enabled']);
        $parameters->set('watchtower15.metrics.store', $metrics['store']);
        $parameters->set('watchtower15.metrics.prefix', $metrics['prefix']);
        $parameters->set('watchtower15.metrics.allow_list', $allowList);
        $parameters->set('watchtower15.metrics.heartbeat', $metrics['heartbeat']);
        $parameters->set('watchtower15.metrics.flush_after_response', $metrics['flush_after_response']);
        $parameters->set('watchtower15.metrics.schedule', $this->bool($metrics['schedule']));

        $container->import('../config/services.php');
    }

    /**
     * The compile-time rules (D3, D7). Returns the aggregator's allow-list: null for `allow_any`, else the `allowed` map.
     *
     * @param array<string, mixed> $config
     * @param array<mixed, mixed> $metrics
     *
     * @return array<string, string>|null
     */
    private function checkLiterals(array $config, array $metrics, ContainerBuilder $builder): ?array
    {
        $literal = static function (string $key, mixed $value) use ($builder): void {
            if (is_string($value)) {
                $used = [];
                $builder->resolveEnvPlaceholders($value, null, $used);
                if ($used !== []) {
                    throw new InvalidConfigurationException(sprintf('The value of "watchtower15.%s" must be a literal: it is checked while the container compiles, so it cannot come from an environment variable.', $key));
                }
            }
        };
        foreach (['timeout', 'connect_timeout', 'max_retries', 'app_info'] as $key) {
            $literal($key, $config[$key]);
        }
        foreach (['allow_any', 'heartbeat', 'flush_after_response', 'schedule'] as $key) {
            $literal('metrics.' . $key, $metrics[$key]);
        }
        foreach ((array) $metrics['allowed'] as $name => $kind) {
            $literal('metrics.allowed', $name);
            $literal('metrics.allowed', $kind);
        }

        $timeout = $config['timeout'];
        if ($timeout !== null && (! is_numeric($timeout) || (float) $timeout <= 0 || (float) $timeout > 300)) {
            throw new InvalidConfigurationException('"watchtower15.timeout" must be above 0 and at most 300 seconds.');
        }
        $connect = $config['connect_timeout'];
        if ($connect !== null && (! is_numeric($connect) || (float) $connect <= 0 || (float) $connect > ($timeout !== null ? (float) $timeout : 15.0))) {
            throw new InvalidConfigurationException('"watchtower15.connect_timeout" must be above 0 and not above the timeout.');
        }
        $retries = $config['max_retries'];
        if ($retries !== null && (! is_numeric($retries) || (int) $retries != $retries || (int) $retries < 0 || (int) $retries > 10)) {
            throw new InvalidConfigurationException('"watchtower15.max_retries" must be a whole number from 0 to 10.');
        }
        $appInfo = $config['app_info'];
        if ($appInfo !== null && (! is_string($appInfo) || preg_match('#\A[A-Za-z0-9._-]+/[A-Za-z0-9._+-]+\z#', $appInfo) !== 1)) {
            throw new InvalidConfigurationException('"watchtower15.app_info" must look like name/version, with letters, digits and ._+- only.');
        }
        if (! in_array($metrics['flush_after_response'], ['auto', 'always', 'never'], true)) {
            throw new InvalidConfigurationException('"watchtower15.metrics.flush_after_response" must be auto, always or never.');
        }
        foreach (['allow_any', 'schedule'] as $key) {
            if (! is_bool($metrics[$key]) && ! in_array($metrics[$key], ['true', 'false', '1', '0', 1, 0], true)) {
                throw new InvalidConfigurationException(sprintf('"watchtower15.metrics.%s" must be true or false.', $key));
            }
        }
        if ($metrics['heartbeat'] !== null && (! is_string($metrics['heartbeat']) || trim($metrics['heartbeat']) === '')) {
            throw new InvalidConfigurationException('"watchtower15.metrics.heartbeat" must be a metric key or null.');
        }
        $allowed = [];
        foreach ((array) $metrics['allowed'] as $name => $kind) {
            if (! in_array($kind, ['count', 'level'], true)) {
                throw new InvalidConfigurationException(sprintf('"watchtower15.metrics.allowed.%s" must be "count" or "level".', (string) $name));
            }
            $allowed[(string) $name] = (string) $kind;
        }

        // The store, when neither it nor its prefix comes from the environment: the rules that read only the config.
        $usedStore = [];
        foreach (['store', 'prefix'] as $key) {
            if (is_string($metrics[$key])) {
                $builder->resolveEnvPlaceholders($metrics[$key], null, $usedStore);
            }
        }
        if ($usedStore === []) {
            $problem = StoreFactory::literalProblem(
                is_string($metrics['store']) ? $metrics['store'] : null,
                is_string($metrics['prefix']) ? $metrics['prefix'] : null,
                (string) $metrics['flush_after_response'],
            );
            if ($problem !== null) {
                throw new InvalidConfigurationException($problem);
            }
        }

        return $this->bool($metrics['allow_any']) ? null : $allowed;
    }

    private function bool(mixed $value): bool
    {
        return is_bool($value) ? $value : in_array($value, ['true', '1', 1], true);
    }
}
