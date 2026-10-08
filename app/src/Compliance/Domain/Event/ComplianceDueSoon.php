<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Event;

use App\Notifications\Domain\Event\ComplianceDueItem;
use App\Notifications\Domain\Event\ComplianceDueSoonData;
use App\Notifications\Domain\Event\NotifiableEvent;
use App\Notifications\Domain\Event\SubjectNotification;
use App\Notifications\Domain\Type\NotificationType;

/**
 * Сроки обязанностей сотрудника-субъекта — дайджест НА ЧЕЛОВЕКА (список позиций «подходит срок»/«просрочено»).
 * Настраиваемый тип (SubjectSupervisors): адресаты — сам сотрудник, начальник его отдела и администраторы,
 * по их подпискам. Доставку делает NotificationDispatcher. Проход уведомлений публикует это событие.
 */
final readonly class ComplianceDueSoon implements NotifiableEvent, SubjectNotification, ComplianceDueSoonData
{
    /** @var list<ComplianceDueItem> */
    private array $items;

    public function __construct(
        private string $profileId,
        private string $employeeFio,
        ComplianceDueItem ...$items,
    ) {
        $this->items = array_values($items);
    }

    public function notificationType(): NotificationType
    {
        return NotificationType::ComplianceDueSoon;
    }

    public function subjectProfileId(): string
    {
        return $this->profileId;
    }

    public function employeeFio(): string
    {
        return $this->employeeFio;
    }

    /** @return list<ComplianceDueItem> */
    public function items(): array
    {
        return $this->items;
    }
}
