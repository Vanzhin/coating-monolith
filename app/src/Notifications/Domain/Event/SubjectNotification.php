<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Event;

/** Событие про сотрудника-субъекта (резолвер SubjectSupervisors: субъект + надзорные). */
interface SubjectNotification
{
    public function subjectProfileId(): string;
}
