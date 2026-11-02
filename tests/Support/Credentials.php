<?php

declare(strict_types=1);

namespace WatchTower15\Symfony\Tests\Support;

/** Credentials of a valid form (the core's checks read the form only), made here because the cells install no test class of the core. */
final class Credentials
{
    public static function apiKey(): string
    {
        $body = str_repeat('a', 64);
        $alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $number = crc32($body);
        $encoded = '';
        while ($number > 0) {
            $encoded = $alphabet[$number % 62] . $encoded;
            $number = intdiv($number, 62);
        }

        return 'wt15ak_' . $body . str_pad($encoded, 6, '0', STR_PAD_LEFT);
    }

    public static function metricsToken(): string
    {
        return 'wt15ms_' . str_repeat('b', 64);
    }

    public static function logsToken(): string
    {
        return 'wt15ls_' . str_repeat('c', 64);
    }
}
