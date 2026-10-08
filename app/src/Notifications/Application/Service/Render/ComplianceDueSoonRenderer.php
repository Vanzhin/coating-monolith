<?php

declare(strict_types=1);

namespace App\Notifications\Application\Service\Render;

use App\Notifications\Domain\Event\ComplianceDueSoonData;
use App\Notifications\Domain\Event\DueKind;
use App\Notifications\Domain\Event\NotifiableEvent;
use App\Notifications\Domain\Service\MessageRendererInterface;
use App\Notifications\Domain\Type\NotificationType;
use App\Shared\Infrastructure\Exception\AppException;

/** Текст дайджеста сроков по сотруднику. Событие обязано нести данные контракта ComplianceDueSoonData. */
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

        $lines = [];
        foreach ($event->items() as $item) {
            $lines[] = match ($item->kind) {
                DueKind::Soon => sprintf('— %s: подходит срок до %s', $item->label, $item->dueDate),
                DueKind::Overdue => sprintf('— %s: просрочено с %s', $item->label, $item->dueDate),
            };
        }

        return sprintf("У %s по срокам обязанностей:\n%s", $event->employeeFio(), implode("\n", $lines));
    }
}
