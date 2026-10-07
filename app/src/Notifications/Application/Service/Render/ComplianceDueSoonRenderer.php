<?php

declare(strict_types=1);

namespace App\Notifications\Application\Service\Render;

use App\Notifications\Domain\Event\ComplianceDueSoonData;
use App\Notifications\Domain\Event\NotifiableEvent;
use App\Notifications\Domain\Service\MessageRendererInterface;
use App\Notifications\Domain\Type\NotificationType;
use App\Shared\Infrastructure\Exception\AppException;

/** Текст «подходит срок выдачи СИЗ». Событие обязано нести данные контракта ComplianceDueSoonData. */
final readonly class ComplianceDueSoonRenderer implements MessageRendererInterface
{
    public function type(): NotificationType
    {
        return NotificationType::ComplianceDueSoon;
    }

    public function render(NotifiableEvent $event): string
    {
        if (!$event instanceof ComplianceDueSoonData) {
            throw new AppException('Событие compliance.due_soon не реализует ComplianceDueSoonData.');
        }

        return sprintf('У %s подходит срок выдачи «%s» — до %s', $event->employeeFio(), $event->obligationLabel(), $event->dueDate());
    }
}
