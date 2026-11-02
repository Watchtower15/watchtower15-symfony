<?php

declare(strict_types=1);

namespace WatchTower15\Symfony\Lifecycle;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use WatchTower15\Lifecycle\ShutdownStrategy;
use WatchTower15\Metrics\StoreScope;
use WatchTower15\Symfony\Metrics\PipeStatus;

/**
 * The after-response flush (plan WT15-SDK-00008 D8), in place of the core's `PhpShutdownStrategy`: it keeps the callback the
 * aggregator's `registerShutdown()` hands it when the aggregator is first built, and runs it on `kernel.terminate` and
 * `console.terminate` as `flush_after_response` says. A process that never built the aggregator has no callback, so its terminate
 * makes no store call. A failure is counted by the core, never thrown here.
 *
 * @internal
 */
final class KernelTerminateStrategy implements ShutdownStrategy, EventSubscriberInterface
{
    private ?\Closure $callback = null;

    public function __construct(private readonly Runtime $runtime, private readonly PipeStatus $status)
    {
    }

    public function register(\Closure $callback): void
    {
        $this->callback = $callback;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => 'onKernelTerminate',
            ConsoleEvents::TERMINATE => 'onConsoleTerminate',
        ];
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        if (! $this->ready()) {
            return;
        }
        $mode = $this->status->flushAfterResponse();
        // `auto`: where the response is already finished, and for a store no CLI can flush (APCu is per SAPI) in every runtime.
        if ($mode === 'always' || ($mode === 'auto' && ($this->runtime->canFinishResponse() || $this->status->scope() === StoreScope::Sapi))) {
            $this->run();
        }
    }

    public function onConsoleTerminate(ConsoleTerminateEvent $event): void
    {
        if (! $this->ready() || $event->getCommand()?->getName() === 'watchtower15:flush') {
            return;
        }
        if (in_array($this->status->flushAfterResponse(), ['auto', 'always'], true)) {
            $this->run();
        }
    }

    private function ready(): bool
    {
        return $this->callback !== null && $this->status->canSend();
    }

    private function run(): void
    {
        $callback = $this->callback;
        if ($callback === null) {
            return;
        }
        try {
            $callback();
        } catch (\Throwable) {
            // Never thrown (D-SDK12): the core counts what failed.
        }
    }
}
