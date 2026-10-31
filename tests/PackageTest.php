<?php

declare(strict_types=1);

namespace WatchTower15\Symfony\Tests;

use WatchTower15\Symfony\Package;
use PHPUnit\Framework\TestCase;

final class PackageTest extends TestCase
{
    public function testNameIsTheNameInComposerJson(): void
    {
        $json = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true);

        self::assertIsArray($json);
        self::assertSame($json['name'], Package::NAME);
    }

    public function testVersionIsANonEmptyString(): void
    {
        self::assertNotSame('', Package::version());
    }
}
