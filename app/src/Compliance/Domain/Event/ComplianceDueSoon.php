<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Event;

use App\Notifications\Domain\Event\ComplianceDueSoonData;
use App\Notifications\Domain\Event\NotifiableEvent;
use App\Notifications\Domain\Event\SubjectNotification;
use App\Notifications\Domain\Type\NotificationType;

/**
 * Подходит срок выдачи СИЗ у сотрудника-субъекта. Настраиваемый тип (SubjectSupervisors): адресаты —
 * сам сотрудник, начальник его отдела и администраторы, по их подпискам. Доставку делает NotificationDispatcher.
 */
final readonly class ComplianceDueSoon implements NotifiableEvent, SubjectNotification, ComplianceDueSoonData
{
    public function __construct(
        private string $profileId,
        private string $employeeFio,
        private string $obligationLabel,
        private string $dueDate,
    ) {
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

    public function obligationLabel(): string
    {
        return $this->obligationLabel;
    }

    public function dueDate(): string
    {
        return $this->dueDate;
    }
}
