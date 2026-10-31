<?php

declare(strict_types=1);

namespace WatchTower15\Symfony;

use Composer\InstalledVersions;

final class Package
{
    public const NAME = 'watchtower15/symfony';

    public static function version(): string
    {
        if (InstalledVersions::isInstalled(self::NAME)) {
            $version = InstalledVersions::getPrettyVersion(self::NAME);
            if ($version !== null) {
                return $version;
            }
        }

        return 'dev';
    }
}
