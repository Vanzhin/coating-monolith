<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Event;

/** Состояние строки дайджеста сроков: приближается срок или уже просрочено. Соответствует Soon/Overdue бакета. */
enum DueKind: string
{
    case Soon = 'soon';
    case Overdue = 'overdue';
}
