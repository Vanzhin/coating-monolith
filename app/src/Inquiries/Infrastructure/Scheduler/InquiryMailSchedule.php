<?php

declare(strict_types=1);

namespace App\Inquiries\Infrastructure\Scheduler;

use App\Inquiries\Application\Scheduler\PollInquiryMailbox;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Расписание приёма с почты: опрос ящика подбора каждые 5 минут. stateful + processOnlyLastMissedRun —
 * если воркер лежал, догоняется ТОЛЬКО последний пропуск (один опрос, не N подряд). Потребляется
 * `messenger:consume scheduler_inquiry_mail` (supervisor, прод). Ящик не настроен → проход no-op.
 */
#[AsSchedule('inquiry_mail')]
final class InquiryMailSchedule implements ScheduleProviderInterface
{
    public function __construct(private readonly CacheInterface $cache)
    {
    }

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->add(RecurringMessage::every('5 minutes', new PollInquiryMailbox()))
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true);
    }
}
