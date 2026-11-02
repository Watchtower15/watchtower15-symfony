<?php

declare(strict_types=1);

namespace WatchTower15\Symfony\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The answers and the log of the bundle's HTTP client in a test kernel. Static, because a kernel's container is dumped to PHP and
 * can hold no object: the test kernel's client is `new MockHttpClient([TestHttp::class, 'respond'])`.
 */
final class TestHttp
{
    /** @var list<MockResponse> */
    private static array $queue = [];

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    public static array $requests = [];

    public static function reset(): void
    {
        self::$queue = [];
        self::$requests = [];
    }

    public static function queue(MockResponse ...$responses): void
    {
        foreach ($responses as $response) {
            self::$queue[] = $response;
        }
    }

    /** @param array<string, mixed> $data */
    public static function json(array $data, int $status = 200): MockResponse
    {
        return new MockResponse((string) json_encode($data), ['http_code' => $status, 'response_headers' => ['content-type: application/json', 'date: ' . gmdate('D, d M Y H:i:s') . ' GMT']]);
    }

    /** @param array<string, mixed> $options */
    public static function respond(string $method, string $url, array $options): ResponseInterface
    {
        self::$requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

        return array_shift(self::$queue) ?? self::json(['data' => []]);
    }

    /** The value of a request header as the client normalised it, or null. @param array<string, mixed> $options */
    public static function header(array $options, string $name): ?string
    {
        $headers = $options['normalized_headers'][strtolower($name)] ?? null;
        if (! is_array($headers) || $headers === []) {
            return null;
        }

        return (string) preg_replace('/^[^:]+:\s*/', '', (string) $headers[0]);
    }
}
