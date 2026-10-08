<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Scheduler;

use App\Compliance\Application\Scheduler\ScanDueSoon;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Расписание учёта: ежедневный проход сроков в 06:00 по Москве (прод-сервер в UTC — иначе было бы 06:00 UTC =
 * 09:00 MSK). stateful + processOnlyLastMissedRun — если воркер лежал у окна, при старте догоняется ТОЛЬКО
 * последний пропущенный запуск (один скан, не N подряд). Потребляется `messenger:consume scheduler_compliance`
 * (supervisor).
 */
#[AsSchedule('compliance')]
final class ComplianceSchedule implements ScheduleProviderInterface
{
    public function __construct(private readonly CacheInterface $cache)
    {
    }

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->add(RecurringMessage::cron('0 6 * * *', new ScanDueSoon(), new \DateTimeZone('Europe/Moscow')))
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true);
    }
}
