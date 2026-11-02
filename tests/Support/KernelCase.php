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

    private mixed $exceptionHandlerBefore = null;

    private mixed $errorHandlerBefore = null;

    protected function setUp(): void
    {
        TestHttp::reset();
        // Booting a kernel registers Symfony's error and exception handlers (FrameworkBundle on 6.4 and 7.4), which PHPUnit 12 reports as
        // risky when a test leaves them: remember the ones in place, and put them back after each test.
        $this->exceptionHandlerBefore = set_exception_handler(null);
        restore_exception_handler();
        $this->errorHandlerBefore = set_error_handler(null);
        restore_error_handler();
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
        for ($i = 0; $i < 20; ++$i) {
            $current = set_exception_handler(null);
            restore_exception_handler();
            if ($current === $this->exceptionHandlerBefore) {
                break;
            }
            restore_exception_handler();
        }
        for ($i = 0; $i < 20; ++$i) {
            $current = set_error_handler(null);
            restore_error_handler();
            if ($current === $this->errorHandlerBefore) {
                break;
            }
            restore_error_handler();
        }
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
