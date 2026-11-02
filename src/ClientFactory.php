<?php

declare(strict_types=1);

namespace WatchTower15\Symfony;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Kernel;
use WatchTower15\Client;
use WatchTower15\Http\Transport;
use WatchTower15\Metrics\CallableTokenProvider;
use WatchTower15\Symfony\Support\UrlRule;

/**
 * Every `Client` the bundle builds (D4, D5): over the bundle's transport with the SDK's logger, naming Symfony and its version in the
 * `User-Agent`. A blank credential is not given, a blank URL is the core's default. The application's client throws the core's
 * `ConfigurationException` at construction for a malformed credential or URL, to the code that asks for it and to nothing else;
 * the metrics client never throws (its token is a provider read when a chunk is sent, a URL that fails the rule is left out); the
 * logs client throws only to the handler's closure.
 *
 * @internal
 */
final class ClientFactory
{
    public function __construct(private readonly ?Transport $transport, private readonly LoggerInterface $logger)
    {
    }

    public function application(
        #[\SensitiveParameter] ?string $apiKey,
        #[\SensitiveParameter] ?string $metricsToken,
        #[\SensitiveParameter] ?string $logsToken,
        ?string $apiUrl,
        ?string $ingestUrl,
        mixed $timeout = null,
        mixed $connectTimeout = null,
        mixed $maxRetries = null,
        ?string $appInfo = null,
    ): Client {
        $metricsToken = self::text($metricsToken);
        $arguments = [
            'apiKey' => $apiKey,
            'metricsToken' => $metricsToken === null ? null : new CallableTokenProvider(static fn (): string => $metricsToken),
            'logsToken' => $logsToken,
        ];
        foreach (['timeout' => $timeout, 'connectTimeout' => $connectTimeout] as $name => $value) {
            if (is_numeric($value)) {
                $arguments[$name] = (float) $value;
            }
        }
        if (is_numeric($maxRetries)) {
            $arguments['maxRetries'] = (int) $maxRetries;
        }
        if ($appInfo !== null && trim($appInfo) !== '') {
            $arguments['appInfo'] = trim($appInfo);
        }

        return $this->make($arguments, self::text($apiUrl), self::text($ingestUrl));
    }

    /** The aggregator's client: the metrics token as a provider and `ingest_url` only when it passes the core's rule. Never throws. */
    public function metrics(#[\SensitiveParameter] ?string $metricsToken, ?string $ingestUrl): Client
    {
        $token = self::text($metricsToken);
        $url = self::text($ingestUrl);

        return $this->make(
            ['metricsToken' => $token === null ? null : new CallableTokenProvider(static fn (): string => $token)],
            null,
            $url !== null && UrlRule::problem($url, 'ingestUrl') === null ? $url : null,
        );
    }

    /** The log handler's client: the logs token and `ingest_url` alone. Throws for a malformed one, which only the handler's closure meets. */
    public function logs(#[\SensitiveParameter] ?string $logsToken, ?string $ingestUrl): Client
    {
        return $this->make(['logsToken' => $logsToken], null, self::text($ingestUrl));
    }

    /** A client holding exactly the given credentials and URLs, for `watchtower15:test`. */
    public function only(
        #[\SensitiveParameter] ?string $apiKey = null,
        #[\SensitiveParameter] ?string $metricsToken = null,
        #[\SensitiveParameter] ?string $logsToken = null,
        ?string $apiUrl = null,
        ?string $ingestUrl = null,
    ): Client {
        return $this->make(['apiKey' => $apiKey, 'metricsToken' => $metricsToken, 'logsToken' => $logsToken], self::text($apiUrl), self::text($ingestUrl));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function make(#[\SensitiveParameter] array $arguments, ?string $apiUrl, ?string $ingestUrl): Client
    {
        $arguments['logger'] = $this->logger;
        if ($this->transport !== null) {
            $arguments['httpClient'] = $this->transport;
        }
        if ($apiUrl !== null) {
            $arguments['apiUrl'] = $apiUrl;
        }
        if ($ingestUrl !== null) {
            $arguments['ingestUrl'] = $ingestUrl;
        }
        /** @phpstan-ignore argument.type (the keys are the core's named arguments, built above) */
        $client = new Client(...$arguments);
        $client->useFramework('symfony', Kernel::VERSION);

        return $client;
    }

    private static function text(#[\SensitiveParameter] ?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : $value;
    }
}
