<?php

declare(strict_types=1);

namespace WatchTower15\Symfony\Scheduler;

use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use WatchTower15\Metrics\StoreScope;
use WatchTower15\Symfony\Metrics\PipeStatus;

/**
 * The schedule `watchtower15`, registered when `symfony/scheduler` is installed (D9). Decided at runtime: a `memory://` or `apcu://`
 * store cannot be flushed from a CLI worker (SDK03 D20), so its schedule is empty. Run it with `messenger:consume scheduler_watchtower15`.
 *
 * @internal
 */
final class FlushSchedule implements ScheduleProviderInterface
{
    public function __construct(private readonly PipeStatus $status)
    {
    }

    public function getSchedule(): Schedule
    {
        $schedule = new Schedule();
        $scope = $this->status->scope();
        if ($scope !== StoreScope::Process && $scope !== StoreScope::Sapi) {
            $schedule->add(RecurringMessage::every('1 minute', new FlushMetrics()));
        }

        return $schedule;
    }
}
