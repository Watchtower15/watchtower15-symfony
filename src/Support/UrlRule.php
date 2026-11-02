<?php

declare(strict_types=1);

namespace WatchTower15\Symfony\Support;

use WatchTower15\Client;
use WatchTower15\ConfigurationException;

/**
 * D4: the core's URL rule (SDK02 D3), applied to the two base URLs before any `Client` is built with them. The core exposes no
 * public call, so a `Client` is built with the URL alone and its refusal read: one rule, one copy.
 *
 * @internal
 */
final class UrlRule
{
    /**
     * @param 'apiUrl'|'ingestUrl' $argument
     *
     * @return ?string null when the URL is usable or blank, else why it is not; never the URL itself
     */
    public static function problem(?string $url, string $argument): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }
        try {
            if ($argument === 'apiUrl') {
                new Client(apiUrl: $url);
            } else {
                new Client(ingestUrl: $url);
            }
        } catch (ConfigurationException $e) {
            return trim((string) preg_replace('/`' . $argument . '`/', 'it', $e->getMessage()), ". \t\n");
        }

        return null;
    }

    /** The configuration key and the variable a URL comes from, for a message. */
    public static function key(string $argument): string
    {
        return $argument === 'apiUrl' ? 'watchtower15.api_url' : 'watchtower15.ingest_url';
    }
}
