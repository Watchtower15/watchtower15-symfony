<?php

declare(strict_types=1);

namespace WatchTower15\Symfony\Lifecycle;

/**
 * Whether the response is already finished when `kernel.terminate` runs (D8): true where `Response::send()` could finish the
 * request, that is where `fastcgi_finish_request()` (PHP-FPM, FrankenPHP) or `litespeed_finish_request()` exists, never decided
 * by the SAPI's name. A one-method seam, so a test can set it.
 *
 * @internal
 */
class Runtime
{
    public function canFinishResponse(): bool
    {
        return function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request');
    }
}
