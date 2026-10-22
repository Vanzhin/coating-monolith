<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Event;

/** Состояние строки дайджеста сроков. Соответствует бакетам Soon/Overdue/Missing единого резолвера. */
enum DueKind: string
{
    case Soon = 'soon';
    case Overdue = 'overdue';
    case Missing = 'missing';
}
