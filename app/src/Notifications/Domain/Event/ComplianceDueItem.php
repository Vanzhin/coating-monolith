<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Event;

/** Одна строка дайджеста сроков: позиция + её состояние (срок/просрочка) + как показывать дату. */
final readonly class ComplianceDueItem
{
    public function __construct(
        public string $label,
        public DueKind $kind,
        public string $dueDate,
    ) {
    }
}
