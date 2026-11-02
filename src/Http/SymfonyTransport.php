<?php

declare(strict_types=1);

namespace WatchTower15\Symfony\Http;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use WatchTower15\Exception\ApiConnectionException;
use WatchTower15\Http\Transport;
use WatchTower15\Http\TransportOptions;

/**
 * The core's transport over Symfony's HTTP client (plan WT15-SDK-00008 D6), so the profiler shows every call. Every status is
 * returned (`getContent(false)`); the response cap is enforced from `on_progress`; a failure is an `ApiConnectionException`
 * built from scalars, with no `previous`, since a Symfony `TransportException` is thrown from the client's response loop whose
 * frames hold the request's headers (SDK02 D5). Redirects are never followed. `HttpClientInterface` has no portable connect
 * timeout, so the connect is bounded by the total.
 *
 * @internal
 */
final class SymfonyTransport implements Transport
{
    public function __construct(private readonly HttpClientInterface $client)
    {
    }

    public function send(#[\SensitiveParameter] RequestInterface $request, TransportOptions $options): ResponseInterface
    {
        $cap = $options->maxResponseBytes;
        $tooLarge = false;
        $failure = null;
        $status = 0;
        $headers = [];
        $content = '';
        try {
            $response = $this->client->request($request->getMethod(), (string) $request->getUri(), [
                'headers' => self::headersOf($request),
                'body' => (string) $request->getBody(),
                'timeout' => $options->timeout,
                'max_duration' => $options->timeout,
                'max_redirects' => 0,
                'on_progress' => static function (int $downloaded, int $size) use ($cap, &$tooLarge): void {
                    if ($downloaded > $cap || $size > $cap) {
                        $tooLarge = true;

                        throw new \RuntimeException('response too large');
                    }
                },
            ]);
            $status = $response->getStatusCode();
            $headers = $response->getHeaders(false);
            $content = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            // Only scalars leave this block: the cause is dropped, whatever frames it holds.
            $failure = [$e::class, $e->getMessage()];
        }
        if ($failure !== null || $tooLarge) {
            throw self::failure($failure[0] ?? '', $failure[1] ?? '', $tooLarge);
        }

        /** @var array<string, list<string>> $headers */
        return new Response($status, $headers, $content);
    }

    /**
     * @return array<string, string>
     */
    private static function headersOf(#[\SensitiveParameter] RequestInterface $request): array
    {
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[(string) $name] = implode(', ', $values);
        }

        return $headers;
    }

    private static function failure(string $class, string $message, bool $tooLarge): ApiConnectionException
    {
        if ($tooLarge) {
            return new ApiConnectionException('The response is larger than the cap.', true, true, 'too_large');
        }

        return new ApiConnectionException($class . ': ' . $message, true, false, 'other');
    }
}
