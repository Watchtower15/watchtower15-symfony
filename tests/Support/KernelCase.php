<?php

declare(strict_types=1);

namespace WatchTower15\Symfony\Tests\Support;

use PHPUnit\Framework\TestCase;

/** A test case that boots kernels, sets environment variables, and cleans up after itself. */
abstract class KernelCase extends TestCase
{
    /** @var list<TestKernel> */
    private array $kernels = [];

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        TestHttp::reset();
    }

    protected function tearDown(): void
    {
        foreach ($this->kernels as $kernel) {
            $kernel->remove();
        }
        $this->kernels = [];
        foreach ($this->savedEnv as $name => $value) {
            if ($value === false) {
                putenv($name);
                unset($_SERVER[$name], $_ENV[$name]);
            } else {
                putenv($name . '=' . $value);
                $_SERVER[$name] = $_ENV[$name] = $value;
            }
        }
        $this->savedEnv = [];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $options
     */
    protected function boot(array $config = [], array $options = []): TestKernel
    {
        $kernel = new TestKernel($config, $options);
        $this->kernels[] = $kernel;
        $kernel->boot();

        return $kernel;
    }

    protected function env(string $name, ?string $value): void
    {
        $this->savedEnv[$name] ??= getenv($name);
        if ($value === null) {
            putenv($name);
            unset($_SERVER[$name], $_ENV[$name]);
        } else {
            putenv($name . '=' . $value);
            $_SERVER[$name] = $_ENV[$name] = $value;
        }
    }
}
